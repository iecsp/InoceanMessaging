<?php declare(strict_types=1);

namespace Inocean\Messaging\Migration;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
final class Migration1793491200CustomerMessaging extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1793491200; }
    public function update(Connection $connection): void
    {
        if (!$connection->createSchemaManager()->introspectTable('ino_sms_message')->hasColumn('customer_id')) {
            $connection->executeStatement('ALTER TABLE ino_sms_message ADD COLUMN customer_id BINARY(16) NULL, ADD INDEX idx_sms_customer (customer_id)');
        }
    }
    public function updateDestructive(Connection $connection): void {}
}
