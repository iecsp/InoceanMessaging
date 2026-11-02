<?php declare(strict_types=1);
namespace Inocean\Messaging\Content;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\{IdField,StringField,IntField,BoolField,JsonField,LongTextField,TranslatedField,TranslationsAssociationField};
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{PrimaryKey,Required,ApiAware,SearchRanking};
final class TemplateDefinition extends EntityDefinition
{
    public function getEntityName(): string { return 'ino_sms_template'; }
    protected function defineFields(): FieldCollection { return new FieldCollection([
            (new IdField('id','id'))->addFlags(new PrimaryKey(), new Required(), new ApiAware()),
            (new IdField('scope_id', 'scopeId'))->addFlags(new ApiAware()),
            (new StringField('code', 'code'))->addFlags(new ApiAware(),new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),
            (new StringField('purpose', 'purpose'))->addFlags(new ApiAware()),
            (new BoolField('active', 'active'))->addFlags(new ApiAware()),
            (new JsonField('allowed_variables', 'allowedVariables'))->addFlags(new ApiAware()),
            (new TranslatedField('body'))->addFlags(new ApiAware()),
            (new TranslationsAssociationField(TemplateTranslationDefinition::class, 'ino_sms_template_id'))->addFlags(new ApiAware(), new Required()),
    ]); }
}
