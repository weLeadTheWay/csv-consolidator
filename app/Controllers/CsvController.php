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
    /*
    private function generateSiDataId(int $length = 8): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $id;
    }
    */

    private function validateHeaders(array $headers, string $type): array
    {
        $headers = array_map('trim', $headers);

        $salesRequired = [
            'Document No',
            'Status',
            'Total Amount',
            'Customer Code',
            'Customer Name',
            'Business Center',
            'Division',
            'Profit Center'
        ];

        $delconRequired = [
            'SI Number',
            'Unit Price',
            'Secondary Quantity',
            'Secondary UOM',
            'Receipt Qty',
            'Receipt Kilos',
            'Return Qty'
        ];

        $required = $type === 'delcon' ? $delconRequired : $salesRequired;

        $missing = array_diff($required, $headers);
        $extra   = array_diff($headers, $required);

        return [
            'valid' => empty($missing),
            'missing' => array_values($missing),
            'extra' => array_values($extra)
        ];
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

            // ✅ VALIDATE HEADER IMMEDIATELY
            $file = new SplFileObject($path, 'r');
            $file->setFlags(SplFileObject::READ_CSV);

            $headers = $file->fgetcsv();

            $validation = $this->validateHeaders($headers, $type);

            // ❌ IF INVALID → DO NOT PROCEED
            if (!$validation['valid']) {

                // delete bad file (important)
                unlink($path);

                return [
                    'error' => 'Invalid CSV Template',
                    'validation' => $validation
                ];
            }

            // ✔ IF VALID → continue
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

            $type = $_GET['type'] ?? 'sales';

            if ($type === 'delcon') {
                $result = $this->upsertDelcon($filePath);
            } else {
                $result = $this->upsertSmart($filePath);
            }

            ob_clean();
            header('Content-Type: application/json');

            echo json_encode([
                'success' => true,
                'processed' => $result['processed'] ?? 0,
                'changed_count' => $result['changed_count'] ?? 0,
                'total' => $result['total'] ?? 0
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

        $file = new SplFileObject($filePath, 'r');
        $file->setFlags(SplFileObject::READ_CSV);

        $headers = $file->fgetcsv();
        $type = $_GET['type'] ?? 'sales';

        $validation = $this->validateHeaders($headers, $type);

        $data = [];

        $file->seek($offset + 1);

        for ($i = 0; $i < $limit && !$file->eof(); $i++) {

            $row = $file->fgetcsv();

            if (!$row || $row === [null]) continue;

            if (count($row) !== count($headers)) {
                $row = array_pad($row, count($headers), null);
            }

            $data[] = array_combine($headers, $row);
        }

        return [
            'fileId' => $fileId,
            'validation' => $validation,
            'data' => $data
        ];
    }

    /*
    ======================
    SMART UPSERT SALES TRACKING LIST (BATCHED)
    ======================
    */
    public function upsertSmart(string $filePath): array
    {
        date_default_timezone_set('Asia/Manila');
        $now = date('Y-m-d H:i:s');

        if (!file_exists($filePath)) {
            return [
                'status' => 'FAILED',
                'message' => 'File not found'
            ];
        }

        $handle = fopen($filePath, "r");
        $headers = fgetcsv($handle);

        $batch = [];
        $batchSize = 500;

        $processedRows = 0;
        $skippedRows = 0;

        $changedDocs = [];
        $existing = [];

        $stmt = $this->db->query("
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
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = trim((string)$row['Document No']);
            $existing[$key] = $row;
        }

        while (($row = fgetcsv($handle)) !== false) {

            $row = array_combine($headers, array_pad($row, count($headers), null));

            if (!$row || empty($row['Document No'])) {
                $skippedRows++;
                continue;
            }

            $row = array_map(fn($val) =>
                is_string($val) ? trim($val) : $val,
            $row);

            $batch[] = $row;

            if (count($batch) === $batchSize) {
                $result = $this->processBatch($batch, $now, $changedDocs, $existing);
                $processedRows += $result['processed'];
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $result = $this->processBatch($batch, $now, $changedDocs, $existing);
            $processedRows += $result['processed'];
        }

        fclose($handle);
        $countStmt = $this->db->query("SELECT COUNT(*) as total FROM sales_tracking_list");
        $dbCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $uniqueChanged = array_values(array_unique($changedDocs));

        return [
            'status' => 'SUCCESS',
            'processed' => $processedRows,
            'skipped' => $skippedRows,
            'changed_count' => count($uniqueChanged),
            'updated_docs' => $uniqueChanged,
            'total' => (int)$dbCount
        ];
    }

    /*
    ======================
    BATCH PROCESSOR SALES TRACKING LIST 
    ======================
    */
    private function processBatch(array $rows, string $now, array &$changedDocs, array &$existing): array
    {
        if (empty($rows)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $values = [];
        $params = [];
        $i = 0;

        foreach ($rows as $row) {

            // Normalize numeric fields BEFORE comparison
            $row['Total Amount'] = isset($row['Total Amount'])
                ? number_format((float)str_replace(',', '', $row['Total Amount']), 2, '.', '')
                : '0.00';

            $docNo = trim((string)($row['Document No'] ?? ''));
            if (!$docNo) continue;

            $values[] = "(
                :doc{$i},
                :status{$i},
                :amount{$i},
                :custCode{$i},
                :custName{$i},
                :biz{$i},
                :division{$i},
                :profit{$i},
                :added{$i},
                :updated{$i}
            )";

            $params[":doc{$i}"]      = $docNo;
            $params[":status{$i}"]   = $row['Status'] ?? null;
            $params[":amount{$i}"]   = $row['Total Amount'] ?? null;
            $params[":custCode{$i}"] = $row['Customer Code'] ?? null;
            $params[":custName{$i}"] = $row['Customer Name'] ?? null;
            $params[":biz{$i}"]      = $row['Business Center'] ?? null;
            $params[":division{$i}"] = $row['Division'] ?? null;
            $params[":profit{$i}"]   = $row['Profit Center'] ?? null;
            $params[":added{$i}"]    = $now;
            $params[":updated{$i}"]  = $now;

            $dbRow = $existing[$docNo] ?? null;

            $normalize = function ($val) {
                if ($val === null || $val === '') return '';
                return trim((string)$val);
            };

            $isChanged = false;

            if ($dbRow) {
                if (
                    $normalize($dbRow['Status']) !== $normalize($row['Status']) ||
                    $normalize($dbRow['Total Amount']) !== $normalize($row['Total Amount']) ||
                    $normalize($dbRow['Customer Code']) !== $normalize($row['Customer Code']) ||
                    $normalize($dbRow['Customer Name']) !== $normalize($row['Customer Name']) ||
                    $normalize($dbRow['Business Center']) !== $normalize($row['Business Center']) ||
                    $normalize($dbRow['Division']) !== $normalize($row['Division']) ||
                    $normalize($dbRow['Profit Center']) !== $normalize($row['Profit Center'])
                ) {
                    $isChanged = true;
                }
            } else {
                $isChanged = true;
            }

            if ($isChanged) {
                $changedDocs[$docNo] = $docNo;
            }

            if ($isChanged && count($changedDocs) < 20) {
                error_log("CHANGED: " . $docNo);
            }

            $existing[$docNo] = [
                'Document No'   => $docNo,
                'Status'        => $row['Status'] ?? null,
                'Total Amount'  => $row['Total Amount'] ?? null,
                'Customer Code' => $row['Customer Code'] ?? null,
                'Customer Name' => $row['Customer Name'] ?? null,
                'Business Center'=> $row['Business Center'] ?? null,
                'Division'      => $row['Division'] ?? null,
                'Profit Center' => $row['Profit Center'] ?? null
            ];

            $i++;
        }

        if (empty($values)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $sql = "
            INSERT INTO sales_tracking_list (
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
            VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
                `Status`          = VALUES(`Status`),
                `Total Amount`    = VALUES(`Total Amount`),
                `Customer Code`   = VALUES(`Customer Code`),
                `Customer Name`   = VALUES(`Customer Name`),
                `Business Center` = VALUES(`Business Center`),
                `Division`        = VALUES(`Division`),
                `Profit Center`   = VALUES(`Profit Center`)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'processed' => $i
        ];
    }


    private function fetchExistingRowsOptimized(array $docNos): array
    {
        if (empty($docNos)) return [];

        $placeholders = implode(',', array_fill(0, count($docNos), '?'));

        $stmt = $this->db->prepare("
            SELECT 
                si_data_id,
                `Document No`,
                data_hash,
                date_added
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

    /*
    ======================
    DELCON UPSERT (BATCHED)
    ======================
    */
    public function upsertDelcon(string $filePath): array
    {
        date_default_timezone_set('Asia/Manila');
        $now = date('Y-m-d H:i:s');

        $inserted = 0;

        if (!file_exists($filePath)) {
            return ['inserted' => $inserted];
        }

        $handle = fopen($filePath, "r");
        $headers = fgetcsv($handle);

        $batch = [];
        $batchSize = 500;

        while (($row = fgetcsv($handle)) !== false) {

            $row = array_combine($headers, array_pad($row, count($headers), null));

            if (!$row) continue;

            // Normalize: trim all string values
            $row = array_map(function ($val) {
                return is_string($val) ? trim($val) : $val;
            }, $row);

            // Encode normalization
            $row = array_map(function ($val) {
                if (!is_string($val)) return $val;

                $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                $val = preg_replace('/[\x00-\x1F\x7F]/u', '', $val);

                return $val;
            }, $row);

            $batch[] = $row;

            if (count($batch) === $batchSize) {
                $result = $this->processDelconBatch($batch, $now);
                $inserted += $result['inserted'];
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $result = $this->processDelconBatch($batch, $now);
            $inserted += $result['inserted'];
        }

        fclose($handle);

        return [
            'status' => 'SUCCESS',
            'processed' => $inserted,
            'changed_count' => 0,
            'total' => 0
        ];
    }

    /*
    ======================
    DELCON BATCH PROCESSOR
    ======================
    */
    private function processDelconBatch(array $rows, string $now): array
    {
        $values = [];
        $params = [];

        $inserted = 0;
        $i = 0;

        foreach ($rows as $row) {

            // Extract required fields
            $siNumber = $row['SI Number'] ?? null;
            
            // Skip rows without SI Number (required for database constraint)
            if (!$siNumber) {
                continue;
            }
            
            $unitPrice = $row['Unit Price'] ?? null;
            $secondaryQty = $row['Secondary Quantity'] ?? null;
            $secondaryUom = $row['Secondary UOM'] ?? null;
            $receiptQty = $row['Receipt Qty'] ?? null;
            $receiptKilos = $row['Receipt Kilos'] ?? null;
            $returnQty = $row['Return Qty'] ?? null;

            // Convert numeric values
            if ($unitPrice !== null) {
                $unitPrice = (float)str_replace(',', '', (string)$unitPrice);
            }
            if ($secondaryQty !== null) {
                $secondaryQty = (int)$secondaryQty;
            }
            if ($receiptQty !== null) {
                $receiptQty = (int)$receiptQty;
            }
            if ($receiptKilos !== null) {
                $receiptKilos = (int)$receiptKilos;
            }
            if ($returnQty !== null) {
                $returnQty = (int)$returnQty;
            }

            // BUILD INSERT VALUE SET
            $values[] = "(
                :delconId{$i},
                :siNum{$i},
                :unitPrice{$i},
                :secQty{$i},
                :secUom{$i},
                :recQty{$i},
                :recKilos{$i},
                :retQty{$i},
                :added{$i}
            )";

            $params[":delconId{$i}"]  = $this->generateDelconId();
            $params[":siNum{$i}"]     = $siNumber;
            $params[":unitPrice{$i}"] = $unitPrice;
            $params[":secQty{$i}"]    = $secondaryQty;
            $params[":secUom{$i}"]    = $secondaryUom;
            $params[":recQty{$i}"]    = $receiptQty;
            $params[":recKilos{$i}"]  = $receiptKilos;
            $params[":retQty{$i}"]    = $returnQty;
            $params[":added{$i}"]     = $now;

            $inserted++;
            $i++;
        }

        // Batch insert all rows
        if (!empty($values)) {

            $sql = "
                INSERT INTO delcon_si (
                    delcon_data_id,
                    `SI Number`,
                    `Unit Price`,
                    `Secondary Quantity`,
                    `Secondary UOM`,
                    `Receipt Qty`,
                    `Receipt Kilos`,
                    `Return Qty`,
                    date_added
                )
                VALUES " . implode(',', $values);

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        return ['inserted' => $inserted];
    }

    /*
    ======================
    DELCON HELPER: Generate Delcon Data ID
    ======================
    */
    private function generateDelconId(int $length = 12): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $id;
    }
}