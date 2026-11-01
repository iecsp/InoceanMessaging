<?php declare(strict_types=1);
namespace Inocean\Messaging\Content;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityProtection\{EntityProtectionCollection,ReadProtection,WriteProtection};
abstract class OperationalDefinition extends EntityDefinition
{
    protected function defineProtections(): EntityProtectionCollection { return new EntityProtectionCollection([new ReadProtection(Context::SYSTEM_SCOPE),new WriteProtection(Context::SYSTEM_SCOPE)]); }
}
