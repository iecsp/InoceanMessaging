<?php declare(strict_types=1);
namespace Inocean\Messaging\Content;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\{DateTimeField,IdField,StringField,IntField,BoolField,JsonField,LongTextField,TranslatedField,TranslationsAssociationField};
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{PrimaryKey,Required,ApiAware};
final class WebhookEventDefinition extends OperationalDefinition
{
    public function getEntityName(): string { return 'ino_sms_webhook_event'; }
    protected function defineFields(): FieldCollection { return new FieldCollection([
            (new IdField('id','id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('provider', 'provider')),
            (new StringField('event_key', 'eventKey')),
            (new StringField('kind', 'kind')),
            new IdField('message_id','messageId'),
    ]); }
}
