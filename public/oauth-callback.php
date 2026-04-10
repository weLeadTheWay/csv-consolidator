<?php

require_once __DIR__ . '/../app/Services/GoogleSheetService.php';

$google = new GoogleSheetService();
$google->handleCallback();

header("Location: index.php");
exit;