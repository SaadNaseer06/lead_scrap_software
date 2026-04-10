<?php

require __DIR__ . '/../vendor/autoload.php';

$path = __DIR__ . '/../LEAD SHEET.xlsx';
if (!file_exists($path)) {
    fwrite(STDERR, "Missing sample file: {$path}" . PHP_EOL);
    exit(1);
}

$reader = new OpenSpout\Reader\XLSX\Reader();
$reader->open($path);

$line = 0;
foreach ($reader->getSheetIterator() as $sheet) {
    foreach ($sheet->getRowIterator() as $row) {
        $line++;
        if ($line > 3) {
            break;
        }

        echo json_encode($row->toArray()) . PHP_EOL;
    }
    break;
}

$reader->close();
