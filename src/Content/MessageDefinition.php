<?php declare(strict_types=1);
namespace Inocean\Messaging\Content;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\{DateTimeField,IdField,StringField,IntField,BoolField,JsonField,LongTextField,TranslatedField,TranslationsAssociationField};
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{PrimaryKey,Required,ApiAware};
final class MessageDefinition extends OperationalDefinition
{
    public function getEntityName(): string { return 'ino_sms_message'; }
    protected function defineFields(): FieldCollection { return new FieldCollection([
            (new IdField('id','id'))->addFlags(new PrimaryKey(), new Required()),
            (new IdField('scope_id', 'scopeId')),
            (new StringField('phone_hash', 'phoneHash')),
            (new StringField('status', 'status')),
            (new StringField('purpose', 'purpose')),
            (new StringField('provider', 'provider')),
            (new StringField('provider_id', 'providerId')),
            (new IntField('segments', 'segments')),
            (new StringField('error_code', 'errorCode')),
            new LongTextField('phone_cipher','phoneCipher'),
            new LongTextField('body_cipher','bodyCipher'),
            new StringField('template_code','templateCode'),
            new StringField('idempotency_key','idempotencyKey'),
            new StringField('fingerprint','fingerprint'),
            new IntField('attempt_count','attemptCount'),
            new DateTimeField('next_at','nextAt'),
            new StringField('cost','cost'),
            new StringField('quota_month','quotaMonth'),
            new StringField('currency','currency'),
            new DateTimeField('provider_event_at','providerEventAt'),
    ]); }
}
