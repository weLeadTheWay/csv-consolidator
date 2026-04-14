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

    /**
     * Generate MD5 hash of trackable fields for change detection
     * @param array $row Row data with Document No and trackable fields
     * @return string MD5 hash of the concatenated field values
     */
    private function generateDataHash(array $row): string
    {
        $hashData = implode('|', [
            $row['Status'] ?? '',
            $row['Total Amount'] ?? '',
            $row['Customer Code'] ?? '',
            $row['Customer Name'] ?? '',
            $row['Business Center'] ?? '',
            $row['Division'] ?? '',
            $row['Profit Center'] ?? ''
        ]);
        
        return md5($hashData);
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
                'inserted' => $result['inserted'],
                'updated'  => $result['updated'] ?? 0,
                'total'    => $result['inserted'] + ($result['updated'] ?? 0)
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
    OPTIMIZED BATCH PROCESSOR (INSERT...ON DUPLICATE KEY UPDATE)
    Handles both inserts and updates in a single SQL statement
    Uses MD5 hash for efficient change detection
    ======================
    */
    private function processBatch(array $rows, string $now): array
    {
        if (empty($rows)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $values = [];
        $params = [];
        $docNos = [];
        $cleanRows = [];
        $i = 0;

        // Normalize and prepare rows
        foreach ($rows as $row) {
            $docNo = $row['Document No'] ?? null;
            if (!$docNo) continue;

            // Trim all string values
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

            $row['Status'] = $row['Status'] ?? null;

            $docNos[] = $docNo;
            $cleanRows[] = $row;
        }

        // Fetch existing data in ONE query
        $existing = $this->fetchExistingRowsOptimized($docNos);

        $inserted = 0;
        $updated = 0;

        // Build single INSERT...ON DUPLICATE KEY UPDATE statement
        foreach ($cleanRows as $row) {
            $docNo = $row['Document No'];
            $old = $existing[$docNo] ?? null;
            
            $siDataId = $old ? $old['si_data_id'] : $this->generateSiDataId();
            $newDataHash = $this->generateDataHash($row);
            $oldDataHash = $old['data_hash'] ?? null;

            // Pre-compute if this would be inserted or updated
            if (!$old) {
                $inserted++;
            } else if ($newDataHash !== $oldDataHash) {
                $updated++;
            }
            // If hash matches and row exists, skip (no changes)

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
                :hash{$i},
                :added{$i},
                :updated{$i}
            )";

            $params[":si{$i}"]       = $siDataId;
            $params[":doc{$i}"]      = $docNo;
            $params[":status{$i}"]   = $row['Status'];
            $params[":amount{$i}"]   = $row['Total Amount'];
            $params[":custCode{$i}"] = $row['Customer Code'];
            $params[":custName{$i}"] = $row['Customer Name'];
            $params[":biz{$i}"]      = $row['Business Center'];
            $params[":division{$i}"] = $row['Division'];
            $params[":profit{$i}"]   = $row['Profit Center'];
            $params[":hash{$i}"]     = $newDataHash;
            $params[":added{$i}"]    = $old ? $old['date_added'] : $now;
            $params[":updated{$i}"]  = $now;

            $i++;
        }

        if (empty($values)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        // Single batch statement: INSERT new rows, UPDATE changed rows only
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
                data_hash,
                date_added,
                date_last_update
            )
            VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
                `Status`           = VALUES(`Status`),
                `Total Amount`     = VALUES(`Total Amount`),
                `Customer Code`    = VALUES(`Customer Code`),
                `Customer Name`    = VALUES(`Customer Name`),
                `Business Center`  = VALUES(`Business Center`),
                `Division`         = VALUES(`Division`),
                `Profit Center`    = VALUES(`Profit Center`),
                data_hash          = VALUES(data_hash),
                date_last_update   = VALUES(date_last_update)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'inserted' => $inserted,
            'updated' => $updated
        ];
    }

    /**
     * Optimized version: Fetch existing rows in a single query
     * Groups by Document No for O(1) lookup
     */
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

        return ['inserted' => $inserted];
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