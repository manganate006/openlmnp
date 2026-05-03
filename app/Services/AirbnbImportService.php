<?php

namespace App\Services;

use App\Models\Income;
use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Import des revenus depuis un fichier CSV Airbnb.
 *
 * Supporte deux formats d'export Airbnb :
 * 1. "Historique des transactions" : Date, Type, Confirmation code, Amount, Host fee, Paid out...
 * 2. "Réservations" : Code de confirmation, Statut, Nom du voyageur, Date de début, Revenus...
 */
class AirbnbImportService
{
    /**
     * Mapping des en-têtes CSV Airbnb vers nos champs.
     * Supporte plusieurs variantes (FR / EN, formats Transactions et Réservations).
     */
    private const COLUMN_MAP = [
        'date'             => ['Date', 'date', 'Date du paiement', 'Payout date'],
        'type'             => ['Type', 'type'],
        'status'           => ['Statut', 'Status'],
        'confirmation'     => ['Confirmation code', 'confirmation_code', 'Code de confirmation', 'Confirmation'],
        'start_date'       => ['Start date', 'start_date', 'Date de début', 'Début du séjour', 'Check-in'],
        'end_date'         => ['End date', 'Date de fin', 'Fin du séjour', 'Check-out'],
        'nights'           => ['Nights', 'nights', 'Nuits', 'Nombre de nuits', '# des nuits'],
        'guest'            => ['Guest', 'guest', 'Voyageur', 'Nom du voyageur'],
        'listing'          => ['Listing', 'listing', 'Annonce', 'Nom de l\'annonce'],
        'amount'           => ['Amount', 'amount', 'Montant', 'Montant brut', 'Gross earnings', 'Revenus'],
        'host_fee'         => ['Host fee', 'host_fee', 'Frais de service hôte', 'Service fee', 'Host Fee Amount'],
        'paid_out'         => ['Paid out', 'paid_out', 'Versé', 'Montant versé', 'Host Payout'],
        'currency'         => ['Currency', 'currency', 'Devise'],
        'booked_date'      => ['Réservée', 'Booked'],
    ];

    /**
     * Parse le CSV et retourne un aperçu des lignes sans les importer.
     *
     * @return array{rows: array, skipped: int, errors: array}
     */
    public function preview(UploadedFile $file, Property $property): array
    {
        $parsed = $this->parseFile($file);
        if ($parsed === null) {
            return ['rows' => [], 'skipped' => 0, 'errors' => [], 'warnings' => []];
        }

        [$lines, $columnIndexes] = $parsed;
        $isNetFormat = ! isset($columnIndexes['host_fee']);
        $rows = [];
        $skipped = 0;
        $errors = [];
        $warnings = [];

        if ($isNetFormat && ! $property->airbnb_commission_rate) {
            $warnings[] = 'Ce CSV ne contient pas la commission Airbnb. Configurez le taux de commission dans les paramètres du bien pour recalculer automatiquement le montant brut et la commission.';
        }

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $row = str_getcsv($line);

            try {
                $data = $this->extractRowData($row, $columnIndexes, $property);
                if ($data) {
                    $rows[] = $data;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $errors[] = "Ligne " . ($lineNum + 2) . " : " . $e->getMessage();
                $skipped++;
            }
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Importe un fichier CSV Airbnb pour un bien donné.
     *
     * @return array{imported: int, skipped: int, errors: array}
     */
    public function import(UploadedFile $file, Property $property): array
    {
        $parsed = $this->parseFile($file);
        if ($parsed === null) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Fichier vide ou invalide']];
        }

        [$lines, $columnIndexes] = $parsed;
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $row = str_getcsv($line);

            try {
                $result = $this->processRow($row, $columnIndexes, $property);
                if ($result) {
                    $imported++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $errors[] = "Ligne " . ($lineNum + 2) . " : " . $e->getMessage();
                $skipped++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Parse le fichier CSV et retourne les lignes + index de colonnes.
     */
    private function parseFile(UploadedFile $file): ?array
    {
        $content = file_get_contents($file->getRealPath());

        // Supprimer le BOM UTF-8
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Normaliser les caractères Unicode spéciaux (espaces insécables, etc.)
        $content = $this->normalizeUnicode($content);

        $lines = explode("\n", $content);

        if (count($lines) < 2) {
            return null;
        }

        $header = str_getcsv(array_shift($lines));
        $columnIndexes = $this->mapColumns($header);

        return [$lines, $columnIndexes];
    }

    /**
     * Normalise les caractères Unicode problématiques dans le contenu CSV.
     */
    private function normalizeUnicode(string $content): string
    {
        // Remplacer les espaces insécables et variantes par des espaces normaux
        // U+00A0 (NBSP), U+202F (NNBSP), U+2007 (Figure space), U+2009 (Thin space)
        $content = preg_replace('/[\x{00A0}\x{202F}\x{2007}\x{2009}]/u', ' ', $content);

        return $content;
    }

    /**
     * Extrait les données d'une ligne CSV sans créer d'enregistrement.
     *
     * @return array|null Données de la ligne ou null si ignorée
     */
    private function extractRowData(array $row, array $indexes, Property $property): ?array
    {
        $amountRaw = $this->getField($row, $indexes, 'amount')
            ?? $this->getField($row, $indexes, 'paid_out');

        if (! $amountRaw) {
            return null;
        }

        $amount = $this->parseMoney($amountRaw);

        // Date : priorité à 'date', sinon 'start_date' (format Réservations)
        $dateRaw = $this->getField($row, $indexes, 'date')
            ?? $this->getField($row, $indexes, 'start_date');
        if (! $dateRaw) {
            return null;
        }
        $date = $this->parseDate($dateRaw);

        $confirmation = $this->getField($row, $indexes, 'confirmation');
        $guest = $this->getField($row, $indexes, 'guest');
        $startDate = $this->getField($row, $indexes, 'start_date');
        $status = $this->getField($row, $indexes, 'status');

        // Lignes ignorées (annulations, montant 0) : on les retourne quand même pour l'aperçu
        if ($amount <= 0) {
            return [
                'date' => $date,
                'guest' => $guest,
                'confirmation' => $confirmation,
                'amount' => $amount,
                'host_fee' => 0,
                'checkin' => $startDate ? $this->parseDate($startDate) : null,
                'duplicate' => false,
                'skipped' => true,
                'skip_reason' => $status ?? 'Montant nul ou négatif',
            ];
        }

        $hostFeeRaw = $this->getField($row, $indexes, 'host_fee');
        $hostFee = $hostFeeRaw ? abs($this->parseMoney($hostFeeRaw)) : 0;

        // Format Réservations (pas de colonne Host fee) : recalculer brut/commission
        if (! $hostFeeRaw && ! isset($indexes['host_fee']) && $property->airbnb_commission_rate) {
            [$amount, $hostFee] = $this->recalculateGross($amount, (float) $property->airbnb_commission_rate);
        }

        $duplicate = false;
        if ($confirmation) {
            $duplicate = Income::where('property_id', $property->id)
                ->where('reservation_ref', $confirmation)
                ->exists();
        }

        return [
            'date' => $date,
            'guest' => $guest,
            'confirmation' => $confirmation,
            'amount' => $amount,
            'host_fee' => $hostFee,
            'checkin' => $startDate ? $this->parseDate($startDate) : null,
            'duplicate' => $duplicate,
            'skipped' => false,
            'skip_reason' => null,
        ];
    }

    /**
     * Map les colonnes du CSV vers nos identifiants.
     */
    private function mapColumns(array $header): array
    {
        $indexes = [];
        $header = array_map('trim', $header);

        foreach (self::COLUMN_MAP as $field => $variants) {
            foreach ($variants as $variant) {
                $index = array_search($variant, $header);
                if ($index !== false) {
                    $indexes[$field] = $index;
                    break;
                }
            }
        }

        return $indexes;
    }

    /**
     * Traite une ligne du CSV.
     */
    private function processRow(array $row, array $indexes, Property $property): ?Income
    {
        // Récupérer le montant (brut ou versé)
        $amountRaw = $this->getField($row, $indexes, 'amount')
            ?? $this->getField($row, $indexes, 'paid_out');

        if (! $amountRaw) {
            return null;
        }

        // Convertir le montant en centimes
        $amount = $this->parseMoney($amountRaw);
        if ($amount <= 0) {
            return null; // Ignorer les remboursements et ajustements négatifs
        }

        // Date : priorité à 'date', sinon 'start_date' (format Réservations)
        $dateRaw = $this->getField($row, $indexes, 'date')
            ?? $this->getField($row, $indexes, 'start_date');
        if (! $dateRaw) {
            return null;
        }
        $date = $this->parseDate($dateRaw);

        // Commission host
        $hostFeeRaw = $this->getField($row, $indexes, 'host_fee');
        $hostFee = $hostFeeRaw ? abs($this->parseMoney($hostFeeRaw)) : 0;

        // Format Réservations (pas de colonne Host fee) : recalculer brut/commission
        if (! $hostFeeRaw && ! isset($indexes['host_fee']) && $property->airbnb_commission_rate) {
            [$amount, $hostFee] = $this->recalculateGross($amount, (float) $property->airbnb_commission_rate);
        }

        // Vérifier si déjà importé (par code de confirmation)
        $confirmation = $this->getField($row, $indexes, 'confirmation');
        if ($confirmation) {
            $existing = Income::where('property_id', $property->id)
                ->where('reservation_ref', $confirmation)
                ->first();
            if ($existing) {
                return null; // Déjà importé
            }
        }

        // Dates de séjour
        $startDate = $this->getField($row, $indexes, 'start_date');
        $endDate = $this->getField($row, $indexes, 'end_date');

        return Income::create([
            'property_id'     => $property->id,
            'income_date'     => $date,
            'amount'          => $amount,
            'platform_fee'    => $hostFee,
            'tourist_tax'     => 0,
            'source'          => 'airbnb',
            'reservation_ref' => $confirmation,
            'guest_name'      => $this->getField($row, $indexes, 'guest'),
            'checkin_date'    => $startDate ? $this->parseDate($startDate) : null,
            'checkout_date'   => $endDate ? $this->parseDate($endDate) : null,
        ]);
    }

    /**
     * Recalcule le montant brut et la commission à partir du net et du taux.
     * net = brut × (1 - taux/100)  →  brut = net / (1 - taux/100)
     *
     * @param int $netCents Montant net en centimes
     * @param float $ratePercent Taux de commission en % (ex: 3.6)
     * @return array{0: int, 1: int} [grossCents, feeCents]
     */
    private function recalculateGross(int $netCents, float $ratePercent): array
    {
        $rate = bcdiv((string) $ratePercent, '100', 6);
        $divisor = bcsub('1', $rate, 6);
        $grossCents = (int) bcdiv((string) $netCents, $divisor, 0);
        $feeCents = $grossCents - $netCents;

        return [$grossCents, $feeCents];
    }

    private function getField(array $row, array $indexes, string $field): ?string
    {
        if (! isset($indexes[$field])) {
            return null;
        }

        $value = $row[$indexes[$field]] ?? null;

        return $value !== null && $value !== '' ? trim($value) : null;
    }

    /**
     * Parse un montant monétaire vers des centimes.
     * Gère : "1,234.56", "1234.56", "1 234,56", "252,26 €", "-56.78"
     */
    private function parseMoney(string $raw): int
    {
        $raw = trim($raw);
        // Remove currency symbols and unicode spaces
        $raw = preg_replace('/[€$£\x{00A0}\x{202F}]/u', '', $raw);
        $raw = trim($raw);

        // Detect format: if last separator is comma and has 2 digits after → European
        if (preg_match('/,\d{2}$/', $raw)) {
            // European format: 1.234,56 or 1 234,56
            $raw = str_replace(['.', ' '], '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            // US format: 1,234.56
            $raw = str_replace([',', ' '], '', $raw);
        }

        return (int) bcmul($raw, '100', 0);
    }

    /**
     * Parse une date depuis divers formats.
     */
    private function parseDate(string $raw): string
    {
        $raw = trim($raw);

        // Try common formats (d/m/Y avant m/d/Y car CSV Airbnb FR)
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'M d, Y', 'd M Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $raw);
                if ($date && $date->month <= 12 && $date->day <= 31) {
                    return $date->format('Y-m-d');
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Fallback: let Carbon guess
        return Carbon::parse($raw)->format('Y-m-d');
    }
}
