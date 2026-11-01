<?php declare(strict_types=1);
namespace Inocean\Messaging\Core\Contract;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
interface StoreAccessInterface
{
    /** Validate operator identity, permission, tenant/store membership and sales channel binding. Return canonical sales channel scope. Never trust client scope. */
    public function authorize(Request $request, SalesChannelContext $context, string $permission): string;
}
