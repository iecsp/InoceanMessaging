<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/support.php';
use Inocean\Messaging\Core\Service\{Settings,Privacy,SmsConsentService,SmsSuppressionService,ProviderManager,MessagingService};
use Inocean\Messaging\Core\Provider\{TelnyxProvider,PlivoProvider};
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
try {
 $db=Doctrine\DBAL\DriverManager::getConnection((new Doctrine\DBAL\Tools\DsnParser(['mysql'=>'pdo_mysql']))->parse(getenv('INO_SMS_TEST_DATABASE_URL')));
 if($db->getDatabase()!=='ino_messaging_test') { throw new RuntimeException('Test database only'); }
 $settings=new Settings(new TestConfig());$privacy=new Privacy();$consent=new SmsConsentService($db,$privacy);$suppression=new SmsSuppressionService($db,$privacy,$consent);
 $http=new MockHttpClient(function(){usleep(150000);return new MockResponse('{"data":{"id":"concurrent-'.uniqid().'"}}');});
 $providers=new ProviderManager(new TelnyxProvider($http,$settings),new PlivoProvider($http,$settings),$settings);
 $service=new MessagingService($db,$privacy,$suppression,$providers,$settings,new TestBus());if(getenv('INO_SMS_TEST_ENQUEUE')) { $task=$service->enqueue(new \Inocean\Messaging\Core\DTO\SmsRequest(str_repeat('1',32),'+16045550123','Parallel enqueue','transactional','parallel-enqueue'));$service->deliver($task['id']); } else { $service->deliver(getenv('INO_SMS_TEST_MESSAGE_ID')); }
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage());exit(1); }
