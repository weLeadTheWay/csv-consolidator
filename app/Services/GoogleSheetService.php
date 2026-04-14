<?php

require_once __DIR__ . '/../Interfaces/GoogleSheetServiceInterface.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Drive;

class GoogleSheetService implements GoogleSheetServiceInterface
{
    private Client $client;
    private Sheets $sheets;
    private Drive $drive;

    public function __construct()
    {
        session_start();

        $this->client = new Client();

        $this->client->setApplicationName("CSV Consolidator");

        // OAuth credentials
        $this->client->setAuthConfig(
            __DIR__ . '/../Config/oauth-credentials.json'
        );

        $this->client->setScopes([
            Sheets::SPREADSHEETS,
            Drive::DRIVE
        ]);

        $this->client->setAccessType('offline');
        $this->client->setPrompt('select_account consent');

        if (isset($_SESSION['access_token'])) {
            $this->client->setAccessToken($_SESSION['access_token']);

            // Refresh token if expired
            if ($this->client->isAccessTokenExpired()) {
                $refreshToken = $_SESSION['access_token']['refresh_token'] ?? null;

                if ($refreshToken) {
                    $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
                    $_SESSION['access_token'] = array_merge($_SESSION['access_token'], $newToken);
                }
            }
        }

        $this->sheets = new Sheets($this->client);
        $this->drive = new Drive($this->client);
    }

    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    public function handleCallback(): void
    {
        if (!isset($_GET['code'])) {
            throw new Exception("Missing OAuth code");
        }

        $token = $this->client->fetchAccessTokenWithAuthCode($_GET['code']);

        $_SESSION['access_token'] = $token;
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['access_token']);
    }

    public function createSheet(string $title): string
    {
        $spreadsheet = new \Google\Service\Sheets\Spreadsheet([
            'properties' => [
                'title' => $title
            ],
            'sheets' => [
                [
                    'properties' => [
                        'title' => 'Sheet1'
                    ]
                ]
            ]
        ]);

        $result = $this->sheets->spreadsheets->create($spreadsheet);

        return $result->spreadsheetId;
    }

    public function fetchSheetData(string $sheetName): array
    {
        $client = new \Google\Client();
        $client->setAuthConfig(__DIR__ . '/../Config/csv-consolidator-credentials.json');
        $client->addScope(\Google\Service\Sheets::SPREADSHEETS_READONLY);

        $service = new \Google\Service\Sheets($client);

        $spreadsheetId = getenv("GOOGLE_SHEET_ID");

        if (!$spreadsheetId) {
            throw new Exception("Missing GOOGLE_SHEET_ID in .env");
        }

        $response = $service->spreadsheets_values->get(
            $spreadsheetId,
            $sheetName
        );

        $rows = $response->getValues();

        if (!$rows || count($rows) < 2) return [];

        $headers = array_map('trim', $rows[0]);
        $data = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $item = [];

            foreach ($headers as $index => $key) {
                $value = $row[$index] ?? null;
                $item[$key] = $this->normalize($value);
            }

            $data[] = $item;
        }

        return $data;
    }

    private function normalize($value)
    {
        if ($value === null || $value === '') {
            return "-";
        }
        return $value;
    }
}