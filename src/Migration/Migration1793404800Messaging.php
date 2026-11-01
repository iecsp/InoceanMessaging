<?php declare(strict_types=1);
namespace Inocean\Messaging\Migration;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
final class Migration1793404800Messaging extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1793404800; }
    public function update(Connection $connection): void {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ino_sms_message` (`id` BINARY(16) NOT NULL, `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NULL, `scope_id` BINARY(16) NOT NULL, `phone_hash` CHAR(64) NOT NULL, `phone_cipher` TEXT NULL, `body_cipher` TEXT NULL, `purpose` VARCHAR(32) NOT NULL, `template_code` VARCHAR(100) NULL, `idempotency_key` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL, `fingerprint` CHAR(64) NOT NULL, `status` VARCHAR(32) NOT NULL, `provider` VARCHAR(16) NULL, `provider_id` VARCHAR(128) NULL, `segments` INT NOT NULL, `attempt_count` INT NOT NULL DEFAULT 0, `next_at` DATETIME(3) NOT NULL, `quota_month` CHAR(7) NULL, `error_code` VARCHAR(80) NULL, `cost` DECIMAL(18,8) NULL, `currency` VARCHAR(3) NULL, `provider_event_at` DATETIME(3) NULL, UNIQUE KEY `uniq_sms_idempotency` (`scope_id`,`idempotency_key`), UNIQUE KEY `uniq_sms_provider` (`provider`,`provider_id`), KEY `idx_sms_quota` (`scope_id`,`quota_month`,`status`), KEY `idx_sms_outbox` (`status`,`next_at`), KEY `idx_sms_scope` (`scope_id`,`created_at`), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ino_sms_attempt` (`id` BINARY(16) NOT NULL, `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NULL, `message_id` BINARY(16) NOT NULL, `provider` VARCHAR(16) NOT NULL, `status` VARCHAR(32) NOT NULL, `provider_id` VARCHAR(128) NULL, `error_code` VARCHAR(80) NULL, CONSTRAINT `fk_sms_attempt_message` FOREIGN KEY (`message_id`) REFERENCES `ino_sms_message` (`id`) ON DELETE CASCADE, KEY `idx_sms_attempt_message` (`message_id`), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ino_sms_consent` (`id` BINARY(16) NOT NULL, `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NULL, `scope_id` BINARY(16) NOT NULL, `phone_hash` CHAR(64) NOT NULL, `sequence` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE, `type` VARCHAR(40) NOT NULL, `source` VARCHAR(128) NOT NULL, `evidence` TEXT NOT NULL, `expires_at` DATETIME(3) NULL, KEY `idx_sms_consent_lookup` (`scope_id`,`phone_hash`,`created_at`), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ino_sms_suppression` (`id` BINARY(16) NOT NULL, `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NULL, `scope_id` BINARY(16) NOT NULL, `phone_hash` CHAR(64) NOT NULL, `purpose` VARCHAR(32) NOT NULL, `provider` VARCHAR(16) NOT NULL DEFAULT '', `active` TINYINT(1) NOT NULL DEFAULT 1, `event_at` DATETIME(3) NOT NULL, `source` VARCHAR(128) NOT NULL, UNIQUE KEY `uniq_sms_suppression` (`scope_id`,`phone_hash`,`purpose`,`provider`), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ino_sms_webhook_event` (`id` BINARY(16) NOT NULL, `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NULL, `provider` VARCHAR(16) NOT NULL, `event_key` CHAR(64) NOT NULL, `kind` VARCHAR(40) NOT NULL, `message_id` BINARY(16) NULL, UNIQUE KEY `uniq_sms_event` (`provider`,`event_key`), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ino_sms_template` (`id` BINARY(16) NOT NULL, `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NULL, `scope_id` BINARY(16) NOT NULL, `code` VARCHAR(100) NOT NULL, `purpose` VARCHAR(32) NOT NULL, `active` TINYINT(1) NOT NULL DEFAULT 1, `allowed_variables` JSON NOT NULL, UNIQUE KEY `uniq_sms_template` (`scope_id`,`code`), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ino_sms_template_translation` (`ino_sms_template_id` BINARY(16) NOT NULL, `language_id` BINARY(16) NOT NULL, `body` LONGTEXT NOT NULL, `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NULL, PRIMARY KEY (`ino_sms_template_id`,`language_id`), CONSTRAINT `fk_sms_translation_template` FOREIGN KEY (`ino_sms_template_id`) REFERENCES `ino_sms_template` (`id`) ON DELETE CASCADE, CONSTRAINT `fk_sms_translation_language` FOREIGN KEY (`language_id`) REFERENCES `language` (`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
    public function updateDestructive(Connection $connection): void {}
}
