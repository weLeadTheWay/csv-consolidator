<?php

require_once __DIR__ . '/../Config/Database.php';

class BankCustomerService
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function upsert(array $rows): array
    {
        if (empty($rows)) {
            return ["inserted" => 0, "updated" => 0];
        }

        $placeholders = [];
        $params = [];
        $skipped = 0;
        $inserted = 0;
        $changedCustomerNos = [];

        // preload existing rows (IMPORTANT)
        $existing = [];

        $stmt = $this->db->query("SELECT * FROM bank_customer");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[$row['customer_no']] = $row;
        }

        foreach ($rows as $row) {
            $customerNo = trim($row['Customer No'] ?? '');
            $mode = strtoupper(trim($row['MODE OF PAYMENT'] ?? ''));

            // skip rules
            if ($customerNo === '' || $mode === 'INACTIVE') {
                $skipped++;
                continue;
            }

            // normalize values
            $customerNo = trim($row['Customer No'] ?? '');
            $mode = strtoupper(trim($row['MODE OF PAYMENT'] ?? ''));

            // skip if customer no is empty or mode is INACTIVE
            if ($customerNo === '' || $mode === 'INACTIVE') {
                $skipped++;
                continue;
            }
            $inserted++;

            $dbRow = $existing[(int)$customerNo] ?? null;
            $isChanged = false;

            if ($dbRow) {
                if (
                    $dbRow['account_name'] !== ($row['Account Name'] ?? '-') ||
                    $dbRow['type'] !== ($row['Type'] ?? '-') ||
                    $dbRow['mode_of_payment'] !== ($row['MODE OF PAYMENT'] ?? '-') ||
                    $dbRow['remittance_channel'] !== ($row['REMITTANCE CHANNEL'] ?? '-') ||
                    $dbRow['bank'] !== ($row['BANK (if applicable)'] ?? '-') ||
                    (string)$dbRow['bank_account_no'] !== (string)($row['BANK ACCOUNT NO. (if applicable)'] ?? '-') ||
                    $dbRow['cr_type'] !== ($row['CR TYPE (CAS/MANUAL)'] ?? '-') ||
                    (int)$dbRow['cwt_ewt'] !== (($row['CWT/EWT'] ?? 0) ? 1 : 0)
                ) {
                    $isChanged = true;
                }
            } else {
                $isChanged = true; // new row
            }

            if ($isChanged) {
                $customerNoInt = (int)$customerNo;

                if ($customerNoInt > 0) {
                    $changedCustomerNos[$customerNoInt] = $customerNoInt;
                }
            }

            $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params[] = $row['Account Name'] ?? '-';
            $params[] = (int)$customerNo; // ✅ safe now
            $params[] = $row['Type'] ?? '-';
            $params[] = $row['MODE OF PAYMENT'] ?? '-';
            $params[] = $row['REMITTANCE CHANNEL'] ?? '-';
            $params[] = $row['BANK (if applicable)'] ?? '-';
            $params[] = $row['BANK ACCOUNT NO. (if applicable)'] ?? '-';
            $params[] = $row['CR TYPE (CAS/MANUAL)'] ?? '-';
            $params[] = ($row['CWT/EWT'] ?? 0) ? 1 : 0;
        }

        // if all rows skipped
        if (empty($placeholders)) {
            return [
                "inserted" => 0,
                "updated" => 0,
                "skipped" => $skipped
            ];
        }

        $sql = "
            INSERT INTO bank_customer (
                account_name,
                customer_no,
                type,
                mode_of_payment,
                remittance_channel,
                bank,
                bank_account_no,
                cr_type,
                cwt_ewt,
                date_added
            ) VALUES " . implode(',', $placeholders) . "

            ON DUPLICATE KEY UPDATE
                account_name = VALUES(account_name),
                type = VALUES(type),
                mode_of_payment = VALUES(mode_of_payment),
                remittance_channel = VALUES(remittance_channel),
                bank = VALUES(bank),
                bank_account_no = VALUES(bank_account_no),
                cr_type = VALUES(cr_type),
                cwt_ewt = VALUES(cwt_ewt)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $countStmt = $this->db->query("SELECT COUNT(*) as total FROM bank_customer");
        $count = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        $changedCustomerNos = array_values($changedCustomerNos);
        $totalChanges = count($changedCustomerNos);
        

        return [
            "status" => "SUCCESS",
            "processed" => $inserted,
            "skipped" => $skipped,
            "bank_customer_rows" => (int)$count,
            "total_changes" => $totalChanges,
            "updated_customer_nos" => $changedCustomerNos
        ];
    }
}