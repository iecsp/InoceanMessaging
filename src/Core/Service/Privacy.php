<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
final class Privacy
{
    private function key(): string {
        $key = base64_decode((string) \Inocean\Messaging\Core\Service\Environment::get('INO_SMS_ENCRYPTION_KEY'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { throw new \RuntimeException('INO_SMS_ENCRYPTION_KEY must be base64 encoded 32 bytes'); }
        return $key;
    }
    public function seal(string $text): string { $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($nonce . sodium_crypto_secretbox($text, $nonce, $this->key())); }
    public function open(string $text): string {
        $raw = base64_decode($text, true);
        if ($raw === false || strlen($raw) < 40) { throw new \RuntimeException('Invalid ciphertext'); }
        $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), $this->key());
        if ($plain === false) { throw new \RuntimeException('Cannot decrypt SMS'); } return $plain;
    }
    public function hash(string $phone): string {
        $key = (string) \Inocean\Messaging\Core\Service\Environment::get('INO_SMS_HASH_KEY'); if (strlen($key) < 32) { throw new \RuntimeException('INO_SMS_HASH_KEY must contain at least 32 characters'); }
        return hash_hmac('sha256', $phone, $key);
    }
}
