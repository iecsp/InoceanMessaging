<?php declare(strict_types=1);

namespace Inocean\Messaging\Core\Service;

use Doctrine\DBAL\Connection;

final class CustomerSubscriptionService
{
    public function __construct(private readonly Connection $db, private readonly CustomerProfileService $customers, private readonly SmsConsentService $consent, private readonly SmsSuppressionService $suppression, private readonly Privacy $privacy) {}

    public function assertConfigured(): void { $this->privacy->hash('configuration-check'); $this->privacy->seal('configuration-check'); }

    public function changed(string $scope, string $id, bool $subscribed, string $actor, ?string $oldPhone = null, ?string $oldScope = null): void
    {
        $member = $this->customers->recipient($scope, $id, $subscribed);
        $this->db->transactional(function () use ($scope, $id, $member, $subscribed, $actor, $oldPhone, $oldScope): void {
            if ($subscribed) {
                $this->consent->record($scope, $member['phone'], 'marketing_express', $actor, 'Authorized administrator confirmed SMS marketing consent by selecting the customer SMS subscription field.', null, $id);
                $this->suppression->set($scope, $member['phone'], 'marketing', '', false, $actor);
                return;
            }
            // Keep the consent identity even when the billing number changes or becomes invalid.
            $identities = $this->db->fetchAllAssociative('SELECT LOWER(HEX(scope_id)) scope, phone_hash hash FROM ino_sms_consent WHERE customer_id=UNHEX(?) UNION SELECT LOWER(HEX(scope_id)) scope, phone_hash hash FROM ino_sms_message WHERE customer_id=UNHEX(?)', [$id, $id]);
            foreach ([[$scope, $member['phone']], [$oldScope ?? $scope, $oldPhone]] as [$phoneScope, $phone]) {
                if (is_string($phone) && preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) { $identities[] = ['scope' => $phoneScope, 'hash' => $this->privacy->hash($phone)]; }
            }
            if ($identities === []) { throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Cannot resolve the SMS consent number; reconcile consent before saving this preference'); }
            $seen = [];
            foreach ($identities as $identity) {
                $key = $identity['scope'].$identity['hash'];
                if (isset($seen[$key])) { continue; }
                $seen[$key] = true;
                $this->consent->recordHash($identity['scope'], $identity['hash'], 'marketing_opt_out', $actor, 'Customer SMS marketing subscription field was cleared.', null, $id);
                $this->suppression->marketingOptOutHash($identity['scope'], $identity['hash'], $actor);
            }
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
