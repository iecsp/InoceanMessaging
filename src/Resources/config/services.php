<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set('inocean.messaging.http_client', 'Symfony\\Component\\HttpClient\\HttpClient')
        // A raw client avoids automatic retries of non-idempotent SMS POSTs.
        ->factory(['Symfony\\Component\\HttpClient\\HttpClient', 'create']);

    $services->set('Inocean\\Messaging\\Core\\Service\\Privacy');

    $services->set('Inocean\\Messaging\\Core\\Service\\Settings');

    $services->set('Inocean\\Messaging\\Core\\Service\\Segments');

    $services->set('Inocean\\Messaging\\Core\\Service\\TemplateRenderer');

    $services->set('Inocean\\Messaging\\Core\\Service\\ProviderManager');

    $services->set('Inocean\\Messaging\\Core\\Service\\SmsConsentService');

    $services->set('Inocean\\Messaging\\Core\\Service\\SmsSuppressionService');

    $services->set('Inocean\\Messaging\\Core\\Service\\SmsTemplateService');

    $services->set('Inocean\\Messaging\\Core\\Service\\MessagingService');

    $services->set('Inocean\\Messaging\\Core\\Service\\ReadService');

    $services->set('Inocean\\Messaging\\Core\\Service\\WebhookSignature');

    $services->set('Inocean\\Messaging\\Core\\Service\\WebhookService');

    $services->set('Inocean\\Messaging\\Core\\Service\\UnavailableIntegration');

    $services->set('Inocean\\Messaging\\Core\\Provider\\TelnyxProvider')
        ->arg('$http', service('inocean.messaging.http_client'));

    $services->set('Inocean\\Messaging\\Core\\Provider\\PlivoProvider')
        ->arg('$http', service('inocean.messaging.http_client'));

    $services->alias('Inocean\\Messaging\\Core\\Contract\\StoreAccessInterface', 'Inocean\\Messaging\\Core\\Service\\UnavailableIntegration');

    $services->alias('Inocean\\Messaging\\Core\\Contract\\BusinessReferenceResolverInterface', 'Inocean\\Messaging\\Core\\Service\\UnavailableIntegration');

    $services->set('Inocean\\Messaging\\Content\\MessageDefinition')
        ->tag('shopware.entity.definition', ['entity' => 'ino_sms_message']);

    $services->set('Inocean\\Messaging\\Content\\AttemptDefinition')
        ->tag('shopware.entity.definition', ['entity' => 'ino_sms_attempt']);

    $services->set('Inocean\\Messaging\\Content\\ConsentDefinition')
        ->tag('shopware.entity.definition', ['entity' => 'ino_sms_consent']);

    $services->set('Inocean\\Messaging\\Content\\SuppressionDefinition')
        ->tag('shopware.entity.definition', ['entity' => 'ino_sms_suppression']);

    $services->set('Inocean\\Messaging\\Content\\TemplateDefinition')
        ->tag('shopware.entity.definition', ['entity' => 'ino_sms_template']);

    $services->set('Inocean\\Messaging\\Content\\TemplateTranslationDefinition')
        ->tag('shopware.entity.definition', ['entity' => 'ino_sms_template_translation']);

    $services->set('Inocean\\Messaging\\Content\\WebhookEventDefinition')
        ->tag('shopware.entity.definition', ['entity' => 'ino_sms_webhook_event']);

    $services->set('Inocean\\Messaging\\Api\\AdminController')
        ->public()
        ->tag('controller.service_arguments');

    $services->set('Inocean\\Messaging\\Api\\StoreController')
        ->public()
        ->tag('controller.service_arguments');

    $services->set('Inocean\\Messaging\\Api\\TelnyxWebhookController')
        ->public()
        ->tag('controller.service_arguments');

    $services->set('Inocean\\Messaging\\Api\\PlivoWebhookController')
        ->public()
        ->tag('controller.service_arguments');

    $services->set('Inocean\\Messaging\\Core\\Message\\SendSmsHandler');

    $services->set('Inocean\\Messaging\\Command\\RecoverCommand');

    $services->set('Inocean\\Messaging\\Api\\ApiExceptionSubscriber');

    $services->set('Inocean\\Messaging\\Command\\SeedTemplatesCommand');

    $services->set('Inocean\\Messaging\\Command\\RetentionCommand');

    $services->set('Inocean\\Messaging\\Api\\ProviderController')
        ->public()
        ->tag('controller.service_arguments');
    $services->set('Inocean\\Messaging\\Core\\Service\\CustomerProfileService')
        ->arg('$customerRepository', service('customer.repository'));
    $services->set('Inocean\\Messaging\\Core\\Service\\UnsubscribeToken');
    $services->set('Inocean\\Messaging\\Core\\Service\\CustomerSubscriptionService');
    $services->set('Inocean\\Messaging\\Subscriber\\CustomerSubscriptionSubscriber');
    $services->set('Inocean\\Messaging\\Api\\UnsubscribeController')->arg('$translator', service('inocean.messaging.translator'))->public()->tag('controller.service_arguments');
    $services->set('Inocean\\Messaging\\Routing\\PublicMessagingRouteScope')->autowire(false)->autoconfigure(false)->tag('shopware.route_scope');
    $services->set('inocean.messaging.translator', 'Symfony\\Component\\Translation\\Translator')->autowire(false)->autoconfigure(false)->factory(['Inocean\\Messaging\\Core\\Service\\MessagingTranslationFactory', 'create']);
};
