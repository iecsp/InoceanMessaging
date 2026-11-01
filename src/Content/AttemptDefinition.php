<?php declare(strict_types=1);
namespace Inocean\Messaging\Content;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\{DateTimeField,IdField,StringField,IntField,BoolField,JsonField,LongTextField,TranslatedField,TranslationsAssociationField};
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{PrimaryKey,Required,ApiAware};
final class AttemptDefinition extends OperationalDefinition
{
    public function getEntityName(): string { return 'ino_sms_attempt'; }
    protected function defineFields(): FieldCollection { return new FieldCollection([
            (new IdField('id','id'))->addFlags(new PrimaryKey(), new Required()),
            (new IdField('message_id', 'messageId')),
            (new StringField('provider', 'provider')),
            (new StringField('status', 'status')),
            (new StringField('error_code', 'errorCode')),
            new StringField('provider_id','providerId'),
    ]); }
}
