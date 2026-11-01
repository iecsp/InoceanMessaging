<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Contract;
use Inocean\Messaging\Core\DTO\{SmsRequest, SmsResult};
interface SmsProviderInterface { public function name(): string; public function send(SmsRequest $request): SmsResult; }
