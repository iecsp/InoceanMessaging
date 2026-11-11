<?php declare(strict_types=1);

namespace Inocean\Messaging\Core\Flow;

use Inocean\Messaging\Core\DTO\SmsRequest;
use Inocean\Messaging\Core\Service\CustomerProfileService;
use Inocean\Messaging\Core\Service\MessagingService;
use Inocean\Messaging\Core\Service\SmsTemplateService;
use Inocean\Messaging\Core\Service\UnsubscribeToken;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Framework\Event\CustomerAware;
use Shopware\Core\Framework\Event\MailAware;
use Shopware\Core\Framework\Uuid\Uuid;

final class SendMessageAction extends FlowAction
{
    public const NAME = 'action.inocean.messaging.send';

    public function __construct(
        private readonly CustomerProfileService $customers,
        private readonly SmsTemplateService $templates,
        private readonly MessagingService $messaging,
    ) {}

    public static function getName(): string
    {
        return self::NAME;
    }

    public function requirements(): array
    {
        return [CustomerAware::class];
    }

    public function handleFlow(StorableFlow $flow): void
    {
        $templateId = $flow->getConfig()['templateId'] ?? null;
        if (!is_string($templateId) || !Uuid::isValid($templateId)) {
            throw new \InvalidArgumentException('Flow Send Message requires an SMS template.');
        }

        $customerId = $flow->getStore(CustomerAware::CUSTOMER_ID);
        $scope = $flow->getStore(MailAware::SALES_CHANNEL_ID) ?? $flow->getData(MailAware::SALES_CHANNEL_ID);
        if (!is_string($customerId) || !is_string($scope)) {
            throw new \DomainException('Flow Send Message requires a customer and sales channel.');
        }

        $recipient = $this->customers->recipient($scope, $customerId);
        $variables = $recipient['variables'] + ['unsubscribeUrl' => UnsubscribeToken::PLACEHOLDER];
        $template = $this->templates->renderById($scope, $templateId, $variables['customerLanguage'], $variables);

        $this->messaging->enqueue(new SmsRequest(
            $scope,
            $recipient['phone'],
            $template['body'],
            $template['purpose'],
            'flow:'.Uuid::randomHex(),
            $template['code'],
            $customerId,
        ));
    }
}
