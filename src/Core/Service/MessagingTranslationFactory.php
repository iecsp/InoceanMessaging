<?php declare(strict_types=1);

namespace Inocean\Messaging\Core\Service;

use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\Translator;

/** Uses Symfony's translation catalogues without requiring an active storefront or sales channel. */
final class MessagingTranslationFactory
{
    public const DOMAIN = 'inocean_messaging';
    public const LOCALES = ['en-GB', 'zh-CN', 'zh-TW'];

    public static function create(): Translator
    {
        $translator = new Translator('en-GB');
        $translator->addLoader('json', new JsonFileLoader());
        $translator->setFallbackLocales(['en-GB']);
        foreach (self::LOCALES as $locale) {
            $translator->addResource('json', dirname(__DIR__, 2).'/Resources/translations/inocean_messaging.'.$locale.'.json', $locale, self::DOMAIN);
        }
        return $translator;
    }
}
