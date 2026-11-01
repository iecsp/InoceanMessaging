<?php declare(strict_types=1);
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\{MessageBusInterface,Envelope};
final class TestConfig extends SystemConfigService {
    public array $values=['telnyxEnabled'=>true,'telnyxFrom'=>'+16045550001','plivoEnabled'=>true,'plivoFrom'=>'+16045550002','autoFailover'=>true,'monthlySegments'=>1000,'perMinute'=>100,'senderIdentity'=>'Test Restaurant'];
    public function __construct() {}
    public function get(string $key,?string $salesChannelId=null) { return $this->values[substr($key,strlen('InoceanMessaging.config.'))]??null; }
}
final class TestBus implements MessageBusInterface { public array $messages=[]; public function dispatch(object $message,array $stamps=[]): Envelope { $this->messages[]=$message; return new Envelope($message,$stamps); } }
