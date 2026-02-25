<?php declare(strict_types=1);

namespace Fib\LiveProductPulse\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1767182000CreateCartPresenceTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767182000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
CREATE TABLE IF NOT EXISTS `fib_live_product_pulse_cart_presence` (
    `cart_token_hash` BINARY(32) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `last_seen_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`cart_token_hash`),
    KEY `idx.fib_live_product_pulse_cart_presence.last_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
