<?php declare(strict_types=1);
namespace Inocean\Messaging\Content;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\{DateTimeField,IdField,StringField,IntField,BoolField,JsonField,LongTextField,TranslatedField,TranslationsAssociationField};
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{PrimaryKey,Required,ApiAware};
final class SuppressionDefinition extends OperationalDefinition
{
    public function getEntityName(): string { return 'ino_sms_suppression'; }
    protected function defineFields(): FieldCollection { return new FieldCollection([
            (new IdField('id','id'))->addFlags(new PrimaryKey(), new Required()),
            (new IdField('scope_id', 'scopeId')),
            (new StringField('phone_hash', 'phoneHash')),
            (new StringField('purpose', 'purpose')),
            (new StringField('provider', 'provider')),
            (new BoolField('active', 'active')),
            (new StringField('source', 'source')),
            new DateTimeField('event_at','eventAt'),
    ]); }
}
