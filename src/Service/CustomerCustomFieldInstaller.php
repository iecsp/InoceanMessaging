<?php declare(strict_types=1);

namespace Inocean\Messaging\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Inocean\Messaging\Core\Service\MessagingTranslationFactory;

final class CustomerCustomFieldInstaller
{
    public function __construct(private readonly EntityRepository $sets) {}
    public function install(Context $context): void
    {
        $translator = MessagingTranslationFactory::create();
        $labels = static function (string $key) use ($translator): array {
            $values = [];
            foreach (MessagingTranslationFactory::LOCALES as $locale) {
                $values[$locale] = $translator->trans($key, [], MessagingTranslationFactory::DOMAIN, $locale);
            }
            $values['zh-HK'] = $values['zh-TW'];
            return $values;
        };
        $this->sets->upsert([[
            'id' => '0f9c26fb8184497eb1806f781379d401',
            'name' => 'ino_messaging_customer',
            'config' => ['label' => $labels('customer.group')],
            'relations' => [['id' => '0f9c26fb8184497eb1806f781379d402', 'entityName' => 'customer']],
            'customFields' => [[
                'id' => '0f9c26fb8184497eb1806f781379d403',
                'name' => 'ino_sms_subscribed',
                'type' => CustomFieldTypes::BOOL,
                'config' => [
                    'label' => $labels('customer.label'),
                    'helpText' => $labels('customer.help'),
                    'componentName' => 'sw-field', 'customFieldType' => 'checkbox', 'customFieldPosition' => 1,
                ],
                'allowCustomerWrite' => false,
            ]],
        ]], $context);
    }
}
