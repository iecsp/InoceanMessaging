<?php declare(strict_types=1);

namespace Inocean\Messaging\Core\Service;

final class UnsubscribeToken
{
    public const PLACEHOLDER = '__INO_SMS_UNSUBSCRIBE_URL__';
    public function __construct(private readonly Privacy $privacy, private readonly Settings $settings) {}

    public function configured(string $scope): bool { return trim((string) $this->settings->get('unsubscribeBaseUrl', $scope, '')) !== ''; }

    public function link(string $scope, string $phone): string
    {
        $base = rtrim((string) $this->settings->get('unsubscribeBaseUrl', $scope, ''), '/');
        $parts = parse_url($base);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) { throw new \DomainException('Configure an absolute HTTPS unsubscribeBaseUrl before sending marketing or customer SMS'); }
        // Secretbox supplies authenticated encryption: the URL contains no plaintext phone.
        $payload = ['v' => 1, 't' => 'u1', 'scope' => $scope, 'phone' => $phone, 'exp' => time() + 366 * 86400];
        $token = rtrim(strtr($this->privacy->seal(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $base.'/inocean/messaging/unsubscribe/'.$token;
    }

    public function verify(string $token): array
    {
        if (strlen($token) > 2048 || !preg_match('/^[A-Za-z0-9_-]+$/D', $token)) { throw new \InvalidArgumentException('Invalid unsubscribe link'); }
        try {
            $payload = json_decode($this->privacy->open(strtr($token, '-_', '+/')), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || ($payload['v'] ?? null) !== 1 || ($payload['t'] ?? null) !== 'u1' || !is_int($payload['exp'] ?? null) || $payload['exp'] < time() || !preg_match('/^[0-9a-f]{32}$/D', $payload['scope'] ?? '') || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $payload['phone'] ?? '')) { throw new \RuntimeException(); }
            return $payload;
        } catch (\Throwable) { throw new \InvalidArgumentException('Invalid or expired unsubscribe link'); }
    }
}
