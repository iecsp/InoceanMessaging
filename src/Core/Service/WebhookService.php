<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Framework\Uuid\Uuid;
final class WebhookService
{
    public function __construct(private readonly Connection $db,private readonly SmsSuppressionService $suppression) {}
    public function handle(string $provider,array $event): void {
        // Event input has already passed provider signature validation.
        $key=$provider==='telnyx' ? ($event['data']['id']??'') : (($event['MessageUUID']??throw new \InvalidArgumentException('Webhook message UUID missing')).':'.($event['Status']??$event['MessageState']??'inbound'));
        if (!is_string($key)||$key==='') { throw new \InvalidArgumentException('Webhook event ID missing'); }
        try { $this->db->transactional(function () use($provider,$event,$key) {
            $id=Uuid::randomBytes();
            $this->db->insert('ino_sms_webhook_event',['id'=>$id,'provider'=>$provider,'event_key'=>hash('sha256',$key),'kind'=>'received','created_at'=>SmsConsentService::now()]);
            $data=$provider==='telnyx' ? ($event['data']['payload']??[]) : $event;
            $inbound=$provider==='telnyx' ? ($event['data']['event_type']??'')==='message.received' : isset($data['Text']);
            if ($inbound) {
                $phone=$provider==='telnyx' ? ($data['from']['phone_number']??'') : ($data['From']??'');
                $to=$provider==='telnyx' ? ($data['to'][0]['phone_number']??'') : ($data['To']??'');
                if ($provider==='plivo') { $phone='+'.ltrim($phone,'+'); $to='+'.ltrim($to,'+'); }
                $map=json_decode((string)(\Inocean\Messaging\Core\Service\Environment::get('INO_SMS_SENDER_SCOPES')?:'{}'),true,512,JSON_THROW_ON_ERROR);
                $scope=$map[$provider.':'.$to]??null;
                if (!is_string($scope)||!Uuid::isValid($scope)) { throw new \RuntimeException('Inbound sender scope is not configured'); }
                $occurredAt=null;
                if($provider==='telnyx') { if(empty($event['data']['occurred_at'])) { throw new \InvalidArgumentException('Inbound occurrence timestamp required'); } $occurredAt=(new \DateTimeImmutable($event['data']['occurred_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'); }
                $kind=$this->suppression->inbound($scope,$phone,(string)($data['text']??$data['Text']??''),$provider,$key,$occurredAt);
                $this->db->update('ino_sms_webhook_event',['kind'=>$kind],['id'=>$id]); return;
            }
            $providerId=$provider==='telnyx' ? ($data['id']??'') : ($data['MessageUUID']??'');
            $message=$this->db->fetchAssociative('SELECT * FROM ino_sms_message WHERE provider=? AND provider_id=? FOR UPDATE',[$provider,$providerId]);
            if (!$message) { throw new \RuntimeException('Message not yet correlated; retry webhook'); }
            $time=$provider==='telnyx' ? new \DateTimeImmutable($event['data']['occurred_at']??'now') : new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
            $at=$time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
            $errorCode=(string)($data['errors'][0]['code']??$data['to'][0]['errors'][0]['code']??$data['ErrorCode']??'');
            if (($provider==='telnyx' && $errorCode==='40300') || ($provider==='plivo' && $errorCode==='200')) { $this->suppression->providerOptOut(bin2hex($message['scope_id']),$message['phone_hash'],$provider,'webhook:'.$key,$at); }
            $status=$provider==='telnyx' ? ($data['to'][0]['status']??'') : ($data['Status']??$data['MessageState']??'');
            $status=match($status) {'delivered'=>'delivered','sent'=>'sent','delivery_failed','sending_failed','failed','undelivered'=>'failed',default=>'accepted'};

            // Terminal states cannot be regressed by late sent/accepted callbacks.
            $ranks=['queued'=>0,'sending'=>0,'unknown'=>0,'accepted'=>1,'sent'=>2,'failed'=>3,'delivered'=>4];
            $update=[];
            if (($ranks[$status]??0)>($ranks[$message['status']]??0) && ($message['provider_event_at']===null||$at>=$message['provider_event_at'])) { $update=['status'=>$status,'provider_event_at'=>$at]; }
            $cost=$provider==='telnyx' ? ($data['cost']['amount']??null) : ($data['TotalAmount']??null);
            $currency=$provider==='telnyx' ? ($data['cost']['currency']??null) : null;
            if (($message['provider_event_at']===null || $at >= $message['provider_event_at']) && $cost!==null && preg_match('/^\d{1,10}(\.\d{1,8})?$/D',(string)$cost)) { $update['cost']=(string)$cost; if (is_string($currency)&&preg_match('/^[A-Z]{3}$/D',$currency)) { $update['currency']=$currency; } }
            $parts=$data['parts']??$data['Units']??null;
            if (($message['provider_event_at']===null || $at >= $message['provider_event_at']) && is_numeric($parts)&&(int)$parts>0&&(int)$parts<1000) { $update['segments']=(int)$parts; }
            if ($update) { $update['provider_event_at']=$at; $this->db->update('ino_sms_message',$update+['updated_at'=>SmsConsentService::now()],['id'=>$message['id']]); }
            $this->db->update('ino_sms_webhook_event',['kind'=>$status,'message_id'=>$message['id']],['id'=>$id]);
        }); } catch (UniqueConstraintViolationException) { /* Exactly-once processing through unique provider/event key. */ }
    }
}
