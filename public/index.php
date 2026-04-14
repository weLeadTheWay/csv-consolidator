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

$controller = new CsvController();
$result = $controller->handleRequest();

/**
 * Move ONLY data preparation here
 */
$totalPages  = $result['totalPages'] ?? 0;
$currentPage = $result['page'] ?? 1;
$fileId      = $result['fileId'] ?? null;

/**
 * Pagination function stays here (logic layer)
 */
function generatePagination($currentPage, $totalPages, $fileId) {
    if ($totalPages <= 1) return '';

    $links = [];

    if ($currentPage > 1) {
        $links[] = '<li class="page-item"><a class="page-link" href="?file=' .
            htmlspecialchars($fileId) . '&page=' . ($currentPage - 1) .
            '">&laquo;</a></li>';
    }

    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i == $currentPage ? ' active' : '';
        $links[] = '<li class="page-item' . $active . '">
            <a class="page-link" href="?file=' . htmlspecialchars($fileId) . '&page=' . $i . '">' . $i . '</a>
        </li>';
    }

    if ($currentPage < $totalPages) {
        $links[] = '<li class="page-item"><a class="page-link" href="?file=' .
            htmlspecialchars($fileId) . '&page=' . ($currentPage + 1) .
            '">&raquo;</a></li>';
    }

    return implode('', $links);
}

/**
 * PASS everything to the view
 */
require __DIR__ . '/../views/home.php';