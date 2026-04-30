<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\FiscalYear;
use Illuminate\Support\Facades\Storage;

/**
 * Génération du Fichier des Écritures Comptables (FEC).
 *
 * Format normé par l'article A.47 A-1 du LPF.
 * 18 colonnes obligatoires, séparateur TAB, encodage UTF-8.
 *
 * Nom fichier : {SIREN}FEC{AAAAMMJJ}.txt
 * Sanctions non-conformité : 5 000 € minimum (art. 1729 D du CGI).
 */
class FecService
{
    /**
     * Les 18 colonnes obligatoires du FEC.
     * Noms exacts de l'article A.47 A-1 du LPF.
     */
    private const COLUMNS = [
        'JournalCode',
        'JournalLib',
        'EcritureNum',
        'EcritureDate',
        'CompteNum',
        'CompteLib',
        'CompAuxNum',
        'CompAuxLib',
        'PieceRef',
        'PieceDate',
        'EcritureLib',
        'Debit',
        'Credit',
        'EcritureLet',
        'DateLet',
        'ValidDate',
        'Montantdevise',
        'Idevise',
    ];

    /**
     * Labels des journaux comptables.
     */
    private const JOURNAL_LABELS = [
        'OD' => 'Opérations diverses',
        'HA' => 'Achats',
        'VE' => 'Ventes',
        'BQ' => 'Banque',
        'AN' => 'À-nouveaux',
    ];

    /**
     * Labels des comptes comptables LMNP (Plan Comptable Général).
     */
    private const ACCOUNT_LABELS = [
        '108'  => 'Compte de l\'exploitant',
        '164'  => 'Emprunts auprès des établissements de crédit',
        '211'  => 'Terrains',
        '2131' => 'Constructions - Bâtiments',
        '2135' => 'Agencements des constructions',
        '2151' => 'Installations techniques',
        '2181' => 'Installations générales, agencements divers',
        '2184' => 'Mobilier',
        '2188' => 'Autres immobilisations corporelles',
        '2813' => 'Amortissements constructions',
        '2815' => 'Amortissements installations techniques',
        '28181' => 'Amortissements installations générales',
        '28184' => 'Amortissements mobilier',
        '28188' => 'Amortissements autres immobilisations corporelles',
        '512'  => 'Banque',
        '606'  => 'Achats non stockés',
        '6061' => 'Fournitures non stockables (eau, énergie)',
        '615'  => 'Entretien et réparations',
        '616'  => 'Primes d\'assurances',
        '622'  => 'Rémunérations d\'intermédiaires et honoraires',
        '6226' => 'Honoraires expert-comptable',
        '625'  => 'Déplacements, missions',
        '626'  => 'Frais postaux et de télécommunication',
        '627'  => 'Services bancaires',
        '6351' => 'Taxe foncière',
        '6358' => 'CFE',
        '661'  => 'Charges d\'intérêts',
        '6611' => 'Intérêts des emprunts',
        '6616' => 'Assurance emprunteur',
        '681'  => 'Dotations aux amortissements',
        '68112' => 'Dotations amort. immobilisations corporelles',
        '706'  => 'Prestations de services (loyers)',
        '7061' => 'Loyers location meublée',
        '708'  => 'Produits des activités annexes',
    ];

    /**
     * Génère le FEC pour un exercice fiscal.
     */
    public function generate(FiscalYear $fiscalYear): string
    {
        $entries = AccountingEntry::where('fiscal_year_id', $fiscalYear->id)
            ->orderBy('entry_date')
            ->orderBy('piece_ref')
            ->orderBy('id')
            ->get();

        $siren = $fiscalYear->user->siren ?? '000000000';
        $yearEnd = $fiscalYear->year . '1231';
        // Format légal : {SIREN}FEC{AAAAMMJJ}.txt
        $filename = "{$siren}FEC{$yearEnd}.txt";

        $lines = [];

        // En-tête obligatoire
        $lines[] = implode("\t", self::COLUMNS);

        // Écritures — même EcritureNum pour les lignes d'une écriture équilibrée
        foreach ($entries as $entry) {
            $lines[] = implode("\t", [
                $entry->journal,                                          // JournalCode
                self::JOURNAL_LABELS[$entry->journal] ?? $entry->journal, // JournalLib
                str_pad((string) $entry->piece_ref, 6, '0', STR_PAD_LEFT), // EcritureNum
                $entry->entry_date->format('Ymd'),                        // EcritureDate
                $entry->account_code,                                     // CompteNum
                self::ACCOUNT_LABELS[$entry->account_code] ?? $entry->label, // CompteLib
                '',                                                       // CompAuxNum
                '',                                                       // CompAuxLib
                $entry->piece_ref ?? '',                                  // PieceRef
                $entry->entry_date->format('Ymd'),                        // PieceDate
                $entry->label,                                            // EcritureLib
                $this->formatAmount($entry->debit),                       // Debit
                $this->formatAmount($entry->credit),                      // Credit
                '',                                                       // EcritureLet
                '',                                                       // DateLet
                $entry->entry_date->format('Ymd'),                        // ValidDate
                '',                                                       // Montantdevise (vide pour EUR)
                '',                                                       // Idevise (vide pour EUR)
            ]);
        }

        $content = implode("\r\n", $lines) . "\r\n";

        // Sauvegarder
        $path = "fec/{$fiscalYear->year}/{$filename}";
        Storage::put($path, $content);

        $fiscalYear->update(['fec_path' => $path]);

        return $path;
    }

    /**
     * Formate un montant en centimes vers le format FEC (virgule décimale, pas de séparateur milliers).
     */
    private function formatAmount(int $cents): string
    {
        if ($cents === 0) {
            return '0,00';
        }

        return number_format($cents / 100, 2, ',', '');
    }
}
