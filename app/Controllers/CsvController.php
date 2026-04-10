<?php

require_once __DIR__ . '/../Services/CsvParser.php';
require_once __DIR__ . '/../Config/Database.php';

class CsvController
{
    private CsvParser $parser;
    private PDO $db;

    public function __construct()
    {
        $this->parser = new CsvParser();

        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function handleRequest(): array
    {
        $fileId = $_GET['file'] ?? null;

        /*
        ======================
        UPLOAD
        ======================
        */
        if (isset($_FILES['csv_file'])) {

            if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                return ['error' => 'Upload failed'];
            }

            $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

            if ($ext !== 'csv') {
                return ['error' => 'Only CSV allowed'];
            }

            $fileId = uniqid('csv_');
            $path = __DIR__ . '/../../uploads/' . $fileId . '.csv';

            move_uploaded_file($_FILES['csv_file']['tmp_name'], $path);

            header("Location: index.php?file=$fileId&page=1");
            exit;
        }

        if (!$fileId) {
            return [];
        }

        $filePath = __DIR__ . "/../../uploads/$fileId.csv";

        if (!file_exists($filePath)) {
            return ['error' => 'File not found'];
        }

        /*
        ======================
        IMPORT TRIGGER
        ======================
        */
        if (isset($_GET['import']) && $_GET['import'] == 1) {

            $result = $this->upsertSmart($filePath);

            ob_clean();
            header('Content-Type: application/json');

            echo json_encode([
                'success' => true,
                'inserted' => $result['inserted'],
                'updated'  => $result['updated'],
                'total'    => $result['inserted'] + $result['updated']
            ]);

            exit;
        }

        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $handle = fopen($filePath, "r");
        $headers = fgetcsv($handle);

        $data = [];
        $i = 0;

        while (($row = fgetcsv($handle)) !== false) {

            if ($i >= $offset && count($data) < $limit) {
                $data[] = array_combine($headers, array_pad($row, count($headers), null));
            }

            $i++;

            if ($i >= $offset + $limit) break;
        }

        fclose($handle);

        return [
            'data' => $data,
            'fileId' => $fileId
        ];
    }

    /*
    ======================
    SMART UPSERT (FIXED)
    ======================
    */
    public function upsertSmart(string $filePath): array
    {
        date_default_timezone_set('Asia/Manila');
        $now = date('Y-m-d H:i:s');

        $inserted = 0;
        $updated = 0;

        if (!file_exists($filePath)) {
            return compact('inserted', 'updated');
        }

        $handle = fopen($filePath, "r");
        $headers = fgetcsv($handle);

        $batch = [];
        $batchSize = 500;

        while (($row = fgetcsv($handle)) !== false) {

            $row = array_combine($headers, array_pad($row, count($headers), null));
            if (!$row || !isset($row['employeeid'])) continue;

            $row = array_map('trim', $row);

            $batch[] = $row;

            if (count($batch) === $batchSize) {
                $result = $this->processBatch($batch, $now);
                $inserted += $result['inserted'];
                $updated += $result['updated'];
                $batch = [];
            }
        }

        // leftover rows
        if (!empty($batch)) {
            $result = $this->processBatch($batch, $now);
            $inserted += $result['inserted'];
            $updated += $result['updated'];
        }

        fclose($handle);

        return [
            'inserted' => $inserted,
            'updated' => $updated
        ];
    }
    
    private function processBatch(array $rows, string $now): array
{
    $inserted = 0;
    $updated = 0;

    $values = [];
    $params = [];

    foreach ($rows as $i => $row) {

        $values[] = "(
            :emp{$i},
            :name{$i},
            :age{$i},
            :work{$i},
            :address{$i},
            :uploaded{$i},
            :lastupdate{$i}
        )";

        $params[":emp{$i}"] = $row['employeeid'];
        $params[":name{$i}"] = $row['Name'];
        $params[":age{$i}"] = $row['Age'];
        $params[":work{$i}"] = $row['Work'];
        $params[":address{$i}"] = $row['Address'];
        $params[":uploaded{$i}"] = $now;
        $params[":lastupdate{$i}"] = null;
    }

    $sql = "
        INSERT INTO test_table (
            employeeid,
            Name,
            Age,
            Work,
            Address,
            date_uploaded,
            date_lastupdate
        )
        VALUES " . implode(',', $values) . "
        ON DUPLICATE KEY UPDATE
        date_lastupdate = IF(
            test_table.Name <> VALUES(Name)
            OR test_table.Age <> VALUES(Age)
            OR test_table.Work <> VALUES(Work)
            OR test_table.Address <> VALUES(Address),
            VALUES(date_uploaded),
            test_table.date_lastupdate
        ),
        Name = VALUES(Name),
        Age = VALUES(Age),
        Work = VALUES(Work),
        Address = VALUES(Address)
    ";

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    // Approximation (fast method)
    $total = count($rows);
    $affected = $stmt->rowCount();

    // MySQL behavior:
    // insert = 1
    // update (changed) = 2
    // no change = 0

    $updated = max(0, $affected - $total);
    $inserted = $total - $updated;

    return [
        'inserted' => $inserted,
        'updated' => $updated
    ];
}
}