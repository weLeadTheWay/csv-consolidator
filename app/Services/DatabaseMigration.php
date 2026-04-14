<?php

require_once __DIR__ . '/../Config/Database.php';

/**
 * Database Migration Helper
 * Ensures required columns exist in tables for optimized operations
 */
class DatabaseMigration
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
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
                WHERE TABLE_NAME = 'sales_tracking_list' 
                AND COLUMN_NAME = 'data_hash'
            ");

            if ($stmt->rowCount() === 0) {
                // Add the column if it doesn't exist
                $this->db->exec("
                    ALTER TABLE sales_tracking_list
                    ADD COLUMN data_hash VARCHAR(32) NULL AFTER `Profit Center`,
                    ADD INDEX idx_data_hash (data_hash)
                ");

                echo "[✓] Added data_hash column to sales_tracking_list\n";
                return true;
            } else {
                echo "[✓] data_hash column already exists\n";
                return true;
            }
        } catch (PDOException $e) {
            echo "[✗] Migration failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Run all migrations
     */
    public function runAllMigrations(): bool
    {
        echo "=== Starting Database Migrations ===\n";
        
        $success = true;
        $success = $this->migrateSalesTrackingList() && $success;
        
        echo "=== Migrations Complete ===\n";
        return $success;
    }
}

// Run migrations if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['argv'][0] ?? '')) {
    $migration = new DatabaseMigration();
    $migration->runAllMigrations();
}
