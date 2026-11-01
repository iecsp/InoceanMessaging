<?php declare(strict_types=1);
$loader=require __DIR__.'/bootstrap.php';
use Doctrine\DBAL\DriverManager;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\StaticKernelPluginLoader;
$url=getenv('INO_SMS_TEST_DATABASE_URL');
if(!$url || !str_ends_with($url,'/ino_messaging_test')) { throw new RuntimeException('Disposable database required'); }
foreach(['APP_ENV'=>'dev','APP_SECRET'=>'isolated-messaging-test-only','DATABASE_URL'=>$url,'APP_CACHE_DIR'=>getenv('APP_CACHE_DIR') ?: sys_get_temp_dir().'/ino-messaging-kernel','MESSENGER_TRANSPORT_DSN'=>'doctrine://default','MESSENGER_TRANSPORT_LOW_PRIORITY_DSN'=>'doctrine://default','MESSENGER_TRANSPORT_FAILURE_DSN'=>'doctrine://default?queue_name=failed'] as $name=>$value) { putenv($name.'='.$value);$_SERVER[$name]=$_ENV[$name]=$value; }
$db=DriverManager::getConnection((new \Doctrine\DBAL\Tools\DsnParser(['mysql'=>'pdo_mysql']))->parse($url));
$db->executeStatement('CREATE TABLE IF NOT EXISTS system_config (id BINARY(16) PRIMARY KEY,configuration_key VARCHAR(255),configuration_value JSON,sales_channel_id BINARY(16) NULL)');
$plugins=new StaticKernelPluginLoader($loader,null,[['name'=>'InoceanMessaging','baseClass'=>Inocean\Messaging\InoceanMessaging::class,'active'=>true,'path'=>'custom/plugins/InoceanMessaging','version'=>json_decode(file_get_contents(dirname(__DIR__).'/composer.json'),true,512,JSON_THROW_ON_ERROR)['version'],'autoload'=>['psr-4'=>['Inocean\\Messaging\\'=>['src/']]],'managedByComposer'=>false,'composerName'=>'inocean/messaging']]);
$kernel=new Shopware\Core\Kernel('dev',true,$plugins,'ino-messaging-isolated',Composer\InstalledVersions::getPrettyVersion('shopware/core'),$db,dirname(__DIR__,4));
$kernel->boot();$container=$kernel->getContainer();
foreach(['Inocean\\Messaging\\Api\\AdminController','Inocean\\Messaging\\Api\\StoreController','ino_sms_template.repository'] as $id) { $container->get($id);echo 'PASS container '.$id."\n"; }
$registry=$container->get(Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry::class);
foreach(['message','attempt','consent','suppression','webhook_event','template','template_translation'] as $name) { $definition=$registry->getByEntityName('ino_sms_'.$name);$definition->getFields();echo 'PASS DAL ino_sms_'.$name."\n"; }
$router=$container->get('router');$routes=$router->getRouteCollection();
foreach(['api.action.inocean.messaging.send','store-api.inocean.messaging.template.send','frontend.inocean.messaging.webhook.telnyx','frontend.inocean.messaging.webhook.plivo'] as $name) { if(!$routes->get($name)) { throw new RuntimeException('Route missing '.$name); } echo 'PASS route '.$name."\n"; }
$application=new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);$application->setAutoExit(false);
$lint=$application->run(new Symfony\Component\Console\Input\ArrayInput(['command'=>'lint:container']),new Symfony\Component\Console\Output\NullOutput());
if($lint!==0) { throw new RuntimeException('Container lint failed'); } echo "PASS Symfony lint:container\n";
$response=$kernel->handle(Symfony\Component\HttpFoundation\Request::create('/api/_action/inocean/messaging/send','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],'{}'));
if($response->getStatusCode()!==401) { throw new RuntimeException('Unauthenticated Admin API must return 401, got '.$response->getStatusCode().' '.$response->getContent()); }
echo "PASS HTTP Admin API denies missing OAuth token\n";
$kernel->shutdown();
