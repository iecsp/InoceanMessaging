<?php declare(strict_types=1);
namespace Inocean\Messaging\Content;
use Shopware\Core\Framework\DataAbstractionLayer\{EntityTranslationDefinition, FieldCollection};
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{ApiAware,Required};
final class TemplateTranslationDefinition extends EntityTranslationDefinition
{
    public function getEntityName(): string { return 'ino_sms_template_translation'; }
    protected function getParentDefinitionClass(): string { return TemplateDefinition::class; }
    protected function defineFields(): FieldCollection { return new FieldCollection([(new LongTextField('body','body'))->addFlags(new ApiAware(), new Required())]); }
}
