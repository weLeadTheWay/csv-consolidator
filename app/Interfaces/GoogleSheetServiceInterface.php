<?php

interface GoogleSheetServiceInterface
{
    public function createSheet(string $title): string;
}