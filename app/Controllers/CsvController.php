<?php

require_once __DIR__ . '/../Services/CsvParser.php';

class CsvController
{
    private CsvParser $parser;

    public function __construct()
    {
        $this->parser = new CsvParser();
    }

    public function handleRequest(): array
    {
        $fileId = $_GET['file'] ?? null;

        // =========================
        // 1. HANDLE UPLOAD
        // =========================
        if (isset($_FILES['csv_file'])) {

            if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                return ['error' => 'File upload failed'];
            }

            $fileExtension = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

            if ($fileExtension !== 'csv') {
                return ['error' => 'Only CSV files are allowed'];
            }

            // create unique file id
            $fileId = uniqid('csv_');

            $uploadPath = __DIR__ . '/../../uploads/' . $fileId . '.csv';

            move_uploaded_file($_FILES['csv_file']['tmp_name'], $uploadPath);

            // redirect to fresh page with file id
            header("Location: index.php?file=$fileId&page=1");
            exit;
        }

        // =========================
        // 2. IF NO FILE → FRESH APP
        // =========================
        if (!$fileId) {
            return [];
        }

        $filePath = __DIR__ . "/../../uploads/$fileId.csv";

        if (!file_exists($filePath)) {
            return ['error' => 'File not found'];
        }

        // =========================
        // 3. PAGINATION
        // =========================
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $handle = fopen($filePath, "r");

        $headers = fgetcsv($handle);

        $data = [];
        $rowIndex = 0;

        while (($row = fgetcsv($handle)) !== false) {

            if ($rowIndex >= $offset && count($data) < $limit) {
                $data[] = array_combine(
                    $headers,
                    array_pad($row, count($headers), null)
                );
            }

            $rowIndex++;

            if ($rowIndex >= $offset + $limit) {
                break;
            }
        }

        fclose($handle);

        // total rows (for pagination)
        $totalRows = 0;

        $h = fopen($filePath, "r");
        fgetcsv($h); // skip header

        while (fgetcsv($h) !== false) {
            $totalRows++;
        }

        fclose($h);
        $totalPages = ceil($totalRows / $limit);

        return [
            'data' => $data,
            'total' => $totalRows,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
            'fileId' => $fileId,
            'debug' => "Total rows: $totalRows | Total columns: " . count($headers)
        ];
    }
}