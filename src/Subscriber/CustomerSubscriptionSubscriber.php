<?php declare(strict_types=1);

namespace Inocean\Messaging\Subscriber;

use Doctrine\DBAL\Connection;
use Inocean\Messaging\Core\Service\{CustomerProfileService, CustomerSubscriptionService};
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\{PreWriteValidationEvent, PostWriteValidationEvent};
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CustomerSubscriptionSubscriber implements EventSubscriberInterface
{
    /** Snapshots belong to write commands, never to the lifetime of a worker/request. */
    private \WeakMap $changes;
    public function __construct(private readonly Connection $db, private readonly CustomerSubscriptionService $subscriptions) { $this->changes = new \WeakMap(); }
    public static function getSubscribedEvents(): array { return [PreWriteValidationEvent::class => 'beforeWrite', PostWriteValidationEvent::class => 'afterWrite']; }

    public function beforeWrite(PreWriteValidationEvent $event): void
    {
        if ($event->getContext()->hasState(CustomerProfileService::SYNC_STATE)) { return; }
        foreach ($event->getCommandsForEntity('customer') as $command) {
            unset($this->changes[$command]);
            $payload = $command->getPayload();
            if ($command instanceof JsonUpdateCommand) {
                if ($command->getStorageName() !== 'custom_fields' || !array_key_exists(CustomerProfileService::FIELD, $payload)) { continue; }
                $fields = $payload;
            } else {
                if (!array_key_exists('custom_fields', $payload)) { continue; }
                $fields = is_string($payload['custom_fields']) ? json_decode($payload['custom_fields'], true, 512, JSON_THROW_ON_ERROR) : ($payload['custom_fields'] ?? []);
            }
            if (!is_array($fields)) { continue; }
            $id = $command->getDecodedPrimaryKey()['id'];
            $old = $this->db->fetchAssociative('SELECT custom_fields, LOWER(HEX(sales_channel_id)) scope FROM customer WHERE id=UNHEX(?) FOR UPDATE', [$id]);
            $oldFields = json_decode($old['custom_fields'] ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
            if (!array_key_exists(CustomerProfileService::FIELD, $fields) && !in_array($oldFields[CustomerProfileService::FIELD] ?? false, [true, 1], true)) { continue; }
            $active = in_array($fields[CustomerProfileService::FIELD] ?? false, [true, 1], true);
            if ($active === in_array($oldFields[CustomerProfileService::FIELD] ?? false, [true, 1], true)) { continue; }
            $source = $event->getContext()->getSource();
            if ($active && (!$source instanceof AdminApiSource || !$source->isAllowed('inocean_messaging:manage'))) { throw new AccessDeniedHttpException('SMS marketing opt-in requires an authorized messaging administrator and consent evidence'); }
            if ($active) { try { $this->subscriptions->assertConfigured(); } catch (\RuntimeException) { throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Configure messaging encryption and HMAC keys before enabling SMS marketing consent'); } }
            $scope = isset($payload['sales_channel_id']) ? Uuid::fromBytesToHex($payload['sales_channel_id']) : ($old['scope'] ?? null);
            if ($scope === null) { continue; }
            $actor = $source instanceof AdminApiSource ? 'customer-admin:'.($source->getUserId() ?? $source->getIntegrationId()) : 'customer-preference';
            // Capture the old billing number before address updates in this write batch.
            $oldPhone = $old ? $this->db->fetchOne('SELECT a.phone_number FROM customer c JOIN customer_address a ON a.id=c.default_billing_address_id AND a.customer_id=c.id WHERE c.id=UNHEX(?)', [$id]) : null;
            $this->changes[$command] = [$scope, $id, $active, $actor, is_string($oldPhone) ? trim($oldPhone) : null, $old['scope'] ?? $scope];
        }
    }

    public function afterWrite(PostWriteValidationEvent $event): void
    {
        foreach ($event->getCommandsForEntity('customer') as $command) {
            if (!isset($this->changes[$command])) { continue; }
            $change = $this->changes[$command];
            unset($this->changes[$command]);
            // PostWriteValidation runs inside the DAL transaction. Any failure rolls back both writes.
            $this->subscriptions->changed(...$change);
        }
    }
}
