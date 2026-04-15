<?php

require_once __DIR__ . '/../Services/GoogleSheetService.php';
require_once __DIR__ . '/../Services/BankCustomerService.php';

class GoogleSheetBankCustomerController
{
    private GoogleSheetService $sheetService;
    private BankCustomerService $bankService;

    public function __construct()
    {
        $this->sheetService = new GoogleSheetService();
        $this->bankService  = new BankCustomerService();
    }

    public function handleRequest()
    {
        $rows = $this->sheetService->fetchSheetData("BANK_CUSTOMER");

        $result = $this->bankService->upsert($rows);

        return [
            "status" => $result["status"],
            "processed" => $result["processed"],
            "skipped (inactive)" => $result["skipped"],
            "bank_customer_rows" => $result["bank_customer_rows"],
            "updated_customer_nos" => $result["updated_customer_nos"]
        ];
    }
}