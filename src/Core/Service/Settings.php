<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Shopware\Core\System\SystemConfig\SystemConfigService;
final class Settings
{
    public function __construct(private readonly SystemConfigService $config) {}
    public function get(string $key, string $scope, mixed $default = null): mixed { return $this->config->get('InoceanMessaging.config.'.$key, $scope) ?? $default; }
    public function secret(string $name): string { $value = (string) \Inocean\Messaging\Core\Service\Environment::get($name); if ($value === '') { throw new \RuntimeException('Missing messaging secret: '.$name); } return $value; }
}
