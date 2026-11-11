<?php declare(strict_types=1);
// Includes the existing mock-provider regression suite, then tests member integration.
require __DIR__.'/run.php';
require __DIR__.'/kernel.php';

use Inocean\Messaging\Core\Service\{CustomerProfileService,CustomerSubscriptionService,UnsubscribeToken,MessagingService,SmsConsentService};
use Inocean\Messaging\Core\DTO\SmsRequest;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\{EntityWrittenContainerEvent,EntityWriteEvent};
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

final class TestMemberRepository extends EntityRepository
{
    public ?Context $lastContext=null;
    public function __construct(private readonly Doctrine\DBAL\Connection $db) {}
    public function update(array $data,Context $context): EntityWrittenContainerEvent {
        $this->lastContext=$context;
        foreach($data as $row) { $this->db->executeStatement('UPDATE customer SET custom_fields=? WHERE id=UNHEX(?)',[json_encode($row['customFields'],JSON_THROW_ON_ERROR),$row['id']]); }
        return new EntityWrittenContainerEvent($context,new NestedEventCollection(),[]);
    }
}
$db->executeStatement('CREATE TABLE IF NOT EXISTS customer (id BINARY(16) PRIMARY KEY,sales_channel_id BINARY(16),default_billing_address_id BINARY(16),first_name VARCHAR(100),last_name VARCHAR(100),customer_number VARCHAR(100),company VARCHAR(100),custom_fields JSON,language_id BINARY(16))');
$db->executeStatement('CREATE TABLE IF NOT EXISTS customer_address (id BINARY(16) PRIMARY KEY,customer_id BINARY(16),phone_number VARCHAR(40))');
$memberId=str_repeat('3',32);$addressId=str_repeat('4',32);$memberPhone='+16045550999';$scope=str_repeat('1',32);$other=str_repeat('2',32);
$db->executeStatement('INSERT INTO customer (id,sales_channel_id,default_billing_address_id,first_name,last_name,customer_number,company,custom_fields,language_id) VALUES (UNHEX(?),UNHEX(?),UNHEX(?),?,?,?,?,?,UNHEX(?)) ON DUPLICATE KEY UPDATE custom_fields=VALUES(custom_fields),first_name=VALUES(first_name)',[$memberId,$scope,$addressId,'Alex','Chen','MEM-100','Inocean','{"ino_sms_subscribed":0,"keep_me":"preserved"}',md5('en-CA')]);
$db->executeStatement('INSERT INTO customer_address (id,customer_id,phone_number) VALUES (UNHEX(?),UNHEX(?),?) ON DUPLICATE KEY UPDATE phone_number=VALUES(phone_number)',[$addressId,$memberId,$memberPhone]);
// kernel.php creates a new connection; all transactional collaborators must share it.
$consent=new SmsConsentService($db,$privacy);
$suppression=new Inocean\Messaging\Core\Service\SmsSuppressionService($db,$privacy,$consent);
$memberRepo=new TestMemberRepository($db);$profiles=new CustomerProfileService($db,$memberRepo);
$subscriptions=new CustomerSubscriptionService($db,$profiles,$consent,$suppression,$privacy);
$subscriber=new Inocean\Messaging\Subscriber\CustomerSubscriptionSubscriber($db,$subscriptions);
$definition=$registry->getByEntityName('customer');
$existence=new Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence('customer',['id'=>Uuid::fromHexToBytes($memberId)],true,false,false,[]);
function preferenceEvent(bool $active,Context $context): Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent {
 global $definition,$existence,$memberId;
 $command=new Shopware\Core\Framework\DataAbstractionLayer\Write\Command\JsonUpdateCommand($definition,'custom_fields',['ino_sms_subscribed'=>(int)$active],['id'=>Uuid::fromHexToBytes($memberId)],$existence,'/');
 return new Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent(Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext::createFromContext($context),[$command]);
}
$untrusted=new Shopware\Core\Framework\Api\Context\AdminApiSource(str_repeat('a',32));
rejects(fn()=>$subscriber->beforeWrite(preferenceEvent(true,new Context($untrusted))),'Customer opt-in requires manage permission');
rejects(fn()=>$subscriber->beforeWrite(preferenceEvent(true,Context::createDefaultContext())),'Untrusted system imports cannot grant marketing consent');
$untrusted->setPermissions(['inocean_messaging:manage']);
$event=preferenceEvent(true,new Context($untrusted));$subscriber->beforeWrite($event);
$db->executeStatement('UPDATE customer SET custom_fields=JSON_SET(custom_fields,\'$.ino_sms_subscribed\',1) WHERE id=UNHEX(?)',[$memberId]);$subscriber->afterWrite(new Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PostWriteValidationEvent($event->getWriteContext(),$event->getCommands()));
$member=$profiles->recipient($scope,$memberId);
check($member['subscribed']===true,'Native numeric custom-field storage is recognized');
check($member['variables']['customerFullName']==='Alex Chen','Member variables read from persisted customer');
check(!array_key_exists('email',$member['variables']),'Member whitelist excludes sensitive unrelated fields');
check($consent->allowed($scope,$privacy->hash($memberPhone),'marketing'),'Authorized checkbox opt-in creates express consent audit');
rejects(fn()=>$profiles->recipient($other,$memberId),'Member variables reject cross-channel customer');
$tokens=new UnsubscribeToken($privacy,$settings);$url=$tokens->link($scope,$memberPhone);$token=basename($url);
check(!str_contains($url,$memberPhone),'Unsubscribe URL encrypts recipient phone');
check($tokens->verify($token)['phone']===$memberPhone,'Authenticated unsubscribe token round trip');
rejects(fn()=>$tokens->verify(substr($token,0,15).'X'.substr($token,16)),'Tampered unsubscribe token denied');
$expired=rtrim(strtr($privacy->seal(json_encode(['v'=>1,'t'=>'u1','scope'=>$scope,'phone'=>$memberPhone,'exp'=>time()-1])), '+/', '-_'),'=');
rejects(fn()=>$tokens->verify($expired),'Expired unsubscribe token denied');
$oldBase=$config->values['unsubscribeBaseUrl'];$config->values['unsubscribeBaseUrl']='http://insecure.test';rejects(fn()=>$tokens->link($scope,$memberPhone),'Unsubscribe URL requires HTTPS');$config->values['unsubscribeBaseUrl']=$oldBase;
$memberMessaging=new MessagingService($db,$privacy,$suppression,$manager,$settings,$tokens,$bus,new Psr\Log\NullLogger(),$profiles);
$queued=$memberMessaging->enqueue(new SmsRequest($scope,$memberPhone,'Hi Alex, offer. '.UnsubscribeToken::PLACEHOLDER,'marketing','member-offer',null,$memberId));
$replayed=$memberMessaging->enqueue(new SmsRequest($scope,$memberPhone,'Hi Alex, offer. '.UnsubscribeToken::PLACEHOLDER,'marketing','member-offer',null,$memberId));
check($queued['id']===$replayed['id'],'Randomized unsubscribe links preserve enqueue idempotency');
$stored=$privacy->open($db->fetchOne('SELECT body_cipher FROM ino_sms_message WHERE id=UNHEX(?)',[$queued['id']]));
check(!str_contains($stored,UnsubscribeToken::PLACEHOLDER)&&str_contains($stored,'/inocean/messaging/unsubscribe/'),'Final SMS contains real recipient unsubscribe link');
// Exercise the same persisted customer and sales-channel data Shopware supplies to a Flow action.
$flowTemplateId = Uuid::randomHex();
$db->insert('ino_sms_template', [
    'id' => Uuid::fromHexToBytes($flowTemplateId),
    'scope_id' => Uuid::fromHexToBytes($scope),
    'code' => 'flow_password_changed',
    'purpose' => 'transactional',
    'allowed_variables' => '["customerFirstName"]',
    'created_at' => SmsConsentService::now(),
]);
$db->insert('ino_sms_template_translation', [
    'ino_sms_template_id' => Uuid::fromHexToBytes($flowTemplateId),
    'language_id' => md5('zh-CN', true),
    'body' => '{{ customerFirstName }}，您的密码已更改。',
    'created_at' => SmsConsentService::now(),
]);
$db->executeStatement('UPDATE customer SET language_id=UNHEX(?) WHERE id=UNHEX(?)', [md5('zh-CN'), $memberId]);
$flow = new Shopware\Core\Content\Flow\Dispatching\StorableFlow('customer.password.changed', Context::createDefaultContext(), [
    Shopware\Core\Framework\Event\CustomerAware::CUSTOMER_ID => $memberId,
    Shopware\Core\Framework\Event\MailAware::SALES_CHANNEL_ID => $scope,
]);
$flow->setConfig(['templateId' => $flowTemplateId]);
$action = new Inocean\Messaging\Core\Flow\SendMessageAction(
    $profiles,
    new Inocean\Messaging\Core\Service\SmsTemplateService($db, $renderer),
    $memberMessaging,
);
$before = count($bus->messages);
rejects(fn() => $action->handleFlow($flow), 'Flow requires independent transactional SMS authorization');
$consent->record($scope, $memberPhone, 'transactional_authorization', 'flow-test', 'Password change notification');
$action->handleFlow($flow);
$flowMessage = $db->fetchAssociative('SELECT status, body_cipher, phone_cipher, LOWER(HEX(customer_id)) customer_id FROM ino_sms_message WHERE template_code=?', ['flow_password_changed']);
check(
    count($bus->messages) === $before + 1 && $flowMessage['status'] === 'queued'
    && $flowMessage['customer_id'] === $memberId && $privacy->open($flowMessage['phone_cipher']) === $memberPhone
    && str_starts_with($privacy->open($flowMessage['body_cipher']), 'Alex，您的密码已更改。'),
    'Flow queues the localized SMS for the triggering customer',
);
$db->update('ino_sms_template', ['scope_id' => Uuid::fromHexToBytes($other)], ['id' => Uuid::fromHexToBytes($flowTemplateId)]);
rejects(fn() => $action->handleFlow($flow), 'Flow rejects a template from another sales channel');
$db->update('ino_sms_template', ['scope_id' => Uuid::fromHexToBytes($scope), 'active' => 0], ['id' => Uuid::fromHexToBytes($flowTemplateId)]);
rejects(fn() => $action->handleFlow($flow), 'Flow rejects an inactive template');
check(count($bus->messages) === $before + 1, 'Rejected Flow actions dispatch no SMS');
$db->delete('ino_sms_consent', ['source' => 'flow-test']);
$db->executeStatement('UPDATE customer SET language_id=UNHEX(?) WHERE id=UNHEX(?)', [md5('en-CA'), $memberId]);

$controller=new Inocean\Messaging\Api\UnsubscribeController($tokens,$subscriptions,Inocean\Messaging\Core\Service\MessagingTranslationFactory::create());
foreach ([
    ['/unsubscribe', null, 'en-GB', 'Unsubscribe'],
    ['/unsubscribe?lang=zh-CN', null, 'zh-CN', '取消短信订阅'],
    ['/unsubscribe?lang=zh-TW', null, 'zh-TW', '取消簡訊訂閱'],
    ['/unsubscribe', 'zh-HK, en;q=0.5', 'zh-TW', '取消簡訊訂閱'],
    ['/unsubscribe', 'zh-Hans-CN', 'zh-CN', '取消短信订阅'],
    ['/unsubscribe?lang=en-CA', 'zh-CN', 'en-GB', 'Unsubscribe'],
    ['/unsubscribe?lang=fr-CA', 'fr-CA', 'en-GB', 'Unsubscribe'],
] as [$uri, $accept, $expectedLocale, $expectedButton]) {
    $request = Request::create($uri, 'GET');
    if ($accept !== null) { $request->headers->set('Accept-Language', $accept); }
    $localized = $controller->unsubscribe($token, $request);
    check($localized->headers->get('Content-Language') === $expectedLocale && str_contains($localized->getContent(), '<html lang="'.$expectedLocale.'">'), 'Public unsubscribe locale '.$uri.' '.$accept);
    check(str_contains($localized->getContent(), $expectedButton), 'Localized confirmation button '.$expectedLocale);
    $invalid = $controller->unsubscribe('invalid', $request);
    check($invalid->getStatusCode() === 400 && $invalid->headers->get('Content-Language') === $expectedLocale, 'Localized invalid link '.$expectedLocale);
}
$count=(int)$db->fetchOne('SELECT COUNT(*) FROM ino_sms_consent');
$response=$controller->unsubscribe($token,Request::create('/unsubscribe','GET'));
check($response->getStatusCode()===200 && str_contains($response->getContent(),'method="post"'),'GET link renders confirmation form');
check((int)$db->fetchOne('SELECT COUNT(*) FROM ino_sms_consent')===$count,'GET link does not mutate consent');
check($response->headers->get('Referrer-Policy')==='no-referrer','Unsubscribe page prevents referrer token disclosure');
$response=$controller->unsubscribe($token,Request::create('/unsubscribe','POST',['confirm'=>'yes']));
check($response->getStatusCode()===200 && str_contains($response->getContent(),'Unsubscribed'),'POST confirms unsubscribe');
foreach (['en-GB' => 'Unsubscribed', 'zh-CN' => '已取消订阅', 'zh-TW' => '已取消訂閱'] as $locale => $title) {
    $done = $controller->unsubscribe($token, Request::create('/unsubscribe?lang='.$locale, 'POST', ['confirm' => 'yes']));
    check($done->getStatusCode() === 200 && str_contains($done->getContent(), '<h1>'.$title.'</h1>') && $done->headers->get('Content-Language') === $locale, 'Localized unsubscribe success '.$locale);
}

check($profiles->recipient($scope,$memberId)['subscribed']===false,'Link unsubscribe clears Customer custom field');
check($profiles->find($scope,$memberId)['customFields']['keep_me']==='preserved','Customer DAL update preserves unrelated custom fields');
check($memberRepo->lastContext->hasState(CustomerProfileService::SYNC_STATE),'Projection prevents recursive consent grants');
rejects(fn()=>$suppression->assertAllowed($scope,$privacy->hash($memberPhone),'marketing','plivo'),'Link opt-out blocks marketing across providers');
$memberMessaging->deliver($queued['id']);
check($read->status($scope,$queued['id'])['status']==='suppressed','Queued member marketing is suppressed after link opt-out');
$count=(int)$db->fetchOne('SELECT COUNT(*) FROM ino_sms_consent');$controller->unsubscribe($token,Request::create('/unsubscribe','POST',['confirm'=>'yes']));
check((int)$db->fetchOne('SELECT COUNT(*) FROM ino_sms_consent')===$count,'Repeat unsubscribe does not duplicate consent records');
check($controller->unsubscribe('invalid',Request::create('/','GET'))->getStatusCode()===400,'Invalid link returns safe error page');
$consent->record($scope,$memberPhone,'transactional_authorization','test','requested transactional notification');
$suppression->assertAllowed($scope,$privacy->hash($memberPhone),'transactional','telnyx');check(true,'Marketing link opt-out preserves authorized transactional SMS');
$previewController=new Inocean\Messaging\Api\AdminController($memberMessaging,$read,$consent,$suppression,new Inocean\Messaging\Core\Service\SmsTemplateService($db,$renderer),$renderer,$db,$privacy,$profiles);
$request=Request::create('/preview','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode(['scopeId'=>$scope,'customerId'=>$memberId,'body'=>'Hello {{ customerFullName }}','allowedVariables'=>['customerFullName'],'variables'=>['customerFullName'=>'Forged']]));
$untrusted->setPermissions(['customer:read']);
$preview=json_decode($previewController->preview($request,new Context($untrusted))->getContent(),true);
check($preview['body']==='Hello Alex Chen','Server member values override spoofed front-end variables');
$untrusted->setPermissions([]);rejects(fn()=>$previewController->preview($request,new Context($untrusted)),'Member preview enforces customer read ACL');
if (!$db->createSchemaManager()->introspectTable('language')->hasColumn('parent_id')) { $db->executeStatement('ALTER TABLE language ADD COLUMN parent_id BINARY(16) NULL'); }
if (!$db->createSchemaManager()->introspectTable('language')->hasColumn('translation_code_id')) { $db->executeStatement('ALTER TABLE language ADD COLUMN translation_code_id BINARY(16) NULL'); }
$db->executeStatement('UPDATE language SET translation_code_id=locale_id');
$defaultLanguage=Shopware\Core\Defaults::LANGUAGE_SYSTEM;
$db->executeStatement('INSERT IGNORE INTO language (id,locale_id) VALUES (UNHEX(?),UNHEX(?))',[$defaultLanguage,md5('en-CA')]);
$db->executeStatement('UPDATE language SET translation_code_id=locale_id');
$db->getNativeConnection()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);
$native=$registry->getRepository('ino_sms_template');
// Use Shopware's real writer/reader/searcher but isolate unrelated platform app-script subscribers.
$reflect=new ReflectionClass($native);$args=[];
foreach($reflect->getConstructor()->getParameters() as $parameter) { $args[]=$parameter->getName()==='eventDispatcher' ? new Symfony\Component\EventDispatcher\EventDispatcher() : $reflect->getProperty($parameter->getName())->getValue($native); }
$dal=new EntityRepository(...$args);$templateId=Uuid::randomHex();$ctx=Context::createDefaultContext();
$dal->create([['id'=>$templateId,'scopeId'=>$scope,'code'=>'member_native_crud','purpose'=>'marketing','active'=>true,'allowedVariables'=>['customerFirstName'],'translations'=>[$defaultLanguage=>['body'=>'Hello {{ customerFirstName }}']]]],$ctx);
$entity=$dal->search(new Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria([$templateId]),$ctx)->getEntities()->first();
check($entity!==null&&$entity->get('body')==='Hello {{ customerFirstName }}','Native DAL template create/read with translations');
$dal->update([['id'=>$templateId,'translations'=>[$defaultLanguage=>['body'=>'Welcome {{ customerFirstName }}']]]],$ctx);
$entity=$dal->search(new Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria([$templateId]),$ctx)->getEntities()->first();
check($entity->get('body')==='Welcome {{ customerFirstName }}','Native DAL template update translation');
$db->executeStatement('CREATE TABLE IF NOT EXISTS consent_state (name VARCHAR(255),identifier VARCHAR(255),state VARCHAR(40),updated_at DATETIME(3),created_at DATETIME(3))');
foreach(['actor','revision'] as $column) { if (!$db->createSchemaManager()->introspectTable('consent_state')->hasColumn($column)) { $db->executeStatement('ALTER TABLE consent_state ADD COLUMN '.$column.' VARCHAR(255) NULL'); } }
$dal->delete([['id'=>$templateId]],$ctx);check(!$db->fetchOne('SELECT 1 FROM ino_sms_template WHERE id=UNHEX(?)',[$templateId]),'Native DAL template delete');
echo "$passed combined regression and membership checks passed\n";
