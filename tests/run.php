<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Inocean\Messaging\Core\Service\{Settings,Privacy,Segments,TemplateRenderer,ProviderManager,MessagingService,SmsConsentService,SmsSuppressionService,WebhookSignature,WebhookService,ReadService,SmsTemplateService};
use Inocean\Messaging\Core\Provider\{TelnyxProvider,PlivoProvider};
use Inocean\Messaging\Core\DTO\SmsRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\{MessageBusInterface,Envelope};
use Doctrine\DBAL\DriverManager;
use Shopware\Core\Framework\Uuid\Uuid;
require __DIR__.'/support.php';
$passed=0;
function check(bool $value,string $name): void { global $passed; if(!$value) { throw new RuntimeException('FAIL: '.$name); } ++$passed; echo 'PASS '.$name."\n"; }
function rejects(callable $fn,string $name): void { try { $fn(); } catch(Throwable) { check(true,$name); return; } check(false,$name); }
putenv('INO_SMS_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
putenv('INO_SMS_HASH_KEY='.bin2hex(random_bytes(32)));
putenv('INO_SMS_TELNYX_API_KEY=test-only'); putenv('INO_SMS_PLIVO_AUTH_ID=test-only');putenv('INO_SMS_PLIVO_AUTH_TOKEN=test-only');putenv('INO_SMS_PLIVO_WEBHOOK_URL=https://example.test/inocean/messaging/webhook/plivo');
$config=new TestConfig(); $settings=new Settings($config); $privacy=new Privacy();
$scope=str_repeat('1',32); $other=str_repeat('2',32); $phone='+16045550123';
putenv('INO_SMS_SENDER_SCOPES='.json_encode(['telnyx:+16045550001'=>$scope,'plivo:+16045550002'=>$scope]));
$req=new SmsRequest($scope,$phone,'Hello','transactional','test');
check(Segments::count(str_repeat('a',160))['segments']===1,'GSM single segment');
check(Segments::count(str_repeat('a',161))['segments']===2,'GSM concatenated');
check(Segments::count(str_repeat('^',81))['segments']===2,'GSM extension counts twice');
check(Segments::count(str_repeat('中',70))['segments']===1,'Chinese single segment');
check(Segments::count(str_repeat('中',71))['segments']===2,'Chinese concatenated');
check(Segments::count(str_repeat('😀',36))['units']===72,'Emoji surrogate pair');
check($privacy->open($privacy->seal($phone))===$phone,'Encrypted payload round trip');
check($privacy->seal($phone)!==$privacy->seal($phone),'Random encryption nonce');
rejects(fn()=>new SmsRequest($scope,'6045550123','hi','marketing','x'),'Reject non E.164');
$renderer=new TemplateRenderer();
check($renderer->render('Hello {{ name }}',['name'=>'World','secret'=>'never'],['name'])==='Hello World','Twig scalar rendering');
rejects(fn()=>$renderer->render('{{ secret }}',['secret'=>'hidden'],[]),'Twig variable allowlist');
rejects(fn()=>$renderer->render('{{ include("file") }}',[],[]),'Twig include blocked');
rejects(fn()=>$renderer->render('{{ name.call() }}',['name'=>new stdClass()],['name']),'Twig object access blocked');
rejects(fn()=>$renderer->render('{% for i in 1..1000000 %}x{% endfor %}',[],[]),'Twig loops blocked');
check(Segments::count(str_repeat('😀',201))['segments']===7,'Unicode segments never split surrogate pairs');
check(Segments::count(str_repeat('^',153))['segments']===3,'GSM segments never split escape pairs');
rejects(fn()=>$renderer->render('{{ 1..1000000000 }}',[],[]),'Twig resource-heavy expressions blocked');
$captured=[];
$http=new MockHttpClient(function(string $method,string $url,array $options)use(&$captured) { $captured[]=[$url,$options];return new MockResponse('{"data":{"id":"telnyx-1"}}'); });
$telnyx=new TelnyxProvider($http,$settings); check($telnyx->send($req)->providerId==='telnyx-1','Telnyx accepted');
check(str_contains($captured[0][1]['body'],'Hello'),'Telnyx body encoded');
$plivo=new PlivoProvider(new MockHttpClient(new MockResponse('{"message_uuid":["plivo-1"]}')),$settings);
check($plivo->send($req)->providerId==='plivo-1','Plivo accepted');
foreach([429=>'rate_limited',500=>'unknown',408=>'unknown',400=>'rejected',403=>'rejected'] as $code=>$expected) {
 $r=(new TelnyxProvider(new MockHttpClient(new MockResponse('{}',['http_code'=>$code])),$settings))->send($req);
 check($r->status===$expected,'HTTP '.$code.' classification'); check(!$r->mayFailover(),'HTTP '.$code.' cannot cause unsafe failover');
}
$r=(new TelnyxProvider(new MockHttpClient(new MockResponse('{}')),$settings))->send($req);check($r->status==='unknown','Missing provider ID is unknown');
$r=(new TelnyxProvider(new MockHttpClient(new MockResponse('', ['error'=>'timeout'])),$settings))->send($req);check($r->status==='unknown','Transport timeout is unknown');
$config->values['telnyxEnabled']=false;check($telnyx->send($req)->mayFailover(),'Disabled primary may safely failover');$config->values['telnyxEnabled']=true;
$optOut=(new TelnyxProvider(new MockHttpClient(new MockResponse('{"errors":[{"code":"40300"}]}',['http_code'=>403])),$settings))->send($req);
check($optOut->status==='provider_suppressed' && !$optOut->mayFailover(),'Telnyx provider opt-out rejection normalized');
$sig=new WebhookSignature();$keys=sodium_crypto_sign_keypair();putenv('INO_SMS_TELNYX_PUBLIC_KEY='.base64_encode(sodium_crypto_sign_publickey($keys)));$body='{"data":{}}';$time=(string)time();
$r=Request::create('/','POST',[],[],[],[], $body);$r->headers->set('telnyx-timestamp',$time);$r->headers->set('telnyx-signature-ed25519',base64_encode(sodium_crypto_sign_detached($time.'|'.$body,sodium_crypto_sign_secretkey($keys))));$sig->telnyx($r);check(true,'Telnyx valid signature');
$r->headers->set('telnyx-timestamp',(string)(time()-400));rejects(fn()=>$sig->telnyx($r),'Telnyx stale signature rejected');
$r=Request::create('/','POST',['From'=>$phone,'Text'=>'STOP']);$r->headers->set('content-type','application/x-www-form-urlencoded');$r->headers->set('x-plivo-signature-v3-nonce','nonce');
$r->headers->set('x-plivo-signature-v3','5qKYrIm0hTuw1YBM7Mmz+6MBn7D1VrYKAzgrU3rDGRc=');$sig->plivo($r);check(true,'Plivo V3 SDK canonicalization fixture');
$r->request->set('Text','START');rejects(fn()=>$sig->plivo($r),'Plivo modified body rejected');
// Route ACL presence, and protected DAL records (not a replacement for full OAuth integration tests).
foreach((new ReflectionClass(Inocean\Messaging\Api\AdminController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
 foreach($method->getAttributes(Symfony\Component\Routing\Attribute\Route::class) as $attr) { check(!empty($attr->newInstance()->defaults['_acl']),'Admin ACL '.$method->name); }
}
foreach(['Message','Attempt','Consent','Suppression','WebhookEvent'] as $name) { $class='Inocean\\Messaging\\Content\\'.$name.'Definition'; check(is_subclass_of($class,Inocean\Messaging\Content\OperationalDefinition::class),'DAL protected '.$name); }
if(!getenv('INO_SMS_TEST_DATABASE_URL')) { echo "Database tests skipped; set INO_SMS_TEST_DATABASE_URL to a disposable database.\n"; echo "$passed checks passed\n";exit; }
$db=DriverManager::getConnection((new \Doctrine\DBAL\Tools\DsnParser(['mysql'=>'pdo_mysql']))->parse(getenv('INO_SMS_TEST_DATABASE_URL')));
check($db->getDatabase()==='ino_messaging_test','Disposable database guard');
$db->executeStatement('CREATE TABLE IF NOT EXISTS sales_channel (id BINARY(16) PRIMARY KEY)');
$db->executeStatement('CREATE TABLE IF NOT EXISTS locale (id BINARY(16) PRIMARY KEY,code VARCHAR(10))');
$db->executeStatement('CREATE TABLE IF NOT EXISTS language (id BINARY(16) PRIMARY KEY,locale_id BINARY(16))');
foreach([$scope,$other] as $s) {$db->executeStatement('INSERT IGNORE INTO sales_channel (id) VALUES (UNHEX(?))',[$s]);}
foreach(['en-CA','zh-CN','zh-TW','fr-CA'] as $locale) { $id=md5($locale);$db->executeStatement('INSERT IGNORE INTO locale (id,code) VALUES (UNHEX(?),?)',[$id,$locale]);$db->executeStatement('INSERT IGNORE INTO language (id,locale_id) VALUES (UNHEX(?),UNHEX(?))',[$id,$id]); }
// Only this guarded disposable database is reset; never use a business database here.
foreach(['ino_sms_webhook_event','ino_sms_attempt','ino_sms_message','ino_sms_consent','ino_sms_suppression','ino_sms_template_translation','ino_sms_template'] as $table) { $db->executeStatement('DROP TABLE IF EXISTS '.$table); }
$migration=new Inocean\Messaging\Migration\Migration1793404800Messaging();$migration->update($db);$migration->update($db);check(true,'Migration rerunnable on MySQL');
foreach(['ino_sms_webhook_event','ino_sms_attempt','ino_sms_message','ino_sms_consent','ino_sms_suppression','ino_sms_template_translation','ino_sms_template'] as $table) { $db->executeStatement('DELETE FROM '.$table); }
$consent=new SmsConsentService($db,$privacy);$suppression=new SmsSuppressionService($db,$privacy,$consent);$bus=new TestBus();
$telnyx=new TelnyxProvider(new MockHttpClient(static fn()=>new MockResponse('{"data":{"id":"provider-'.uniqid().'"}}')),$settings);
$manager=new ProviderManager($telnyx,$plivo,$settings);$messaging=new MessagingService($db,$privacy,$suppression,$manager,$settings,$bus);
rejects(fn()=>$messaging->enqueue($req),'Unknown transactional authorization denied');
$consent->record($scope,$phone,'transactional_authorization','test','verified booking request');
$a=$messaging->enqueue($req);$b=$messaging->enqueue($req);check($a['id']===$b['id'],'Persistent enqueue idempotency');
rejects(fn()=>$messaging->enqueue(new SmsRequest($scope,$phone,'Changed','transactional','test')),'Idempotency payload conflict');
$messaging->deliver($a['id']);$messaging->deliver($a['id']);check((int)$db->fetchOne('SELECT COUNT(*) FROM ino_sms_attempt WHERE message_id=UNHEX(?)',[$a['id']])===1,'Duplicate workers send once');
$read=new ReadService($db,$settings);check($read->status($scope,$a['id'])['status']==='accepted','Async provider acceptance');
rejects(fn()=>$read->status($other,$a['id']),'Cross-scope status denied');
$marketing=new SmsRequest($scope,$phone,'Offer','marketing','marketing-1');rejects(fn()=>$messaging->enqueue($marketing),'Marketing without consent denied');
$consent->record($scope,$phone,'marketing_implied','test','not independently validated');rejects(fn()=>$messaging->enqueue($marketing),'Implied consent default denied');
$consent->record($scope,$phone,'marketing_express','test','explicit checkbox');$mark=$messaging->enqueue($marketing);check($mark['status']==='queued','Marketing express consent accepted');
check($suppression->inbound($scope,$phone,'HELP','telnyx','help-test')==='help','HELP audit');
check($suppression->inbound($scope,$phone,'STOP','telnyx','stop-test')==='stop','STOP registered');
rejects(fn()=>$suppression->assertAllowed($scope,$privacy->hash($phone),'transactional','telnyx'),'Provider STOP blocks transactional via same provider');
$suppression->assertAllowed($scope,$privacy->hash($phone),'transactional','plivo');check(true,'Marketing opt-out preserves independently authorized transactional via other provider');
rejects(fn()=>$suppression->assertAllowed($scope,$privacy->hash($phone),'marketing','plivo'),'Failover cannot evade marketing STOP');
$messaging->deliver($mark['id']);check($read->status($scope,$mark['id'])['status']==='suppressed','Consent rechecked after queueing');
check($suppression->inbound($scope,$phone,'START','telnyx','start-test',SmsConsentService::now())==='start','START provider restriction removed');
$suppression->assertAllowed($scope,$privacy->hash($phone),'transactional','telnyx');check(true,'START restores permitted transactional');
rejects(fn()=>$suppression->assertAllowed($scope,$privacy->hash($phone),'marketing','telnyx'),'START never grants marketing');
// Verified webhook deduplication, terminal and out-of-order state handling.
$wh=new WebhookService($db,$suppression);$pid=$db->fetchOne('SELECT provider_id FROM ino_sms_message WHERE id=UNHEX(?)',[$a['id']]);
$event=['data'=>['id'=>'event-one','occurred_at'=>'2026-10-31T20:00:00Z','event_type'=>'message.finalized','payload'=>['id'=>$pid,'to'=>[['status'=>'delivered']],'parts'=>2,'cost'=>['amount'=>'0.0123','currency'=>'USD']]]];
$wh->handle('telnyx',$event);$wh->handle('telnyx',$event);check((int)$db->fetchOne("SELECT COUNT(*) FROM ino_sms_webhook_event WHERE event_key=?",[hash('sha256','event-one')])===1,'Webhook deduplicated');
check($read->status($scope,$a['id'])['status']==='delivered','Delivered callback applied');
$event['data']['id']='event-old';$event['data']['occurred_at']='2026-10-30T20:00:00Z';$event['data']['payload']['to'][0]['status']='sent';$wh->handle('telnyx',$event);check($read->status($scope,$a['id'])['status']==='delivered','Old callback cannot regress delivered');
check((float)$read->status($scope,$a['id'])['cost']===0.0123,'Decimal provider cost recorded');check(count($read->usage($scope))>0,'Usage aggregation');
// Retry uses a new attempt only after a definite 429 and backoff.
$retryTelnyx=new TelnyxProvider(new MockHttpClient([new MockResponse('{}',['http_code'=>429]),new MockResponse('{"data":{"id":"retry-id"}}')]),$settings);
$retryService=new MessagingService($db,$privacy,$suppression,new ProviderManager($retryTelnyx,$plivo,$settings),$settings,$bus);
$retry=$retryService->enqueue(new SmsRequest($scope,$phone,'Retry','transactional','retry'));$retryService->deliver($retry['id']);check($read->status($scope,$retry['id'])['status']==='queued','429 schedules retry');
$retryService->deliver($retry['id']);check((int)$db->fetchOne('SELECT attempt_count FROM ino_sms_message WHERE id=UNHEX(?)',[$retry['id']])===1,'Backoff respected');
$db->executeStatement('UPDATE ino_sms_message SET next_at=UTC_TIMESTAMP(3) WHERE id=UNHEX(?)',[$retry['id']]);$retryService->deliver($retry['id']);check($read->status($scope,$retry['id'])['status']==='accepted','Retry accepted');
// Timeout never crosses providers.
$unknownService=new MessagingService($db,$privacy,$suppression,new ProviderManager(new TelnyxProvider(new MockHttpClient(new MockResponse('{}',['http_code'=>500])),$settings),$plivo,$settings),$settings,$bus);
$unknown=$unknownService->enqueue(new SmsRequest($scope,$phone,'Unknown','transactional','unknown'));$unknownService->deliver($unknown['id']);$unknownService->deliver($unknown['id']);check($read->status($scope,$unknown['id'])['status']==='unknown','Unknown outcome quarantined');check((int)$db->fetchOne('SELECT attempt_count FROM ino_sms_message WHERE id=UNHEX(?)',[$unknown['id']])===1,'Unknown never resends');
$config->values['telnyxEnabled']=false;$fallback=$messaging->enqueue(new SmsRequest($scope,$phone,'Fallback','transactional','fallback'));$messaging->deliver($fallback['id']);$messaging->deliver($fallback['id']);check($read->status($scope,$fallback['id'])['provider']==='plivo','Safe failover chooses backup sender');$config->values['telnyxEnabled']=true;
$seed=new Inocean\Messaging\Command\SeedTemplatesCommand($db);$seed->run(new Symfony\Component\Console\Input\ArrayInput([]),new Symfony\Component\Console\Output\NullOutput());
$templates=new SmsTemplateService($db,$renderer);foreach(['en-CA','zh-CN','zh-TW','fr-CA'] as $locale) { check($templates->render($scope,'order_ready',$locale,['businessName'=>'Test','reference'=>'123'])['purpose']==='transactional','Default template '.$locale); }
$config->values['monthlySegments']=0;$quota=$messaging->enqueue(new SmsRequest($scope,$phone,'Quota','transactional','quota'));$messaging->deliver($quota['id']);check($read->status($scope,$quota['id'])['errorCode']==='monthly_quota','Quota reserved before sending');
// Execute Shopware's own ACL annotation validator using the plugin route privileges.
$kernelStub=new class implements Symfony\Component\HttpKernel\HttpKernelInterface { public function handle(Request $request,int $type=self::MAIN_REQUEST,bool $catch=true): Symfony\Component\HttpFoundation\Response { return new Symfony\Component\HttpFoundation\Response(); } };
$acl=new Shopware\Core\Framework\Api\Acl\AclAnnotationValidator($db);
$source=new Shopware\Core\Framework\Api\Context\AdminApiSource(null,str_repeat('a',32));
$aclRequest=Request::create('/api/_action/inocean/messaging/send','POST');
$aclRequest->attributes->set('_acl',['inocean_messaging:send']);
$aclRequest->attributes->set(Shopware\Core\PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT,new Shopware\Core\Framework\Context($source));
$controllerEvent=new Symfony\Component\HttpKernel\Event\ControllerEvent($kernelStub,static function(){},$aclRequest,Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);
rejects(fn()=>$acl->validate($controllerEvent),'Native Shopware ACL denies missing send permission');
$source->setPermissions(['inocean_messaging:read']);rejects(fn()=>$acl->validate($controllerEvent),'Read-only integration cannot send');
$source->setPermissions(['inocean_messaging:send']);$acl->validate($controllerEvent);check(true,'Native Shopware ACL permits send role');
$aclRequest->attributes->set('_acl',['inocean_messaging:manage']);rejects(fn()=>$acl->validate($controllerEvent),'Send integration cannot manage consent');
// Pending task must recheck provider suppression after a safe failover is scheduled.
$config->values['monthlySegments']=1000;$config->values['telnyxEnabled']=false;
$blockedBackup=$messaging->enqueue(new SmsRequest($scope,$phone,'Backup blocked','transactional','backup-blocked'));
$messaging->deliver($blockedBackup['id']);
$suppression->set($scope,$phone,'all','plivo',true,'provider-test');$messaging->deliver($blockedBackup['id']);
check($read->status($scope,$blockedBackup['id'])['status']==='suppressed','Failover rechecks backup provider suppression');
$suppression->set($scope,$phone,'all','plivo',false,'provider-test');$config->values['telnyxEnabled']=true;
// Outbox recovery never resubmits a send interrupted after the HTTP boundary.
$crashed=$messaging->enqueue(new SmsRequest($scope,$phone,'Crash test','transactional','crash'));
$db->executeStatement("UPDATE ino_sms_message SET status='sending',updated_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 20 MINUTE) WHERE id=UNHEX(?)",[$crashed['id']]);
$db->insert('ino_sms_attempt',['id'=>Uuid::randomBytes(),'message_id'=>Uuid::fromHexToBytes($crashed['id']),'provider'=>'telnyx','status'=>'sending','created_at'=>SmsConsentService::now()]);
$recovery=new Inocean\Messaging\Command\RecoverCommand($db,$bus);
$recovery->run(new Symfony\Component\Console\Input\ArrayInput([]),new Symfony\Component\Console\Output\NullOutput());
check($read->status($scope,$crashed['id'])['status']==='unknown','Interrupted worker quarantined by recovery');
check($db->fetchOne('SELECT status FROM ino_sms_attempt WHERE message_id=UNHEX(?)',[$crashed['id']])==='unknown','Interrupted attempt audit quarantined with message');
$providerBlockedService=new MessagingService($db,$privacy,$suppression,new ProviderManager(new TelnyxProvider(new MockHttpClient(new MockResponse('{"errors":[{"code":"40300"}]}',['http_code'=>403])),$settings),$plivo,$settings),$settings,$bus);
$providerBlocked=$providerBlockedService->enqueue(new SmsRequest($scope,$phone,'Provider blocked','transactional','provider-blocked'));
$providerBlockedService->deliver($providerBlocked['id']);
check($read->status($scope,$providerBlocked['id'])['status']==='provider_suppressed','Provider opt-out stops sending without failover');
rejects(fn()=>$suppression->assertAllowed($scope,$privacy->hash($phone),'marketing','plivo'),'Provider rejection synchronizes cross-provider marketing suppression');
$suppression->inbound($scope,$phone,'START','telnyx','provider-start',SmsConsentService::now());
$timestampPhone='+16045550124';$hash=$privacy->hash($timestampPhone);
$consent->record($scope,$timestampPhone,'transactional_authorization','test','authorized');
$suppression->inbound($scope,$timestampPhone,'STOP','telnyx','new-stop',SmsConsentService::now());
$suppression->inbound($scope,$timestampPhone,'START','telnyx','old-start',gmdate('Y-m-d H:i:s',time()-60));
rejects(fn()=>$suppression->assertAllowed($scope,$hash,'transactional','telnyx'),'Old START cannot clear newer STOP');
$suppression->inbound($scope,$timestampPhone,'STOP','plivo','plivo-stop');
$suppression->inbound($scope,$timestampPhone,'START','plivo','plivo-start');
rejects(fn()=>$suppression->assertAllowed($scope,$hash,'transactional','plivo'),'Plivo START without occurrence ordering requires reconciliation');
// Independent PHP processes contend for the same persisted task.
$config->values['monthlySegments']=1000;
$parallel=$messaging->enqueue(new SmsRequest($scope,$phone,'Parallel','transactional','parallel'));
$processes=[];
putenv('INO_SMS_TEST_MESSAGE_ID='.$parallel['id']);
for($i=0;$i<4;++$i) { $pipes=[];$processes[]=proc_open([PHP_BINARY,__DIR__.'/worker.php'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDOUT],$pipes); }
foreach($processes as $process) { check(proc_close($process)===0,'Parallel worker exits cleanly'); }
check((int)$db->fetchOne('SELECT attempt_count FROM ino_sms_message WHERE id=UNHEX(?)',[$parallel['id']])===1,'Four concurrent workers create one attempt');
check($read->status($scope,$parallel['id'])['status']==='accepted','Concurrent send accepted once');
$queueTask=$messaging->enqueue(new SmsRequest($scope,$phone,'Queue transport','transactional','native-queue'));
$transport=new Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport(new Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection(['table_name'=>'ino_sms_test_queue','queue_name'=>'sms-test'],$db),Symfony\Component\Messenger\Transport\Serialization\Serializer::create());
$transport->setup();$transport->send(new Envelope(new Inocean\Messaging\Core\Message\SendSmsMessage($queueTask['id'])));
$handler=new Inocean\Messaging\Core\Message\SendSmsHandler($messaging);
foreach($transport->get() as $envelope) { check($envelope->getMessage()->id===$queueTask['id'],'Native Doctrine transport serializes SMS ID');$handler($envelope->getMessage());$transport->ack($envelope); }
check($read->status($scope,$queueTask['id'])['status']==='accepted','Native Messenger handler delivers persisted task');
check($transport->getMessageCount()===0,'Native transport ACK removes handled envelope');
putenv('INO_SMS_TEST_ENQUEUE=1');$processes=[];
for($i=0;$i<4;++$i) { $pipes=[];$processes[]=proc_open([PHP_BINARY,__DIR__.'/worker.php'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDOUT],$pipes); }
foreach($processes as $process) { check(proc_close($process)===0,'Concurrent enqueue worker exits cleanly'); }
putenv('INO_SMS_TEST_ENQUEUE');
check((int)$db->fetchOne("SELECT COUNT(*) FROM ino_sms_message WHERE idempotency_key='parallel-enqueue'")===1,'Concurrent enqueue keeps one idempotent task');
check((int)$db->fetchOne("SELECT attempt_count FROM ino_sms_message WHERE idempotency_key='parallel-enqueue'")===1,'Concurrent enqueue and send creates one provider attempt');
$failingBus=new class implements MessageBusInterface { public function dispatch(object $message,array $stamps=[]): Envelope { throw new RuntimeException('Simulated queue outage'); } };
$outboxService=new MessagingService($db,$privacy,$suppression,$manager,$settings,$failingBus);
$outbox=$outboxService->enqueue(new SmsRequest($scope,$phone,'Outbox survival','transactional','outbox-survival'));
check($read->status($scope,$outbox['id'])['status']==='queued','Queue outage retains committed outbox');
echo "$passed checks passed\n";
