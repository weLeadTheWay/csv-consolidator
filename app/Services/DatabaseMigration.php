<?php

require_once __DIR__ . '/../Config/Database.php';

/**
 * Database Migration Helper
 * Ensures required columns exist in tables for optimized operations
 */
class DatabaseMigration
{
    private PDO $db;
    private bool $isCli;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();

        // Detect if running in CLI
        $this->isCli = (php_sapi_name() === 'cli');
    }

    /**
     * Unified logger
     * - CLI: prints output
     * - Web: logs to error log (no UI output)
     */
    private function log(string $message): void
    {
        if ($this->isCli) {
            echo $message . PHP_EOL;
        } else {
            error_log($message);
        }
    }

    /**
     * Migrate sales_tracking_list table
     * Adds data_hash column if it doesn't exist
     */
    public function migrateSalesTrackingList(): bool
    {
        try {
            // Check if data_hash column exists
            $stmt = $this->db->query("
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'sales_tracking_list'
                AND COLUMN_NAME = 'data_hash'
            ");

            if ($stmt->rowCount() === 0) {

                // Add column + index
                $this->db->exec("
                    ALTER TABLE sales_tracking_list
                    ADD COLUMN data_hash VARCHAR(32) NULL AFTER `Profit Center`,
                    ADD INDEX idx_data_hash (data_hash)
                ");

                $this->log("[✓] Added data_hash column to sales_tracking_list");
                return true;

            } else {
                $this->log("[✓] data_hash column already exists");
                return true;
            }

        } catch (PDOException $e) {
            $this->log("[✗] Migration failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Run all migrations
     */
    public function runAllMigrations(): bool
    {
        $this->log("=== Starting Database Migrations ===");

        $success = true;

        $success = $this->migrateSalesTrackingList() && $success;

        $this->log("=== Migrations Complete ===");

        return $success;
    }
}

/**
 * Run migrations only if executed directly via CLI
 */
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['argv'][0] ?? '')) {
    $migration = new DatabaseMigration();
    $migration->runAllMigrations();
}