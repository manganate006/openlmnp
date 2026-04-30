<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExportService
{
    public static function export(string $filename, array $headers, Collection $records, callable $rowMapper): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $records, $rowMapper) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 pour Excel
            fwrite($handle, "\xEF\xBB\xBF");

            // En-tête
            fputcsv($handle, $headers, ';');

            // Données
            foreach ($records as $record) {
                fputcsv($handle, $rowMapper($record), ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
