<?php declare(strict_types=1);

namespace Inocean\Messaging\Api;

use Inocean\Messaging\Core\Service\{UnsubscribeToken, CustomerSubscriptionService, MessagingTranslationFactory};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(defaults: ['_routeScope' => ['ino-messaging-public'], '_csrf_protected' => false, '_noStore' => true])]
final class UnsubscribeController
{
    public function __construct(private readonly UnsubscribeToken $tokens, private readonly CustomerSubscriptionService $subscriptions, private readonly TranslatorInterface $translator) {}

    #[Route(path: '/inocean/messaging/unsubscribe/{token}', name: 'frontend.inocean.messaging.unsubscribe', methods: ['GET', 'POST'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function unsubscribe(string $token, Request $request): Response
    {
        $locale = $this->locale($request);
        try { $payload = $this->tokens->verify($token); }
        catch (\InvalidArgumentException) { return $this->page($locale, 'invalid', false, 400); }
        if ($request->isMethod('POST') && $request->request->get('confirm') === 'yes') {
            $this->subscriptions->unsubscribe($payload['scope'], $payload['phone'], 'Recipient confirmed the authenticated unsubscribe link. Token fingerprint: '.hash('sha256', $token));
            return $this->page($locale, 'done');
        }
        return $this->page($locale, 'confirm', true);
    }

    private function locale(Request $request): string
    {
        $normalize = static function (string $language): ?string {
            $language = strtolower(str_replace('_', '-', $language));
            if ($language === 'en' || str_starts_with($language, 'en-')) { return 'en-GB'; }
            if (in_array($language, ['zh-tw', 'zh-hk', 'zh-mo', 'zh-hant', 'zh-hant-tw', 'zh-hant-hk', 'zh-hant-mo'], true)) { return 'zh-TW'; }
            if (in_array($language, ['zh', 'zh-cn', 'zh-sg', 'zh-hans', 'zh-hans-cn', 'zh-hans-sg'], true)) { return 'zh-CN'; }
            return null;
        };
        $explicit = $request->query->all()['lang'] ?? null;
        if (is_string($explicit) && ($locale = $normalize($explicit)) !== null) { return $locale; }
        foreach ($request->getLanguages() as $language) {
            if (($locale = $normalize($language)) !== null) { return $locale; }
        }
        return 'en-GB';
    }

    private function page(string $locale, string $state, bool $confirm = false, int $status = 200): Response
    {
        $title = $this->translator->trans('unsubscribe.'.$state.'Title', [], MessagingTranslationFactory::DOMAIN, $locale);
        $description = $this->translator->trans('unsubscribe.'.$state.'Body', [], MessagingTranslationFactory::DOMAIN, $locale);
        $button = $this->translator->trans('unsubscribe.button', [], MessagingTranslationFactory::DOMAIN, $locale);
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $form = $confirm ? '<form method="post"><input type="hidden" name="confirm" value="yes"><button type="submit">' . $escape($button) . '</button></form>' : '';
        $html = '<!doctype html><html lang="'.$escape($locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.$escape($title).'</title><style>body{font:17px/1.7 system-ui,sans-serif;background:#f4f6f8;color:#16212d;margin:0;padding:24px}main{max-width:560px;margin:12vh auto;background:#fff;border-radius:16px;padding:32px;box-shadow:0 8px 40px #16212d10}h1{font-size:26px;line-height:1.3}button{background:#155eef;color:white;border:0;border-radius:8px;padding:14px 22px;font:inherit;cursor:pointer;width:100%;margin-top:18px}</style></head><body><main><h1>'.$escape($title).'</h1><p>'.$escape($description).'</p>'.$form.'</main></body></html>';
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Language' => $locale, 'Vary' => 'Accept-Language', 'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY', 'X-Robots-Tag' => 'noindex, nofollow', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"]);
    }
}
