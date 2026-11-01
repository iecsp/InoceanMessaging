<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\DTO;
final readonly class SmsResult
{
    public function __construct(public string $status, public ?string $providerId = null, public ?string $error = null, public ?int $segments = null, public ?string $cost = null, public ?string $currency = null) {}
    public function mayRetry(): bool { return $this->status === 'rate_limited'; }
    public function mayFailover(): bool { return $this->status === 'unavailable_rejected'; }
}
