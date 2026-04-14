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
    
    // Generate unique IF for si_data_id
    private function generateSiDataId(int $length = 8): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $id;
    }

    private function fetchExistingRows(array $docNos): array
    {
        if (empty($docNos)) return [];

        $placeholders = implode(',', array_fill(0, count($docNos), '?'));

        $stmt = $this->db->prepare("
            SELECT 
                `Document No`,
                `Status`,
                `Total Amount`,
                `Customer Code`,
                `Customer Name`,
                `Business Center`,
                `Division`,
                `Profit Center`
            FROM sales_tracking_list
            WHERE `Document No` IN ($placeholders)
        ");

        $stmt->execute($docNos);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['Document No']] = $row;
        }

        return $result;
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

            $type = $_POST['type'] ?? 'sales';

            header("Location: index.php?file=$fileId&page=1&type=$type");
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

        /*
        ======================
        PREVIEW PAGINATION
        ======================
        */
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
    SMART UPSERT (BATCHED)
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

            if (!$row || !isset($row['Document No'])) continue;

            $row = array_map(function ($val) {
                return is_string($val) ? trim($val) : $val;
            }, $row);

            $batch[] = $row;

            if (count($batch) === $batchSize) {
                $result = $this->processBatch($batch, $now);
                $inserted += $result['inserted'];
                $updated += $result['updated'];
                $batch = [];
            }
        }

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

    /*
    ======================
    BATCH PROCESSOR
    ======================
    */
    private function processBatch(array $rows, string $now): array
    {
        $values = [];
        $params = [];

        $docNos = [];
        $cleanRows = [];

        foreach ($rows as $row) {

            $docNo = $row['Document No'] ?? null;
            if (!$docNo) continue;

            // ✅ FIX 3: normalize CSV values here (TRIM + consistency)
            $row = array_map(function ($val) {
                return is_string($val) ? trim($val) : $val;
            }, $row);

            $row = array_map(function ($val) {
                if (!is_string($val)) return $val;

                // convert broken encodings to valid UTF-8
                $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');

                // remove control characters
                $val = preg_replace('/[\x00-\x1F\x7F]/u', '', $val);

                return $val;
            }, $row);

            // ensure missing keys become null (important for safe comparison)
            $row['Status'] = $row['Status'] ?? null;

            $docNos[] = $docNo;
            $cleanRows[$docNo] = $row;
        }

        $existing = $this->fetchExistingRows($docNos);

        $inserted = 0;
        $updated = 0;

        $i = 0;

        foreach ($cleanRows as $docNo => $row) {

            $isNew = !isset($existing[$docNo]);

            $hasChanged = false;

            if (!$isNew) {
                $old = $existing[$docNo];

                $hasChanged =
                    ($old['Status'] ?? null) != ($row['Status'] ?? null) ||
                    ($old['Total Amount'] ?? null) != ($row['Total Amount'] ?? null) ||
                    ($old['Customer Code'] ?? null) != ($row['Customer Code'] ?? null) ||
                    ($old['Customer Name'] ?? null) != ($row['Customer Name'] ?? null) ||
                    ($old['Business Center'] ?? null) != ($row['Business Center'] ?? null) ||
                    ($old['Division'] ?? null) != ($row['Division'] ?? null) ||
                    ($old['Profit Center'] ?? null) != ($row['Profit Center'] ?? null);
            }

            if ($isNew) {
                // INSERT
                $values[] = "(
                    :si{$i},
                    :doc{$i},
                    :status{$i},
                    :amount{$i},
                    :custCode{$i},
                    :custName{$i},
                    :biz{$i},
                    :division{$i},
                    :profit{$i},
                    :added{$i},
                    NULL
                )";

                $params[":si{$i}"]       = $this->generateSiDataId();
                $params[":doc{$i}"]      = $docNo;
                $params[":status{$i}"] = $row['Status'] ?? null;
                $params[":amount{$i}"]   = $row['Total Amount'] ?? null;
                $params[":custCode{$i}"] = $row['Customer Code'] ?? null;
                $params[":custName{$i}"] = $row['Customer Name'] ?? null;
                $params[":biz{$i}"]      = $row['Business Center'] ?? null;
                $params[":division{$i}"] = $row['Division'] ?? null;
                $params[":profit{$i}"]   = $row['Profit Center'] ?? null;
                $params[":added{$i}"]    = $now;

                $inserted++;
                $i++;

            } elseif ($hasChanged) {
                // UPDATE
                $stmt = $this->db->prepare("
                    UPDATE sales_tracking_list
                    SET 
                        `Status` = ?,
                        `Total Amount` = ?,
                        `Customer Code` = ?,
                        `Customer Name` = ?,
                        `Business Center` = ?,
                        `Division` = ?,
                        `Profit Center` = ?,
                        date_last_update = ?
                    WHERE `Document No` = ?
                ");

                $stmt->execute([
                    $row['Status'] ?? null,
                    $row['Total Amount'] ?? null,
                    $row['Customer Code'] ?? null,
                    $row['Customer Name'] ?? null,
                    $row['Business Center'] ?? null,
                    $row['Division'] ?? null,
                    $row['Profit Center'] ?? null,
                    $now,
                    $docNo
                ]);

                $updated++;
            }
        }

        // batch insert new rows only
        if (!empty($values)) {

            $sql = "
                INSERT INTO sales_tracking_list (
                    si_data_id,
                    `Document No`,
                    `Status`,
                    `Total Amount`,
                    `Customer Code`,
                    `Customer Name`,
                    `Business Center`,
                    `Division`,
                    `Profit Center`,
                    date_added,
                    date_last_update
                )
                VALUES " . implode(',', $values);

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated
        ];
    }
}