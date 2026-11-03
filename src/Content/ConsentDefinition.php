<?php declare(strict_types=1);
namespace Inocean\Messaging\Content;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\{DateTimeField,IdField,StringField,IntField,BoolField,JsonField,LongTextField,TranslatedField,TranslationsAssociationField};
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{PrimaryKey,Required,ApiAware};
final class ConsentDefinition extends OperationalDefinition
{
    public function getEntityName(): string { return 'ino_sms_consent'; }
    protected function defineFields(): FieldCollection { return new FieldCollection([
            (new IdField('id','id'))->addFlags(new PrimaryKey(), new Required()),
            (new IdField('scope_id', 'scopeId')),
            (new StringField('phone_hash', 'phoneHash')),
            (new StringField('type', 'type')),
            (new StringField('source', 'source')),
            (new LongTextField('evidence', 'evidence')),
            new DateTimeField('expires_at','expiresAt'),
            new IntField('sequence','sequence'),
            new IdField('customer_id','customerId'),
    ]); }
}
