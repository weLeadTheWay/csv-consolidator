<?php

//LOAD ENV FIRST
foreach (file(__DIR__ . '/../.env') as $line) {
    if (trim($line) === '' || str_starts_with(trim($line), '#')) continue;
    [$key, $value] = explode('=', trim($line), 2);
    putenv("$key=$value");
}

require_once __DIR__ . '/../app/Services/CsvParser.php';
require_once __DIR__ . '/../app/Controllers/CsvController.php';
require_once __DIR__ . '/../app/Services/DatabaseMigration.php';

// Run database migrations once at startup
static $migrationsRun = false;
if (!$migrationsRun) {
    $migration = new DatabaseMigration();
    $migration->runAllMigrations();
    $migrationsRun = true;
}

$source = $_GET['source'] ?? 'csv';

switch ($source) {
    case 'bank_customer':
        require_once __DIR__ . '/../app/Controllers/GoogleSheetBankCustomerController.php';
        $controller = new GoogleSheetBankCustomerController();
        $result = $controller->handleRequest();
        break;

    case 'csv':
    default:
        require_once __DIR__ . '/../app/Controllers/CsvController.php';
        $controller = new CsvController();
        $result = $controller->handleRequest();
        break;
}

if ($source === 'bank_customer') {
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

$fileId      = $result['fileId'] ?? null;

require __DIR__ . '/../views/home.php';