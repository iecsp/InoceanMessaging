<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Message;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Inocean\Messaging\Core\Service\MessagingService;
#[AsMessageHandler]
final class SendSmsHandler { public function __construct(private readonly MessagingService $messaging) {} public function __invoke(SendSmsMessage $message): void { $this->messaging->deliver($message->id); } }
