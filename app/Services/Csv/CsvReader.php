<?php

namespace App\Services\Csv;

use Illuminate\Http\UploadedFile;

/**
 * Lecture d'un fichier CSV : taille, BOM, espaces Unicode, séparateur, en-tête.
 *
 * Ce que cette classe apporte par rapport au `str_getcsv` d'`AirbnbImportService` :
 * la **détection du séparateur**. Un tableur français exporte en `;`, un export de
 * plateforme en `,`, un copier-coller de tableur en tabulation — et un CSV lu avec le
 * mauvais séparateur ne produit pas une erreur, il produit **une seule colonne** dont
 * l'en-tête ressemble à une phrase. L'échec est alors muet et incompréhensible.
 */
class CsvReader
{
    /**
     * Taille maximale acceptée : 10 Mo, alignée sur la limite d'upload des justificatifs
     * (`maxSize` Filament 10240 Ko, `MAX_FILE_SIZE_BYTES` d'`App\Mcp\Tools\AttachDocument`).
     */
    public const MAX_IMPORT_BYTES = 10 * 1024 * 1024;

    /** Séparateurs candidats, par fréquence décroissante dans nos cas d'usage. */
    private const DELIMITERS = [';', ',', "\t", '|'];

    /**
     * @return array{header: list<string>, rows: list<list<string>>, delimiter: string}
     *
     * @throws \RuntimeException fichier trop gros, illisible, ou vide
     */
    public function read(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        // getSize() sur l'UploadedFile : taille réelle du fichier temporaire, non
        // contrôlable par le client, et respectée par UploadedFile::fake().
        $size = $file instanceof UploadedFile ? $file->getSize() : @filesize($path);

        if ($size !== false && $size > self::MAX_IMPORT_BYTES) {
            $maxMo = self::MAX_IMPORT_BYTES / (1024 * 1024);
            throw new \RuntimeException("Le fichier dépasse la taille maximum autorisée ({$maxMo} Mo).");
        }

        $content = $path ? @file_get_contents($path) : false;

        if ($content === false) {
            throw new \RuntimeException('Fichier illisible.');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = self::normalizeUnicode($content);
        $content = str_replace("\r\n", "\n", $content);

        $lines = array_values(array_filter(
            explode("\n", $content),
            fn ($line) => trim($line) !== '',
        ));

        if (count($lines) < 2) {
            throw new \RuntimeException('Fichier vide ou sans ligne de données.');
        }

        $delimiter = self::detectDelimiter($lines[0]);

        $header = array_map(
            fn ($cell) => trim((string) $cell),
            str_getcsv(array_shift($lines), $delimiter, '"', '\\'),
        );

        $rows = array_map(
            fn ($line) => str_getcsv($line, $delimiter, '"', '\\'),
            $lines,
        );

        return ['header' => $header, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    /**
     * Séparateur le plus probable : celui qui découpe l'en-tête en le plus de colonnes.
     *
     * À égalité, l'ordre de `DELIMITERS` tranche. Un en-tête à une seule colonne quel
     * que soit le candidat rend le point-virgule, le plus courant chez nous.
     */
    public static function detectDelimiter(string $headerLine): string
    {
        $best = self::DELIMITERS[0];
        $bestCount = 0;

        foreach (self::DELIMITERS as $candidate) {
            $count = count(str_getcsv($headerLine, $candidate, '"', '\\'));

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** Espaces insécables et variantes → espace normal (U+00A0, U+202F, U+2007, U+2009). */
    public static function normalizeUnicode(string $content): string
    {
        return preg_replace('/[\x{00A0}\x{202F}\x{2007}\x{2009}]/u', ' ', $content);
    }
}
