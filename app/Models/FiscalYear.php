<?php

namespace App\Models;

use App\Models\Scopes\BelongsToUserScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([BelongsToUserScope::class])]
class FiscalYear extends Model
{
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_CLOSED = 'closed';

    /**
     * Durée de report d'un déficit LMNP : les DIX années suivant celle de sa réalisation
     * (CGI art. 156, I-1° ter ; BOI-BIC-CHAMP-40-20 § 250 et BOI-BIC-DEF-20-20 § 120).
     * Un déficit né en 2015 est imputable de 2016 à 2025 inclus, perdu à compter de 2026.
     *
     * ⚠️ Ne pas confondre avec le 1° bis du même article (BIC non professionnels en général),
     * qui n'ouvre que SIX ans et ne concerne pas la location meublée.
     */
    public const DEFICIT_CARRYFORWARD_YEARS = 10;

    /** Provenance des soldes d'ouverture d'un exercice de reprise. */
    public const OPENING_SOURCE_LIASSE = 'liasse';
    public const OPENING_SOURCE_MANUAL = 'manuel';
    public const OPENING_SOURCE_AI     = 'ia';

    protected $fillable = [
        'user_id',
        'year',
        'status',
        'total_income',
        'total_expenses',
        'total_depreciation',
        'capped_depreciation',
        'deferred_depreciation',
        'previous_deferred',
        'opening_deferred_depreciation',
        'opening_deficits',
        'opening_accumulated_depreciation',
        'opening_source',
        'opening_declared_gross_assets',
        'opening_declared_result',
        'previous_deficit',
        'deficit_imputed',
        'deficit_carryforward',
        'deficit_detail',
        'fiscal_result',
        'total_tva_collected',
        'total_tva_deductible',
        'tva_balance',
        'form_data',
        // ⚠️ `pdf_path` / `fec_path` ne sont PLUS écrites ni lues : la liasse et le FEC ne sont
        // plus persistés sur le disque (ils portaient toute la comptabilité en clair, à côté
        // d'un coffre-fort censé la protéger). Elles restent déclarées le temps qu'une bascule
        // arrière de l'image reste possible ; `openlmnp:purge-legacy-files --fix` les vide.
        'pdf_path',
        'fec_path',
        // Ce qui porte désormais le signal « ce document a été produit » : une date, pas un
        // fichier. Lue par la checklist d'accueil, les badges, les statistiques admin, les
        // signaux de cycle de vie et l'encart de correction du 2033-D.
        'tax_return_generated_at',
        'fec_generated_at',
        'transmitted_at',
        'ack_number',
    ];

    protected function casts(): array
    {
        return [
            'form_data'               => 'array',
            'opening_deficits'        => 'array',
            'deficit_detail'          => 'array',
            'tax_return_generated_at' => 'datetime',
            'fec_generated_at'        => 'datetime',
            'transmitted_at'          => 'datetime',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT  => 'Brouillon',
            self::STATUS_CLOSED => 'Clôturé',
        ];
    }

    public static function openingSourceLabels(): array
    {
        return [
            self::OPENING_SOURCE_LIASSE => 'Liasse fiscale',
            self::OPENING_SOURCE_MANUAL => 'Saisie manuelle',
            self::OPENING_SOURCE_AI     => 'Lecture assistée',
        ];
    }

    /**
     * L'exercice est un exercice de REPRISE : le point de départ de la chaîne d'exercices.
     *
     * ⚠️ `opening_source` suffit, même sans différé ni déficit. C'est le cas le plus courant
     * d'un loueur meublé bénéficiaire : sa liasse ne porte ni amortissement différé ni
     * déficit, et sa reprise n'en est pas moins terminée. Jusqu'au 2026-10-15, ce test ne
     * regardait que les deux stocks : une reprise « à zéro » n'était pas reconnue, et
     * l'assistant de clôture lui réclamait l'exercice précédent, qu'elle n'a par définition
     * jamais tenu ici. `opening_source` n'est écrit QUE par la fin de la reprise
     * (`RepriseDossier::applyOpeningBalances()`).
     *
     * `opening_accumulated_depreciation` seul n'entre toujours pas dans ce test : c'est une
     * donnée de contrôle, elle ne suffit pas à faire d'un exercice une reprise.
     */
    public function hasOpeningBalances(): bool
    {
        return $this->opening_source !== null
            || (int) $this->opening_deferred_depreciation > 0
            || $this->openingDeficitsTotal() > 0;
    }

    /** Somme des déficits d'ouverture, tous millésimes confondus (centimes). */
    public function openingDeficitsTotal(): int
    {
        $total = '0';

        foreach ($this->opening_deficits ?? [] as $deficit) {
            $total = bcadd($total, (string) (int) ($deficit['amount'] ?? 0), 0);
        }

        return (int) $total;
    }

    /**
     * Résumé du dépôt enregistré, ou `null` tant que l'utilisateur n'en a saisi aucun.
     *
     * Une liasse générée n'est pas une liasse déposée : seule cette date, saisie à la main
     * après le dépôt effectif, distingue les deux. Rien ne la reconstitue — ni la génération
     * du PDF, ni la clôture de l'exercice.
     */
    public function transmissionSummary(): ?string
    {
        if ($this->transmitted_at === null) {
            return null;
        }

        return 'Liasse déposée le ' . $this->transmitted_at->format('d/m/Y')
            . ($this->ack_number === null
                ? ' — aucun numéro d\'accusé enregistré.'
                : ' — accusé n° ' . $this->ack_number . '.');
    }

    /**
     * Exercice sans aucune donnée calculée.
     *
     * Un tel exercice n'a pas « un report de 0 € » : il n'a PAS DE REPORT DU TOUT. La nuance
     * décide du sort d'un solde d'ouverture — voir `FiscalYearService::carriedForwardDeferred()`.
     */
    public function hasNoComputedData(): bool
    {
        return (int) $this->total_income === 0
            && (int) $this->total_expenses === 0
            && (int) $this->total_depreciation === 0
            && (int) $this->capped_depreciation === 0
            && (int) $this->deferred_depreciation === 0
            && (int) $this->fiscal_result === 0;
    }

    public function getFiscalResultEurosAttribute(): string
    {
        return bcdiv((string) $this->fiscal_result, '100', 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accountingEntries(): HasMany
    {
        return $this->hasMany(AccountingEntry::class)->orderBy('entry_date');
    }
}
