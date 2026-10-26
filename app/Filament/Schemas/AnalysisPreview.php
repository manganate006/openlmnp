<?php

namespace App\Filament\Schemas;

use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Furniture\Schemas\FurnitureForm;
use App\Filament\Resources\PropertyWorks\Schemas\PropertyWorkForm;
use App\Enums\TvaRate;
use App\Models\Income;
use App\Models\Loan;
use App\Services\Ai\Analysis\AnalysisResult;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

/**
 * Formulaire de relecture d'une proposition d'analyse.
 *
 * Les champs sont ceux des formulaires de saisie ordinaires — `ExpenseForm::fields()`
 * et consorts — pour qu'une modification faite là-bas se retrouve ici sans double
 * maintenance. On leur ajoute seulement un INDICATEUR DE CONFIANCE et l'extrait du
 * document (« Lu : … »), les deux seules choses qui distinguent cet écran.
 *
 * Le bien n'est pas un select : il a été choisi à l'étape du dépôt. Il reste dans
 * l'état sous forme de champ caché, car les formulaires existants l'interrogent
 * (`$get('property_id')`) pour savoir si le bien est assujetti à la TVA.
 */
class AnalysisPreview
{
    /** Seuils d'affichage de la confiance (CDC § 3.1). */
    private const HIGH = 0.85;
    private const MEDIUM = 0.6;

    /**
     * Construit le schéma de relecture et y pose les indicateurs de confiance.
     *
     * La décoration se fait APRÈS la construction, via `getFlatFields()` : les
     * champs vivent dans des Grid, et descendre soi-même dans l'arbre revient à
     * demander leurs enfants à des composants qui n'ont pas encore de conteneur
     * — ce que Filament refuse.
     */
    public static function configure(Schema $schema, AnalysisResult $analysis): Schema
    {
        $schema->components([
            // Le bien est choisi à l'étape du dépôt ; il reste dans l'état car
            // les formulaires réutilisés l'interrogent pour savoir si le bien
            // est assujetti à la TVA.
            Hidden::make('property_id'),
            ...static::fieldsFor($analysis),
        ]);

        foreach ($schema->getFlatFields() as $field) {
            static::annotate($field, $analysis);
        }

        return $schema;
    }

    /**
     * Champs métier, repris tels quels des formulaires de saisie.
     *
     * @return array<int, Component>
     */
    public static function fieldsFor(AnalysisResult $analysis): array
    {
        return match ($analysis->entity()) {
            'furniture' => FurnitureForm::fields(),
            'work' => PropertyWorkForm::fields(),
            'loan' => static::loanFields(),
            // ⚠️ `statement` DOIT avoir sa branche, et son absence était un bug : il
            // tombait dans `default => ExpenseForm::fields()`, dont sept champs sont
            // `required()` et qu'aucune valeur de relevé ne remplit — or `confirm()`
            // appelle `getState()`, qui valide. Confirmer un relevé bancaire depuis
            // l'écran échouait donc sur quatre champs vides, et aucun test ne couvrait
            // ce chemin. Ses lignes vivent dans le tableau Blade, pas dans le schéma.
            'statement' => static::statementFields(),
            'earnings' => static::earningsFields($analysis),
            default => ExpenseForm::fields(),
        };
    }

    /**
     * Pastille de confiance + extrait du document en infobulle.
     *
     * Un champ que le modèle n'a pas renseigné n'est pas décoré : l'absence
     * d'indicateur veut dire « à saisir », ce qui est plus lisible qu'une
     * pastille rouge sur un champ vide.
     */
    private static function annotate(Field $field, AnalysisResult $analysis): void
    {
        $name = $field->getName();
        $confidence = $analysis->confidences[$name] ?? null;

        if ($confidence === null) {
            return;
        }

        $field
            ->hint(static::label($confidence))
            ->hintColor(static::color($confidence));

        if (filled($analysis->evidence[$name] ?? null)) {
            $field->hintIcon(
                'heroicon-o-document-magnifying-glass',
                tooltip: 'Lu : « ' . $analysis->evidence[$name] . ' »',
            );
        }
    }

    private static function label(float $confidence): string
    {
        return match (true) {
            $confidence >= self::HIGH => 'Lu avec certitude',
            $confidence >= self::MEDIUM => 'À vérifier',
            default => 'Peu fiable',
        };
    }

    private static function color(float $confidence): string
    {
        return match (true) {
            $confidence >= self::HIGH => 'success',
            $confidence >= self::MEDIUM => 'warning',
            default => 'danger',
        };
    }

    /**
     * En-tête d'un emprunt. Contrairement aux trois autres, LoanForm n'est pas
     * réutilisable : il enchaîne des étapes de wizard et déclenche la génération
     * du tableau théorique — exactement ce qu'un import doit éviter.
     *
     * @return array<int, Component>
     */
    /**
     * En-tête d'un relevé bancaire.
     *
     * Volontairement minimal : tout le contenu d'un relevé vit dans ses LIGNES, qui
     * sont pilotées par la vue et non par ce schéma. Les champs sont en lecture seule —
     * ils rappellent ce qui a été lu, ils ne se corrigent pas.
     *
     * @return array<int, Component>
     */
    private static function statementFields(): array
    {
        return [
            Grid::make(3)->schema([
                TextInput::make('bank_name')->label('Banque')->disabled()->dehydrated(),
                TextInput::make('period_start')->label('Du')->disabled()->dehydrated(),
                TextInput::make('period_end')->label('Au')->disabled()->dehydrated(),
            ]),
        ];
    }

    /**
     * En-tête d'un relevé annuel de plateforme.
     *
     * ⚠️ Champs DÉDIÉS, et pas une extraction d'IncomeForm : celui-ci décrit UNE
     * RÉSERVATION — code, voyageur, dates de séjour — dont un relevé annuel ne porte
     * rien. Ses `income_date` et `amount` n'auraient aucun sens en en-tête, les douze
     * montants vivant dans les lignes. Même raison que loanFields().
     *
     * @return array<int, Component>
     */
    private static function earningsFields(AnalysisResult $analysis): array
    {
        return [
            Grid::make(3)->schema([
                Select::make('source')
                    ->label('Plateforme')
                    ->options(Income::sourceLabels())
                    ->required(),
                // ⚠️ `required()` est un choix, pas un oubli : c'est le SEUL champ dont
                // dépendent les douze dates. Le laisser passer à null créerait des
                // recettes non datables, ou pire, datées d'une année fausse.
                TextInput::make('fiscal_year')
                    ->label('Année du relevé')
                    ->required()
                    ->numeric()
                    ->minValue(2000)
                    ->maxValue((int) date('Y') + 1)
                    ->helperText('Les recettes sont datées du dernier jour de leur mois.'),
                Select::make('tva_rate')
                    ->label('TVA')
                    ->options(TvaRate::options())
                    ->required(),
            ]),
        ];
    }

    private static function loanFields(): array
    {
        return [
            TextInput::make('bank_name')
                ->label('Banque')
                ->required(),
            Grid::make(2)->schema([
                TextInput::make('amount')
                    ->label('Capital emprunté')
                    ->suffix('€')
                    ->required()
                    ->numeric()
                    ->step(0.01)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                TextInput::make('annual_rate')
                    ->label('Taux annuel')
                    ->suffix('%')
                    ->required()
                    ->numeric()
                    ->step(0.001),
            ]),
            Grid::make(2)->schema([
                TextInput::make('duration_months')
                    ->label('Durée')
                    ->suffix('mois')
                    ->required()
                    ->numeric(),
                DatePicker::make('start_date')
                    ->label('Date d\'effet')
                    ->required()
                    ->displayFormat('d/m/Y')
                    ->helperText('La première échéance tombe un mois après cette date.'),
            ]),
            Grid::make(2)->schema([
                TextInput::make('monthly_payment')
                    ->label('Mensualité hors assurance')
                    ->suffix('€')
                    ->numeric()
                    ->step(0.01)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                TextInput::make('insurance_monthly')
                    ->label('Assurance mensuelle')
                    ->suffix('€')
                    ->numeric()
                    ->step(0.01)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
            ]),
            Select::make('insurance_type')
                ->label('Type d\'assurance')
                ->options(Loan::insuranceTypeLabels())
                ->default(Loan::INSURANCE_FIXED)
                ->required(),
        ];
    }
}
