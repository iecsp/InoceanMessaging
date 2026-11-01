<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$root=dirname(__DIR__,4);$plugin=dirname(__DIR__);
$schemas=[
 'config.xml'=>$root.'/vendor/shopware/core/System/SystemConfig/Schema/config.xsd',
];
foreach($schemas as $file=>$schema) { $dom=new DOMDocument();$dom->load($plugin.'/src/Resources/config/'.$file);if(!$dom->schemaValidate($schema)) { throw new RuntimeException('Invalid XML '.$file); }echo 'PASS XSD '.$file.PHP_EOL; }
// Load the actual PHP DI configuration; syntax lint alone cannot detect DSL errors.
$container = new Symfony\Component\DependencyInjection\ContainerBuilder();
$loader = new Symfony\Component\DependencyInjection\Loader\PhpFileLoader($container, new Symfony\Component\Config\FileLocator($plugin.'/src/Resources/config'));
$loader->load('services.php');
if (!$container->hasDefinition(Inocean\Messaging\Api\AdminController::class)
    || !$container->hasAlias(Inocean\Messaging\Core\Contract\StoreAccessInterface::class)) {
    throw new RuntimeException('Incomplete PHP service configuration');
}
echo "PASS PHP service configuration\n";
$spec=(new OpenApi\Serializer())->deserializeFile($plugin.'/docs/openapi.json');
if(!$spec->validate()) { throw new RuntimeException('Invalid OpenAPI document'); }
echo "PASS OpenAPI validation\n";
$composer=json_decode(file_get_contents($plugin.'/composer.json'),true,512,JSON_THROW_ON_ERROR);
if($composer['extra']['shopware-plugin-class']!==Inocean\Messaging\InoceanMessaging::class) { throw new RuntimeException('Plugin metadata mismatch'); }
echo "PASS Composer/plugin metadata\n";
