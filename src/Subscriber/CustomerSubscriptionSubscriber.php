<?php declare(strict_types=1);

namespace Inocean\Messaging\Subscriber;

use Doctrine\DBAL\Connection;
use Inocean\Messaging\Core\Service\{CustomerProfileService, CustomerSubscriptionService};
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CustomerSubscriptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Connection $db, private readonly CustomerSubscriptionService $subscriptions, private readonly LoggerInterface $logger) {}
    public static function getSubscribedEvents(): array { return [EntityWriteEvent::class => 'beforeWrite']; }

    public function beforeWrite(EntityWriteEvent $event): void
    {
        if ($event->getContext()->hasState(CustomerProfileService::SYNC_STATE)) { return; }
        foreach ($event->getCommandsForEntity('customer') as $command) {
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
            $old = $this->db->fetchAssociative('SELECT custom_fields, LOWER(HEX(sales_channel_id)) scope FROM customer WHERE id=UNHEX(?)', [$id]);
            $oldFields = json_decode($old['custom_fields'] ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
            $active = in_array($fields[CustomerProfileService::FIELD] ?? false, [true, 1], true);
            if ($active === in_array($oldFields[CustomerProfileService::FIELD] ?? false, [true, 1], true)) { continue; }
            $source = $event->getContext()->getSource();
            if ($active && (!$source instanceof AdminApiSource || !$source->isAllowed('inocean_messaging:manage'))) { throw new AccessDeniedHttpException('SMS marketing opt-in requires an authorized messaging administrator and consent evidence'); }
            if ($active) { try { $this->subscriptions->assertConfigured(); } catch (\RuntimeException) { throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Configure messaging encryption and HMAC keys before enabling SMS marketing consent'); } }
            $scope = isset($payload['sales_channel_id']) ? Uuid::fromBytesToHex($payload['sales_channel_id']) : ($old['scope'] ?? null);
            if ($scope === null) { continue; }
            $actor = $source instanceof AdminApiSource ? 'customer-admin:'.($source->getUserId() ?? $source->getIntegrationId()) : 'customer-preference';
            $event->addSuccess(function () use ($scope, $id, $active, $actor): void {
                try { $this->subscriptions->changed($scope, $id, $active, $actor); }
                catch (\InvalidArgumentException) { $this->logger->warning('Customer SMS preference saved without authorization: billing phone is not E.164', ['customerId' => $id]); }
            });
        }
    }
}
