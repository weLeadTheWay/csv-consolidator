<?php

require_once __DIR__ . '/../Interfaces/CsvParserInterface.php';

class CsvParser implements CsvParserInterface
{
    public function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("CSV file not found.");
        }

        $data = [];
        $header = null;

        if (($handle = fopen($filePath, "r")) !== false) {

            while (($row = fgetcsv($handle, 1000, ",")) !== false) {

                // First row = header
                if (!$header) {
                    $header = $row;
                    continue;
                }

                // Combine header with row values
                if (count($header) === count($row)) {
                    $data[] = array_combine($header, $row);
                }
            }

            fclose($handle);
        }

        return $data;
    }
}