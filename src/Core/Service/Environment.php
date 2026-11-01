<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
final class Environment
{
    public static function get(string $name): string { return (string)EnvironmentHelper::getVariable($name,getenv($name) ?: ''); }
}
