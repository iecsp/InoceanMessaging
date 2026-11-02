<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
final class SmsSuppressionService
{
    public const GLOBAL = '00000000000000000000000000000000';
    public function __construct(private readonly Connection $db, private readonly Privacy $privacy, private readonly SmsConsentService $consent, private readonly ?CustomerProfileService $customers = null) {}
    public function set(string $scope, string $phone, string $purpose, string $provider, bool $active, string $source, ?string $eventAt = null): void {
        if (!Uuid::isValid($scope) || !preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone) || !in_array($purpose,['all','marketing'],true) || !in_array($provider,['','telnyx','plivo'],true)) { throw new \InvalidArgumentException('Invalid suppression'); }
        $this->write($scope,$this->privacy->hash($phone),$purpose,$provider,$active,$source,$eventAt);
    }
    private function write(string $scope,string $hash,string $purpose,string $provider,bool $active,string $source,?string $eventAt=null): void {
        $at=$eventAt ?? SmsConsentService::now();
        $this->db->executeStatement('INSERT INTO ino_sms_suppression (id,scope_id,phone_hash,purpose,provider,active,source,created_at,event_at) VALUES (?,UNHEX(?),?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE active=IF(VALUES(event_at)>=event_at,VALUES(active),active),source=IF(VALUES(event_at)>=event_at,VALUES(source),source),updated_at=IF(VALUES(event_at)>=event_at,VALUES(created_at),updated_at),event_at=GREATEST(event_at,VALUES(event_at))', [Uuid::randomBytes(),$scope,$hash,$purpose,$provider,(int)$active,substr($source,0,128),SmsConsentService::now(),$at]);
    }
    public function providerOptOut(string $scope,string $hash,string $provider,string $event,?string $eventAt=null): void {
        $this->write($scope,$hash,'marketing','',true,$event,$eventAt);
        $this->write($scope,$hash,'all',$provider,true,$event,$eventAt);
        if ($this->customers !== null) {
            $cipher=$this->db->fetchOne('SELECT phone_cipher FROM ino_sms_message WHERE scope_id=UNHEX(?) AND phone_hash=? AND phone_cipher IS NOT NULL ORDER BY created_at DESC LIMIT 1',[$scope,$hash]);
            if (is_string($cipher)) { $this->customers->unsubscribePhone($scope,$this->privacy->open($cipher)); }
        }
    }
    public function assertAllowed(string $scope, string $hash, string $purpose, string $provider): void {
        $blocked = $this->db->fetchOne("SELECT 1 FROM ino_sms_suppression WHERE scope_id IN (UNHEX(?),UNHEX(?)) AND phone_hash=? AND active=1 AND purpose IN ('all',?) AND provider IN ('',?) LIMIT 1", [$scope,self::GLOBAL,$hash,$purpose,$provider]);
        if ($blocked || !$this->consent->allowed($scope,$hash,$purpose)) { throw new \DomainException('sms_consent_or_suppression'); }
    }
    public function inbound(string $scope, string $phone, string $text, string $provider, string $event, ?string $eventAt = null): string {
        $word = strtoupper(trim($text));
        if (in_array($word,['STOP','UNSUBSCRIBE','CANCEL','END','QUIT','UNSUB','STOP ALL'],true)) {
            $this->set($scope,$phone,'marketing','',true,'webhook:'.$event,$eventAt);
            $this->set($scope,$phone,'all',$provider,true,'webhook:'.$event,$eventAt);
            $this->consent->record($scope,$phone,'marketing_opt_out',$provider,$event);
            $this->customers?->unsubscribePhone($scope,$phone);
            return 'stop';
        }
        if (in_array($word,['START','UNSTOP'],true)) {
            // Plivo lacks a signed occurrence timestamp: audit START, require trusted reconciliation to clear.
            if ($provider==='telnyx' && $eventAt!==null) { $this->set($scope,$phone,'all',$provider,false,'webhook:'.$event,$eventAt); }
            $this->consent->record($scope,$phone,'start',$provider,$event);
            return 'start';
        }
        if ($word === 'HELP') { $this->consent->record($scope,$phone,'help',$provider,$event); return 'help'; }
        return 'inbound';
    }
}
