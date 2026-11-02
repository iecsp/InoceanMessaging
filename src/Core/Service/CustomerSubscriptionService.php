<?php declare(strict_types=1);

namespace Inocean\Messaging\Core\Service;

use Doctrine\DBAL\Connection;

final class CustomerSubscriptionService
{
    public function __construct(private readonly Connection $db, private readonly CustomerProfileService $customers, private readonly SmsConsentService $consent, private readonly SmsSuppressionService $suppression, private readonly Privacy $privacy) {}

    public function assertConfigured(): void { $this->privacy->hash('configuration-check'); $this->privacy->seal('configuration-check'); }

    public function changed(string $scope, string $id, bool $subscribed, string $actor): void
    {
        $member = $this->customers->recipient($scope, $id);
        $this->db->transactional(function () use ($scope, $member, $subscribed, $actor): void {
            $this->consent->record($scope, $member['phone'], $subscribed ? 'marketing_express' : 'marketing_opt_out', $actor, $subscribed ? 'Authorized administrator confirmed SMS marketing consent by selecting the customer SMS subscription field.' : 'Customer SMS marketing subscription field was cleared.');
            $this->suppression->set($scope, $member['phone'], 'marketing', '', !$subscribed, $actor);
        });
    }

    public function unsubscribe(string $scope, string $phone, string $evidence): void
    {
        $this->db->transactional(function () use ($scope, $phone, $evidence): void {
            $alreadyBlocked=(bool)$this->db->fetchOne("SELECT active FROM ino_sms_suppression WHERE scope_id=UNHEX(?) AND phone_hash=? AND purpose='marketing' AND provider=''",[$scope,$this->privacy->hash($phone)]);
            $this->suppression->set($scope, $phone, 'marketing', '', true, 'signed-unsubscribe-link');
            if (!$alreadyBlocked) { $this->consent->record($scope, $phone, 'marketing_opt_out', 'signed-unsubscribe-link', $evidence); }
            $this->customers->unsubscribePhone($scope, $phone);
        });
    }
}
