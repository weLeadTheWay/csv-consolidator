<?php

interface CsvParserInterface {
    public function parse(string $filePath): array;
}