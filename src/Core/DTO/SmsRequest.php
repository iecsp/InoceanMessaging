<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\DTO;
final readonly class SmsRequest
{
    public function __construct(public string $scope, public string $phone, public string $body, public string $purpose, public string $idempotencyKey, public ?string $templateCode = null) {
        if (!preg_match('/^[0-9a-f]{32}$/D', $scope) || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) { throw new \InvalidArgumentException('Invalid scope or E.164 phone'); }
        if (!in_array($purpose, ['transactional', 'marketing'], true) || trim($body) === '' || strlen($body) > 6000 || $idempotencyKey === '' || strlen($idempotencyKey) > 128) { throw new \InvalidArgumentException('Invalid SMS request'); }
    }
}
