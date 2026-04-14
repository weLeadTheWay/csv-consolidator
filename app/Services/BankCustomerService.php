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

        foreach ($rows as $row) {

            $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $params[] = $row['Account Name'] ?? '-';
            $params[] = (int)($row['Customer No'] ?? 0);
            $params[] = $row['Type'] ?? '-';
            $params[] = $row['MODE OF PAYMENT'] ?? '-';
            $params[] = $row['REMITTANCE CHANNEL'] ?? '-';
            $params[] = $row['BANK (if applicable)'] ?? '-';
            $params[] = $row['BANK ACCOUNT NO. (if applicable)'] ?? '-';
            $params[] = $row['CR TYPE (CAS/MANUAL)'] ?? '-';
            $params[] = ($row['CWT/EWT'] ?? 0) ? 1 : 0;
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
            ) VALUES " . implode(',', $placeholders);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            "inserted" => count($rows),
            "updated" => 0
        ];
    }
}