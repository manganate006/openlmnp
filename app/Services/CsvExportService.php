<?php

namespace App\Services;

use Illuminate\Support\Collection;
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
            fputcsv($handle, array_map(self::cell(...), $headers), ';');

            // Données
            foreach ($records as $record) {
                fputcsv($handle, array_map(self::cell(...), $rowMapper($record)), ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Excel et LibreOffice exécutent une cellule qui commence par = + - @ comme une formule.
     * Un libellé saisi par l'utilisateur (ou importé d'un relevé) ne doit pas pouvoir le faire :
     * on le préfixe d'une apostrophe. Les montants (« -12,50 ») passent tels quels.
     */
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric(str_replace(',', '.', $value))) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
