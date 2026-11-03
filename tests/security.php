<?php declare(strict_types=1);
// Destructive tests inherit run.php's disposable-database guard and mock-only providers.
require __DIR__.'/members.php';

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\{EntityWriteGateway, ExceptionHandlerRegistry};
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PostWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Inocean\Messaging\Core\DTO\SmsRequest;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

// Exercise the real DAL transaction boundary, not manually invoked success callbacks.
$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber($subscriber);
$gateway = new EntityWriteGateway(100, $db, $dispatcher, new ExceptionHandlerRegistry([]), $registry);
$untrusted->setPermissions(['inocean_messaging:manage']);
function savePreference(bool $active): void {
    global $gateway, $untrusted;
    $event = preferenceEvent($active, new Context($untrusted));
    $gateway->execute($event->getCommands(), $event->getWriteContext());
}
savePreference(true);
check($consent->allowed($scope,$privacy->hash($memberPhone),'marketing'), 'Real DAL opt-in commits consent');
check($db->fetchOne('SELECT LOWER(HEX(customer_id)) FROM ino_sms_consent WHERE phone_hash=? ORDER BY sequence DESC LIMIT 1',[$privacy->hash($memberPhone)]) === $memberId, 'Consent retains customer identity');
$before=(int)$db->fetchOne('SELECT COUNT(*) FROM ino_sms_consent');
$fail = static function (): void { throw new RuntimeException('Injected failure after consent write'); };
$dispatcher->addListener(PostWriteValidationEvent::class, $fail, -1000);
rejects(fn()=>savePreference(false), 'Failure after synchronization aborts customer write');
check($profiles->recipient($scope,$memberId)['subscribed'], 'Customer flag rolls back with failed consent transaction');
check((int)$db->fetchOne('SELECT COUNT(*) FROM ino_sms_consent')===$before, 'Consent audit rolls back with customer field');
$suppression->assertAllowed($scope,$privacy->hash($memberPhone),'marketing','telnyx');
check(true, 'Suppression rollback leaves original authorization intact');
$dispatcher->removeListener(PostWriteValidationEvent::class, $fail);
// A failed consent INSERT must also roll back the customer update.
$db->executeStatement("CREATE TRIGGER fail_sms_consent BEFORE INSERT ON ino_sms_consent FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected consent failure'");
try { rejects(fn()=>savePreference(false), 'Consent storage failure rejects preference save'); }
finally { $db->executeStatement('DROP TRIGGER fail_sms_consent'); }
check($profiles->recipient($scope,$memberId)['subscribed'], 'Consent INSERT failure preserves customer flag');
// A later invalid billing number must not erase the original consent identity.
$db->executeStatement('UPDATE customer_address SET phone_number=? WHERE id=UNHEX(?)',['invalid',$addressId]);
savePreference(false);
check(!$profiles->recipient($scope,$memberId,false)['subscribed'], 'Invalid current phone still allows reliable historical opt-out');
rejects(fn()=>$suppression->assertAllowed($scope,$privacy->hash($memberPhone),'marketing','plivo'), 'Historical number blocked across providers after phone change');
rejects(fn()=>$memberMessaging->enqueue(new SmsRequest($scope,$memberPhone,'Offer','marketing','raw-after-opt-out')), 'Raw-phone marketing cannot bypass customer opt-out');
$db->executeStatement('UPDATE customer_address SET phone_number=? WHERE id=UNHEX(?)',[$memberPhone,$addressId]);
savePreference(true);
// A single DAL write may update the address before the customer command executes.
$event=preferenceEvent(false,new Context($untrusted));
$addressCommand=new Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand(
    $registry->getByEntityName('customer_address'), ['phone_number'=>'+16045550888'], ['id'=>hex2bin($addressId)],
    new Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence('customer_address',['id'=>hex2bin($addressId)],true,false,false,[]), '/'
);
$gateway->execute([$addressCommand,...$event->getCommands()],$event->getWriteContext());
foreach ([$memberPhone,'+16045550888'] as $number) {
    check((bool)$db->fetchOne("SELECT active FROM ino_sms_suppression WHERE scope_id=UNHEX(?) AND phone_hash=? AND purpose='marketing' AND provider=''",[$scope,$privacy->hash($number)]), 'Atomic address change suppresses '.$number);
}
// Existing subscribers without recoverable history must fail visibly, not save a false success.
$db->executeStatement('DELETE FROM ino_sms_consent WHERE customer_id=UNHEX(?)',[$memberId]);
$db->executeStatement('UPDATE ino_sms_message SET customer_id=NULL WHERE customer_id=UNHEX(?)',[$memberId]);
$db->executeStatement("UPDATE customer SET custom_fields=JSON_SET(custom_fields,'$.ino_sms_subscribed',1) WHERE id=UNHEX(?)",[$memberId]);
$db->executeStatement("UPDATE customer_address SET phone_number='invalid' WHERE id=UNHEX(?)",[$addressId]);
rejects(fn()=>savePreference(false), 'Unresolvable legacy number rejects save explicitly');
check($profiles->recipient($scope,$memberId,false)['subscribed'], 'Unresolvable legacy consent does not falsely report unsubscribe');

$admissionScope=str_repeat('5',32);
$db->executeStatement('INSERT IGNORE INTO sales_channel (id) VALUES (UNHEX(?))',[$admissionScope]);
$consent->record($admissionScope,$phone,'transactional_authorization','test','admission tests');
$config->values['maxPending']=2;
$config->values['enqueuePerMinute']=3;
$admit=fn(string $key)=>$messaging->enqueue(new SmsRequest($admissionScope,$phone,'Admission','transactional',$key));
$a=$admit('admit-a');$b=$admit('admit-b');
$queuedBefore=count($bus->messages);
try { $admit('admit-c'); throw new RuntimeException('Pending limit not enforced'); }
catch (TooManyRequestsHttpException $e) { check($e->getStatusCode()===429 && $e->getHeaders()['Retry-After']==60, 'Pending limit returns 429 and Retry-After'); }
check(count($bus->messages)===$queuedBefore, 'Rejected admission does not dispatch');
check($admit('admit-a')['id']===$a['id'], 'Idempotent replay succeeds at full capacity');
$consent->record($other,$phone,'transactional_authorization','test','independent channel');
check(isset($messaging->enqueue(new SmsRequest($other,$phone,'Other channel','transactional','other-admission'))['id']), 'Full channel does not consume another channel capacity');
rejects(fn()=>$messaging->enqueue(new SmsRequest($admissionScope,$phone,'Changed','transactional','admit-a')), 'Full-capacity replay still rejects payload mismatch');
$db->executeStatement("UPDATE ino_sms_message SET status='rejected' WHERE id=UNHEX(?)",[$a['id']]);
$c=$admit('admit-c');
check(isset($c['id']), 'Completed task releases pending capacity');
$db->executeStatement("UPDATE ino_sms_message SET status='rejected' WHERE scope_id=UNHEX(?)",[$admissionScope]);
try { $admit('admit-d'); throw new RuntimeException('Rate limit not enforced'); }
catch (TooManyRequestsHttpException $e) { check($e->getMessage()==='sms_enqueue_rate_limit', 'Recent completed tasks still consume admission rate'); }
check((int)$db->fetchOne('SELECT COUNT(*) FROM ino_sms_message WHERE scope_id=UNHEX(?)',[$admissionScope])===3, 'Rejected admissions leave no message records');
$db->executeStatement('UPDATE ino_sms_message SET created_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 MINUTE) WHERE scope_id=UNHEX(?)',[$admissionScope]);
check(isset($admit('admit-d')['id']), 'Admission resumes after rolling window expires');
$db->executeStatement("UPDATE ino_sms_message SET status='rejected' WHERE scope_id=UNHEX(?)",[$admissionScope]);
putenv('INO_SMS_ADMISSION_RACE=1');
$workers=[];
for($i=0;$i<6;++$i) { $pipes=[];$workers[]=proc_open([PHP_BINARY,__DIR__.'/worker.php'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDOUT],$pipes); }
foreach($workers as $worker) { check(proc_close($worker)===0, 'Concurrent admission worker exits cleanly'); }
putenv('INO_SMS_ADMISSION_RACE');
check((int)$db->fetchOne("SELECT COUNT(*) FROM ino_sms_message WHERE scope_id=UNHEX(?) AND status='queued'",[$admissionScope])===2, 'Six concurrent callers cannot exceed two pending slots');
// Repeat with free capacity but only two admissions per minute.
$db->executeStatement("UPDATE ino_sms_message SET status='rejected',created_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 MINUTE) WHERE scope_id=UNHEX(?)",[$admissionScope]);
putenv('INO_SMS_ADMISSION_RACE=1');putenv('INO_SMS_ADMISSION_RATE=1');
$workers=[];
for($i=0;$i<6;++$i) { $pipes=[];$workers[]=proc_open([PHP_BINARY,__DIR__.'/worker.php'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDOUT],$pipes); }
foreach($workers as $worker) { check(proc_close($worker)===0, 'Concurrent rate-limit worker exits cleanly'); }
putenv('INO_SMS_ADMISSION_RACE');putenv('INO_SMS_ADMISSION_RATE');
check((int)$db->fetchOne("SELECT COUNT(*) FROM ino_sms_message WHERE scope_id=UNHEX(?) AND status='queued'",[$admissionScope])===2, 'Concurrent callers cannot exceed admission rate with free capacity');
$plan=$db->fetchAllAssociative("EXPLAIN SELECT id FROM ino_sms_message FORCE INDEX (idx_sms_admission) WHERE scope_id=UNHEX(?) AND status IN ('queued','sending') LIMIT 2",[$admissionScope]);
check($plan[0]['key']==='idx_sms_admission', 'Pending-capacity lookup has a usable composite index');
echo "$passed combined security and regression checks passed\n";
