<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Message;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
final readonly class SendSmsMessage implements AsyncMessageInterface { public function __construct(public string $id) {} }
