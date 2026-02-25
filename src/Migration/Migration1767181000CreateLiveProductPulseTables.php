<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1767181000CreateLiveProductPulseTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767181000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `fib_live_product_pulse_cart_reservation` (
                `cart_token_hash` BINARY(32) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `quantity` INT UNSIGNED NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`cart_token_hash`, `product_id`),
                KEY `idx.fib_live_product_pulse_reservation.product_updated` (`product_id`, `updated_at`),
                KEY `idx.fib_live_product_pulse_reservation.updated` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `fib_live_product_pulse_viewer_presence` (
                `product_id` BINARY(16) NOT NULL,
                `viewer_token_hash` BINARY(32) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `last_seen_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_id`, `viewer_token_hash`),
                KEY `idx.fib_live_product_pulse_viewer.product_last_seen` (`product_id`, `last_seen_at`),
                KEY `idx.fib_live_product_pulse_viewer.last_seen` (`last_seen_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
