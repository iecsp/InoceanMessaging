<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Twig\{Environment, Source};
use Twig\Loader\ArrayLoader;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityPolicy;
final class TemplateRenderer
{
    public function render(string $source, array $variables, array $allowed): string {
        if (strlen($source) > 6000) { throw new \InvalidArgumentException('Template too long'); }
        // Limit Twig to scalar interpolation and case filters. Sandbox alone does not
        // stop resource-heavy expressions such as a billion-element range literal.
        $literal = preg_replace('/\{\{\s*[A-Za-z][A-Za-z0-9_]*\s*(?:\|\s*(?:upper|lower)\s*)*\}\}/u', '', $source);
        if ($literal === null || str_contains($literal, '{{') || str_contains($literal, '}}') || str_contains($source, '{%') || str_contains($source, '{#')) { throw new \InvalidArgumentException('Only scalar Twig interpolation with upper/lower filters is allowed'); }
        $safe = [];
        foreach ($allowed as $name) { if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name)) { throw new \InvalidArgumentException('Invalid variable name'); } if (isset($variables[$name]) && !is_scalar($variables[$name])) { throw new \InvalidArgumentException('Only scalar variables allowed'); } if (array_key_exists($name, $variables)) { $safe[$name] = $variables[$name]; } }
        $twig = new Environment(new ArrayLoader(['sms' => $source]), ['autoescape' => false, 'strict_variables' => true, 'cache' => false]);
        $twig->addExtension(new SandboxExtension(new SecurityPolicy([], ['default', 'upper', 'lower'], [], [], []), true));
        // No control-flow, includes, functions, object attributes or global services.
        $body = $twig->render('sms', $safe);
        if (trim($body) === '' || strlen($body) > 6000) { throw new \InvalidArgumentException('Rendered SMS is empty or too long'); }
        return $body;
    }
}
