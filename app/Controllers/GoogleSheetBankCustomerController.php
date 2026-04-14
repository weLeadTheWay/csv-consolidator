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
            "status" => "success",
            "inserted" => $result["inserted"],
            "updated" => $result["updated"]
        ];
    }
}