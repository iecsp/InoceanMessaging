<?php declare(strict_types=1);

namespace Inocean\Messaging\Core\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CustomerProfileService
{
    public const FIELD = 'ino_sms_subscribed';
    public const SYNC_STATE = 'ino_sms_preference_sync';
    public const VARIABLES = ['customerFirstName', 'customerLastName', 'customerFullName', 'customerNumber', 'customerCompany', 'customerLanguage', 'unsubscribeUrl'];

    public function __construct(private readonly Connection $db, private readonly EntityRepository $customerRepository) {}

    public function find(string $scope, string $id): array
    {
        if (!Uuid::isValid($scope) || !Uuid::isValid($id)) { throw new NotFoundHttpException('Customer unavailable'); }
        $row = $this->db->fetchAssociative('SELECT LOWER(HEX(c.id)) id, LOWER(HEX(c.sales_channel_id)) scope, c.first_name, c.last_name, c.customer_number, c.company, c.custom_fields, a.phone_number, o.code locale FROM customer c LEFT JOIN customer_address a ON a.id=c.default_billing_address_id AND a.customer_id=c.id LEFT JOIN language l ON l.id=c.language_id LEFT JOIN locale o ON o.id=l.locale_id WHERE c.id=UNHEX(?) AND c.sales_channel_id=UNHEX(?)', [$id, $scope]);
        if (!$row) { throw new NotFoundHttpException('Customer unavailable in sales channel'); }
        $row['customFields'] = json_decode($row['custom_fields'] ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
        $row['subscribed'] = in_array($row['customFields'][self::FIELD] ?? false, [true, 1], true);
        return $row;
    }

    public function recipient(string $scope, string $id, bool $requirePhone = true): array
    {
        $row = $this->find($scope, $id);
        $phone = trim((string) $row['phone_number']);
        if ($requirePhone && !preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) { throw new \InvalidArgumentException('Customer billing phone must be in E.164 format'); }
        return ['phone' => $phone, 'subscribed' => $row['subscribed'], 'variables' => [
            'customerFirstName' => (string) $row['first_name'],
            'customerLastName' => (string) $row['last_name'],
            'customerFullName' => trim($row['first_name'].' '.$row['last_name']),
            'customerNumber' => (string) $row['customer_number'],
            'customerCompany' => (string) ($row['company'] ?? ''),
            'customerLanguage' => (string) ($row['locale'] ?? 'en-CA'),
        ]];
    }

    public function assertRecipient(string $scope, string $id, string $phone, string $purpose): void
    {
        try { $member = $this->recipient($scope, $id); } catch (NotFoundHttpException | \InvalidArgumentException) { throw new \DomainException('customer_sms_recipient_unavailable'); }
        if ($member['phone'] !== $phone || ($purpose === 'marketing' && !$member['subscribed'])) { throw new \DomainException('customer_sms_preference_or_phone_changed'); }
    }

    public function unsubscribePhone(string $scope, string $phone): void
    {
        $rows = $this->db->fetchAllAssociative('SELECT LOWER(HEX(c.id)) id, c.custom_fields FROM customer c JOIN customer_address a ON a.id=c.default_billing_address_id AND a.customer_id=c.id WHERE c.sales_channel_id=UNHEX(?) AND TRIM(a.phone_number)=?', [$scope, $phone]);
        $updates = [];
        foreach ($rows as $row) {
            $fields = json_decode($row['custom_fields'] ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
            if (in_array($fields[self::FIELD] ?? null, [false, 0], true)) { continue; }
            $updates[] = ['id' => $row['id'], 'customFields' => array_replace($fields, [self::FIELD => false])];
        }
        if ($updates === []) { return; }
        $context = Context::createDefaultContext();
        $context->addState(self::SYNC_STATE);
        $this->customerRepository->update($updates, $context);
    }
}
