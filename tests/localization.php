<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Inocean\Messaging\Core\Service\MessagingTranslationFactory;

function flattenCatalogue(array $values, string $prefix = ''): array
{
    $flat = [];
    foreach ($values as $key => $value) {
        $path = $prefix === '' ? $key : $prefix.'.'.$key;
        if (is_array($value)) { $flat += flattenCatalogue($value, $path); }
        else { $flat[$path] = $value; }
    }
    ksort($flat);
    return $flat;
}

$root = dirname(__DIR__).'/src/Resources';
$translator = MessagingTranslationFactory::create();
foreach (['app/administration/src/snippet/%s.json', 'translations/inocean_messaging.%s.json'] as $pattern) {
    $baseline = null;
    foreach (MessagingTranslationFactory::LOCALES as $locale) {
        $catalogue = flattenCatalogue(json_decode(file_get_contents($root.'/'.sprintf($pattern, $locale)), true, 512, JSON_THROW_ON_ERROR));
        if ($baseline !== null && array_keys($catalogue) !== array_keys($baseline)) { throw new RuntimeException('Missing locale keys: '.$locale); }
        foreach ($catalogue as $key => $value) {
            if (!is_string($value) || trim($value) === '') { throw new RuntimeException('Empty translation: '.$locale.' '.$key); }
            if ($locale === 'en-GB' && preg_match('/\p{Han}/u', $value)) { throw new RuntimeException('Chinese text in English catalogue: '.$key); }
            if ($baseline !== null) {
                preg_match_all('/\{[a-zA-Z]+\}/', $baseline[$key], $expected);
                preg_match_all('/\{[a-zA-Z]+\}/', $value, $actual);
                sort($expected[0]); sort($actual[0]);
                if ($expected[0] !== $actual[0]) { throw new RuntimeException('Interpolation mismatch: '.$locale.' '.$key); }
            }
            if (str_starts_with($pattern, 'translations/')) {
                if ($translator->trans($key, [], MessagingTranslationFactory::DOMAIN, $locale) !== $value) { throw new RuntimeException('Symfony catalogue mismatch: '.$key); }
            }
        }
        $baseline ??= $catalogue;
        echo 'PASS '.$locale.' '.$pattern.' ('.count($catalogue)." keys)\n";
    }
}
$english = flattenCatalogue(json_decode(file_get_contents($root.'/app/administration/src/snippet/en-GB.json'), true, 512, JSON_THROW_ON_ERROR));
foreach (['module/ino-messaging/page/index.js', 'module/ino-messaging/page/templates.html.twig'] as $file) {
    $source = file_get_contents($root.'/app/administration/src/'.$file);
    preg_match_all('/\$t\(\x27([^\x27]+)\x27/', $source, $matches);
    foreach ($matches[1] as $key) {
        if (str_ends_with($key, '.')) { continue; }
        if (!array_key_exists($key, $english)) { throw new RuntimeException('Unresolved UI translation: '.$key); }
    }
    if (preg_match('/\p{Han}/u', $source)) { throw new RuntimeException('Hardcoded Chinese UI text: '.$file); }
}
echo "PASS UI snippet references and no hardcoded Chinese UI text\n";
