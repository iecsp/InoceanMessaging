<?php declare(strict_types=1);
$root=dirname(__DIR__,4);
$loader=require $root.'/vendor/autoload.php';
$loader->addPsr4('Inocean\\Messaging\\',dirname(__DIR__).'/src/');
date_default_timezone_set('UTC');
set_exception_handler(static function(Throwable $e): never { fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1); });
return $loader;
