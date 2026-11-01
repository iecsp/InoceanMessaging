<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Inocean\Messaging\Core\Contract\SmsProviderInterface;
use Inocean\Messaging\Core\Provider\{TelnyxProvider, PlivoProvider};
final class ProviderManager
{
    public function __construct(private readonly TelnyxProvider $telnyx, private readonly PlivoProvider $plivo, private readonly Settings $settings) {}
    public function get(string $name): SmsProviderInterface { return match ($name) { 'telnyx' => $this->telnyx, 'plivo' => $this->plivo, default => throw new \InvalidArgumentException('Unknown provider') }; }
    public function primary(string $scope): string { return (string) $this->settings->get('defaultProvider', $scope, 'telnyx'); }
    public function fallback(string $scope, string $current): ?string { $backup = (string) $this->settings->get('backupProvider', $scope, 'plivo'); return $this->settings->get('autoFailover', $scope, false) && $backup !== $current ? $backup : null; }
}
