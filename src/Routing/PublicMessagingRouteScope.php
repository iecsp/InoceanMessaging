<?php declare(strict_types=1);

namespace Inocean\Messaging\Routing;

use Shopware\Core\Framework\Routing\AbstractRouteScope;
use Symfony\Component\HttpFoundation\Request;

/** Public capabilities authenticate themselves; they must work on headless hosts without storefront domains. */
final class PublicMessagingRouteScope extends AbstractRouteScope
{
    public function getId(): string { return 'ino-messaging-public'; }
    public function getRoutePrefixes(): array { return ['inocean/messaging']; }
    public function isAllowedPath(string $path): bool
    {
        return str_starts_with($path, '/inocean/messaging/unsubscribe/')
            || in_array($path, ['/inocean/messaging/webhook/telnyx', '/inocean/messaging/webhook/plivo'], true);
    }
    public function isAllowed(Request $request): bool { return $this->isAllowedPath($request->getPathInfo()); }
}
