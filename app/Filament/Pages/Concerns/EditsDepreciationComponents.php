<?php

namespace App\Filament\Pages\Concerns;

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\DepreciationService;
use Filament\Notifications\Notification;
use Livewire\Attributes\Computed;

/**
 * Éditeur de composants d'amortissement — logique serveur PARTAGÉE.
 *
 * Extrait de `DepreciationEditor` le 2026-09-04 : l'étape 3 de l'assistant de reprise
 * (`/reprise`) propose les deux mêmes modes (« Ventilation » et « Montants ») et doit
 * écrire par le même chemin. Dupliquer cette lecture/écriture aurait produit un
 * troisième éditeur, qui aurait divergé au premier correctif.
 *
 * L'hôte doit exposer une propriété publique `$propertyId`. La vue partagée est
 * `filament/partials/depreciation-editor-core.blade.php`.
 */
trait EditsDepreciationComponents
{
    #[Computed]
    public function editorData(): array
    {
        if (! $this->propertyId) {
            return ['empty' => true];
        }

        $property = Property::find($this->propertyId);
        if (! $property) {
            return ['empty' => true];
        }

        $depreciableBase = (int) $property->depreciable_base;

        // groupBy et non keyBy : deux composants homonymes existent (un utilisateur peut
        // renommer, ou ajouter « Toiture » deux fois). keyBy en écrasait un en silence,
        // ce qui, maintenant que l'écriture se fait par id, aurait perdu des données.
        $existing = $property->components->groupBy('name');
        $matchedIds = [];

        $components = [];
        foreach (DepreciationService::FULL_CATALOG as $catalog) {
            $match = $existing->get($catalog['name'])?->first();

            if ($match) {
                $matchedIds[] = $match->id;
                $components[] = self::lineFromComponent($match, $catalog['percentage'], $catalog['optional']);
            } else {
                $components[] = [
                    'id'                  => null,
                    'name'                => $catalog['name'],
                    'percentage'          => 0,
                    'baseAmount'          => 0,
                    'baseSource'          => PropertyComponent::BASE_SOURCE_PERCENTAGE,
                    'duration'            => $catalog['duration_years'],
                    'suggestedPercentage' => $catalog['percentage'],
                    'optional'            => $catalog['optional'],
                    'custom'              => false,
                    'enabled'             => ! $catalog['optional'],
                    'sortOrder'           => $catalog['sort_order'],
                    'cerfaCategory'       => PropertyComponent::cerfaCategoryForName($catalog['name']),
                    'startDate'           => null,
                    'openingCumul'        => 0,
                ];
            }
        }

        // Composants en base qui ne correspondent à aucune entrée du catalogue,
        // et les doublons d'un même nom : tous « personnalisés ».
        foreach ($property->components as $comp) {
            if (! in_array($comp->id, $matchedIds, true)) {
                $components[] = self::lineFromComponent($comp, (float) $comp->percentage, true, true);
            }
        }

        usort($components, fn ($a, $b) => $a['sortOrder'] <=> $b['sortOrder']);

        return [
            'empty'           => false,
            'depreciableBase' => $depreciableBase / 100,
            // En centimes : c'est en centimes que se vérifie l'invariant de ventilation.
            // Le raisonnement en euros flottants perdait des centimes dès que la base
            // n'était pas divisible par 100.
            'depreciableBaseCents' => $depreciableBase,
            'components'           => $components,
            'cerfaCategories'      => PropertyComponent::cerfaCategoryLabels(),
            // Défaut affiché dans la colonne « Début » : la mise en location du bien.
            'rentalStartDate'      => $property->rental_start_date?->format('Y-m-d'),
        ];
    }

    /** @return array<string, mixed> */
    private static function lineFromComponent(
        PropertyComponent $component,
        float $suggested,
        bool $optional,
        bool $custom = false,
    ): array {
        return [
            'id'                  => $component->id,
            'name'                => $component->name,
            'percentage'          => (float) $component->percentage,
            'baseAmount'          => (int) $component->base_amount,
            'baseSource'          => $component->base_source,
            'duration'            => $component->duration_years,
            'suggestedPercentage' => $suggested,
            'optional'            => $optional,
            'custom'              => $custom,
            'enabled'             => true,
            'sortOrder'           => $component->sort_order,
            'cerfaCategory'       => $component->cerfaCategory(),
            'startDate'           => $component->depreciation_start_date?->format('Y-m-d'),
            'openingCumul'        => (int) $component->opening_accumulated_depreciation,
        ];
    }

    public function updatedPropertyId(): void
    {
        unset($this->editorData);
        unset($this->properties);
        $this->dispatch('components-loaded', data: $this->editorData);
    }

    /**
     * Enregistre la ventilation.
     *
     * ⚠️ Méthode Livewire PUBLIQUE : tout ce qui arrive ici vient du navigateur et doit
     * être validé côté serveur. Jusqu'au 2026-09-03, la seule garde était l'arrondi de
     * Hamilton appliqué en JavaScript, et un `(int)` tronquait ensuite chaque pourcentage
     * — six composants à 16,67 % s'enregistraient donc à 96 % de la base.
     */
    public function saveComponents(array $components): void
    {
        if (! $this->propertyId) {
            return;
        }

        $property = Property::findOrFail($this->propertyId);

        $lines = [];
        foreach ($components as $comp) {
            $source = ($comp['baseSource'] ?? null) === PropertyComponent::BASE_SOURCE_MANUAL
                ? PropertyComponent::BASE_SOURCE_MANUAL
                : PropertyComponent::BASE_SOURCE_PERCENTAGE;

            $percentage = (float) ($comp['percentage'] ?? 0);
            $baseAmount = (int) ($comp['baseAmount'] ?? 0);

            // Un composant à nom libre reste possible, mais pas anonyme : sans nom, il
            // serait indistinguable dans la liasse comme dans l'écran lui-même.
            $name = trim((string) ($comp['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            // Une ligne décochée, ou vide dans son propre mode, n'est pas conservée.
            $keeps = ($comp['enabled'] ?? false)
                && ($source === PropertyComponent::BASE_SOURCE_MANUAL ? $baseAmount > 0 : $percentage > 0);

            if (! $keeps) {
                continue;
            }

            $lines[] = [
                'id'                  => isset($comp['id']) ? (int) $comp['id'] : null,
                'name'                => mb_substr($name, 0, 120),
                'duration_years'      => max(1, (int) ($comp['duration'] ?? 1)),
                'sort_order'          => (int) ($comp['sortOrder'] ?? 0),
                'base_source'         => $source,
                'percentage'          => $percentage,
                'base_amount'         => $baseAmount,
                'annual_depreciation' => isset($comp['annualDepreciation'])
                    ? (int) $comp['annualDepreciation']
                    : null,
                'cerfa_category'      => $comp['cerfaCategory'] ?? null,
                // ⚠️ `null` signifie « le navigateur n'a pas envoyé ce champ », et la
                // valeur en base est alors laissée telle quelle. La charge utile de
                // l'onglet Ventilation ne porte AUCUNE des trois colonnes de reprise :
                // convertir une absence en `0` effacerait un cumul repris à chaque
                // passage sur les curseurs, sans rien afficher.
                'depreciation_start_date' => self::sanitizeStartDate($comp['startDate'] ?? null),
                'opening_accumulated_depreciation' => isset($comp['openingCumul'])
                    ? max(0, (int) $comp['openingCumul'])
                    : null,
            ];
        }

        try {
            $result = app(DepreciationService::class)->syncComponents($property, $lines);
        } catch (\RuntimeException $e) {
            Notification::make()
                ->danger()
                ->title('Ventilation impossible')
                ->body($e->getMessage()
                    . ' Ajustez la part du terrain ou la valeur retenue sur la fiche du bien.')
                ->persistent()
                ->send();

            return;
        }

        unset($this->editorData);

        $remainder = (int) $result['remainder'];

        $notification = Notification::make()->title('Composants enregistrés');

        if ($remainder > 0) {
            // Sous-ventiler est légitime — un comptable peut n'avoir réparti qu'une part
            // de la base. On l'accepte, mais jamais en silence.
            $notification->warning()->body(sprintf(
                '%s € de base amortissable ne sont rattachés à aucun composant.',
                number_format($remainder / 100, 0, ',', ' '),
            ));
        } else {
            $notification->success();
        }

        $notification->send();
    }

    /**
     * Normalise la date de départ d'un composant venue du navigateur.
     *
     * Trois retours, trois sens distincts — et c'est volontaire :
     *   - `null`  : le champ n'a pas été envoyé, la valeur en base ne bouge pas ;
     *   - `''`    : le champ a été VIDÉ, le composant retombe sur la date du bien ;
     *   - `Y-m-d` : une date valide.
     * Une saisie illisible est traitée comme absente plutôt que propagée en base.
     */
    private static function sanitizeStartDate(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public function resetToDefaults(): void
    {
        if (! $this->propertyId) {
            return;
        }

        $property = Property::findOrFail($this->propertyId);

        $manualCount = $property->components()
            ->where('base_source', PropertyComponent::BASE_SOURCE_MANUAL)
            ->count();

        PropertyComponent::where('property_id', $this->propertyId)->delete();

        app(DepreciationService::class)->generateDefaultComponents($property);

        unset($this->editorData);
        $this->dispatch('components-loaded', data: $this->editorData);

        Notification::make()
            ->success()
            ->title('Composants réinitialisés')
            ->body($manualCount > 0
                ? sprintf(
                    'Les 6 composants standards ont été restaurés. %d base(s) saisie(s) à la main ont été perdues.',
                    $manualCount,
                )
                : 'Les 6 composants standards ont été restaurés.')
            ->send();
    }
}
