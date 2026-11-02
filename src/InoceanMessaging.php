<?php declare(strict_types=1);
namespace Inocean\Messaging;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\{InstallContext, UpdateContext};
use Inocean\Messaging\Service\CustomerCustomFieldInstaller;
final class InoceanMessaging extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        (new CustomerCustomFieldInstaller($this->container->get('custom_field_set.repository')))->install($installContext->getContext());
    }
    public function update(UpdateContext $updateContext): void
    {
        (new CustomerCustomFieldInstaller($this->container->get('custom_field_set.repository')))->install($updateContext->getContext());
    }
}
