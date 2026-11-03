<?php declare(strict_types=1);

namespace Inocean\Messaging\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1793664000AdmissionAndConsent extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1793664000; }
    public function update(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->introspectTable('ino_sms_consent')->hasColumn('customer_id')) {
            $connection->executeStatement('ALTER TABLE ino_sms_consent ADD customer_id BINARY(16) NULL, ADD INDEX idx_sms_consent_customer (customer_id)');
        }
        if (!$schema->introspectTable('ino_sms_message')->hasIndex('idx_sms_admission')) {
            $connection->executeStatement('ALTER TABLE ino_sms_message ADD INDEX idx_sms_admission (scope_id,status)');
        }
    }
    public function updateDestructive(Connection $connection): void {}
}
