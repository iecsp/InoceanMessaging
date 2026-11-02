<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Inocean\Messaging\Core\DTO\SmsRequest;
use Inocean\Messaging\Core\Message\SendSmsMessage;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
final class MessagingService
{
    public function __construct(private readonly Connection $db, private readonly Privacy $privacy, private readonly SmsSuppressionService $suppression, private readonly ProviderManager $providers, private readonly Settings $settings, private readonly UnsubscribeToken $links, private readonly MessageBusInterface $bus, private readonly \Psr\Log\LoggerInterface $logger = new \Psr\Log\NullLogger(), private readonly ?CustomerProfileService $customers = null) {}
    public function enqueue(SmsRequest $request): array {
        if (!$this->db->fetchOne('SELECT 1 FROM sales_channel WHERE id=UNHEX(?)',[$request->scope])) { throw new \InvalidArgumentException('SMS scope must be an existing Sales Channel'); }
        if ($request->purpose === 'marketing') {
            $identity = trim((string)$this->settings->get('senderIdentity',$request->scope,''));
            if ($identity === '') { throw new \DomainException('marketing_sender_identity_required'); }
            $request = new SmsRequest($request->scope,$request->phone,$identity.': '.$request->body.' Reply STOP to unsubscribe.',$request->purpose,$request->idempotencyKey,$request->templateCode,$request->customerId);
        }
        $hash = $this->privacy->hash($request->phone);
        $identity = [$hash,$request->body,$request->purpose,$request->templateCode];
        if ($request->customerId !== null) { $identity[] = $request->customerId; }
        $fingerprint = hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
        $existing = $this->db->fetchAssociative('SELECT LOWER(HEX(id)) id,status,provider,fingerprint FROM ino_sms_message WHERE scope_id=UNHEX(?) AND idempotency_key=?',[$request->scope,$request->idempotencyKey]);
        if ($existing) { return $this->replay($existing,$fingerprint); }
        if ($request->customerId !== null) { if ($this->customers === null) { throw new \DomainException('Customer messaging integration unavailable'); } $this->customers->assertRecipient($request->scope,$request->customerId,$request->phone,$request->purpose); }
        $this->suppression->assertAllowed($request->scope,$hash,$request->purpose,$this->providers->primary($request->scope));
        $body = $request->body;
        if ($request->purpose === 'marketing' || $request->customerId !== null || $this->links->configured($request->scope) || str_contains($body, UnsubscribeToken::PLACEHOLDER)) {
            $url = $this->links->link($request->scope,$request->phone);
            $body = str_contains($body, UnsubscribeToken::PLACEHOLDER) ? str_replace(UnsubscribeToken::PLACEHOLDER,$url,$body) : $body." Unsubscribe: ".$url;
        }
        if (strlen($body)>6000) { throw new \DomainException('sms_body_with_unsubscribe_link_too_long'); }
        if (Segments::count($body)['segments'] > (int)$this->settings->get('maxSegments',$request->scope,6)) { throw new \DomainException('sms_segment_limit'); }
        $id = Uuid::randomHex(); $now = SmsConsentService::now();
        try {
            $this->db->insert('ino_sms_message',['id'=>Uuid::fromHexToBytes($id),'scope_id'=>Uuid::fromHexToBytes($request->scope),'phone_hash'=>$hash,'phone_cipher'=>$this->privacy->seal($request->phone),'body_cipher'=>$this->privacy->seal($body),'purpose'=>$request->purpose,'template_code'=>$request->templateCode,'customer_id'=>$request->customerId === null ? null : Uuid::fromHexToBytes($request->customerId),'idempotency_key'=>$request->idempotencyKey,'fingerprint'=>$fingerprint,'status'=>'queued','segments'=>Segments::count($body)['segments'],'next_at'=>$now,'created_at'=>$now]);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->db->fetchAssociative('SELECT LOWER(HEX(id)) id,status,provider,fingerprint FROM ino_sms_message WHERE scope_id=UNHEX(?) AND idempotency_key=?',[$request->scope,$request->idempotencyKey]);
            if (!$existing) { throw new \RuntimeException('Idempotency collision'); } return $this->replay($existing,$fingerprint);
        }
        // Persistent outbox survives a failed dispatch; recovery command dispatches it later.
        try { $this->bus->dispatch(new SendSmsMessage($id)); } catch (\Throwable) { $this->logger->error('SMS dispatch failed; persistent outbox retained', ['messageId'=>$id]); }
        return ['id'=>$id,'status'=>'queued','provider'=>null];
    }
    private function replay(array $row,string $fingerprint): array { if (!hash_equals($row['fingerprint'],$fingerprint)) { throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Idempotency key payload mismatch'); } unset($row['fingerprint']); return $row; }
    public function deliver(string $id): void {
        // Claim and quota reservation are serialized on the existing sales_channel row.
        $scope = $this->db->fetchOne('SELECT scope_id FROM ino_sms_message WHERE id=UNHEX(?)',[$id]);
        if (!$scope) { return; }
        $row = $this->db->transactional(function () use ($id,$scope) {
            $this->db->fetchOne('SELECT id FROM sales_channel WHERE id=? FOR UPDATE',[$scope]);
            $row = $this->db->fetchAssociative('SELECT *,LOWER(HEX(scope_id)) scope FROM ino_sms_message WHERE id=UNHEX(?) FOR UPDATE',[$id]);
            if (!$row || $row['status'] !== 'queued' || $row['next_at'] > SmsConsentService::now()) { return null; }
            $provider = $row['provider'] ?: $this->providers->primary($row['scope']);
            try {
                if ($row['customer_id'] !== null) { if ($this->customers === null) { throw new \DomainException('customer_integration_unavailable'); } $this->customers->assertRecipient($row['scope'],bin2hex($row['customer_id']),$this->privacy->open($row['phone_cipher']),$row['purpose']); }
                $this->suppression->assertAllowed($row['scope'],$row['phone_hash'],$row['purpose'],$provider);
            }
            catch (\DomainException) { $this->update($id,['status'=>'suppressed','error_code'=>'sms_consent_or_suppression']); return null; }
            $limit = (int)$this->settings->get('monthlySegments',$row['scope'],1000);
            $used = (int)$this->db->fetchOne("SELECT COALESCE(SUM(segments),0) FROM ino_sms_message WHERE scope_id=? AND quota_month=? AND id<>UNHEX(?) AND status IN ('sending','accepted','sent','delivered','failed','unknown')",[$scope,gmdate('Y-m'),$id]);
            $minute = (int)$this->db->fetchOne("SELECT COUNT(*) FROM ino_sms_attempt a JOIN ino_sms_message m ON m.id=a.message_id WHERE m.scope_id=? AND a.created_at>=?",[$scope,gmdate('Y-m-d H:i:s',time()-60)]);
            if ($used + (int)$row['segments'] > $limit) { $this->update($id,['status'=>'rejected','error_code'=>'monthly_quota']); return null; }
            if ($minute >= (int)$this->settings->get('perMinute',$row['scope'],30)) { $this->update($id,['next_at'=>gmdate('Y-m-d H:i:s',time()+60)]); return null; }
            $attempt = Uuid::randomBytes();
            $this->db->insert('ino_sms_attempt',['id'=>$attempt,'message_id'=>Uuid::fromHexToBytes($id),'provider'=>$provider,'status'=>'sending','created_at'=>SmsConsentService::now()]);
            $this->update($id,['status'=>'sending','provider'=>$provider,'quota_month'=>gmdate('Y-m'),'attempt_count'=>(int)$row['attempt_count']+1]);
            return $row+['selected'=>$provider,'attempt'=>$attempt];
        });
        if (!$row) { return; }
        try {
            // Recheck immediately before external side effect (including retry/failover).
            $this->suppression->assertAllowed($row['scope'],$row['phone_hash'],$row['purpose'],$row['selected']);
            if ($row['customer_id'] !== null) { $this->customers?->assertRecipient($row['scope'],bin2hex($row['customer_id']),$this->privacy->open($row['phone_cipher']),$row['purpose']); }
            $result = $this->providers->get($row['selected'])->send(new SmsRequest($row['scope'],$this->privacy->open($row['phone_cipher']),$this->privacy->open($row['body_cipher']),$row['purpose'],$row['idempotency_key']));
        } catch (\DomainException) { $result = new \Inocean\Messaging\Core\DTO\SmsResult('suppressed',error:'sms_consent_or_suppression'); }
        catch (\Throwable) { $this->logger->warning('SMS send outcome unknown', ['messageId'=>$id]); $result = new \Inocean\Messaging\Core\DTO\SmsResult('unknown',error:'worker_unknown'); }
        $this->db->transactional(function () use ($id,$row,$result) {
            $this->db->update('ino_sms_attempt',['status'=>$result->status,'provider_id'=>$result->providerId,'error_code'=>$result->error,'updated_at'=>SmsConsentService::now()],['id'=>$row['attempt']]);
            if ($result->status === 'provider_suppressed') { $this->suppression->providerOptOut($row['scope'],$row['phone_hash'],$row['selected'],'provider-rejection:'.bin2hex($row['attempt'])); }
            $data = ['status'=>$result->status,'provider_id'=>$result->providerId,'error_code'=>$result->error];
            $count = (int)$row['attempt_count']+1;
            if ($result->mayRetry() && $count < min(10,(int)$this->settings->get('maxAttempts',$row['scope'],3))) { $data['status']='queued'; $data['next_at']=gmdate('Y-m-d H:i:s',time()+min(3600,30*(2**$count))); }
            if ($result->mayFailover() && $row['provider'] === null && ($backup=$this->providers->fallback($row['scope'],$row['selected']))) { $data['status']='queued'; $data['provider']=$backup; }
            $this->update($id,$data);
        });
    }
    private function update(string $id,array $data): void { $this->db->update('ino_sms_message',$data+['updated_at'=>SmsConsentService::now()],['id'=>Uuid::fromHexToBytes($id)]); }
}
