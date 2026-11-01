<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Service;
use Inocean\Messaging\Core\Contract\{StoreAccessInterface, BusinessReferenceResolverInterface};
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
final class UnavailableIntegration implements StoreAccessInterface, BusinessReferenceResolverInterface
{
    public function authorize(Request $request, SalesChannelContext $context, string $permission): string { throw new AccessDeniedHttpException('POS identity/store integration is not installed'); }
    public function resolve(string $scope, Request $request, array $payload): array { throw new AccessDeniedHttpException('Trusted business record resolver is not installed'); }
}
