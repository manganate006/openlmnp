<?php

namespace App\Filament\Pages\Concerns;

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\DepreciationService;
use App\Support\Fr;
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
            // ⚠️ Écart DÉJÀ EN BASE à l'ouverture de l'écran, en centimes, positif seulement
            // si les composants dépassent la base. Jusqu'ici l'incohérence ne se voyait qu'à
            // l'enregistrement — et cet enregistrement était REFUSÉ, laissant l'utilisateur
            // enfermé : il ne pouvait ni sauvegarder, ni comprendre pourquoi, et sa seule
            // sortie était une commande console qu'un utilisateur du cloud n'a pas.
            // Signalé par cocool97 (issue #11), 4 335 € d'écart après une baisse du prix.
            'overAllocationCents'  => max(0, app(DepreciationService::class)->overAllocation($property)),
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
            // ⚠️ La dotation d'une ligne saisie en montants voyage avec elle. Absente jusqu'au
            // 2026-10-15, l'éditeur la recalculait (base ÷ durée) à l'affichage, puis
            // l'enregistrait ainsi au premier clic sur « Enregistrer » : la dotation lue sur la
            // liasse du cabinet était remplacée par un calcul sur une durée elle-même arrondie.
            // `null` en ventilation : la dotation y suit toujours la base.
            'annualDepreciation'  => $component->base_source === PropertyComponent::BASE_SOURCE_MANUAL
                ? (int) $component->annual_depreciation
                : null,
            'duration'            => $component->duration_years,
            'suggestedPercentage' => $suggested,
            'optional'            => $optional,
            'custom'              => $custom,
            'enabled'             => true,
            'sortOrder'           => $component->sort_order,
            'cerfaCategory'       => $component->cerfaCategory(),
            'startDate'           => $component->depreciation_start_date?->format('Y-m-d'),
            'openingCumul'        => (int) $component->opening_accumulated_depreciation,
            'openingYear'         => $component->opening_accumulated_year,
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
                'opening_accumulated_year' => isset($comp['openingYear'])
                    ? max(0, (int) $comp['openingYear'])
                    : null,
            ];
        }

        // ⚠️ `syncComponents()` est une RÉCONCILIATION : tout composant absent du lot
        // soumis est SUPPRIMÉ. Le remplacement est voulu (c'est ce qui permet de
        // recopier un plan de cabinet par-dessus la ventilation par défaut), mais il se
        // faisait en silence — le service rend un compteur `deleted` que personne ne
        // lisait. Constaté en vrai le 2026-09-24 : 6 composants ramenés à 1, sans un mot.
        // Les noms se relèvent AVANT l'écriture : après, les lignes n'existent plus.
        $submittedIds = array_values(array_filter(array_map(
            fn (array $line) => $line['id'] ?? null,
            $lines,
        )));

        $removed = $property->components()
            ->whereNotIn('id', $submittedIds ?: [0])
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();

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
        $messages = [];

        if ($removed !== []) {
            // Nommés ET comptés : « 5 composants supprimés » sans les noms laisserait
            // l'utilisateur deviner lesquels, et le bandeau de ventilation ne parle que
            // de ventilation, jamais de suppression.
            $messages[] = sprintf(
                '%s %s : %s.',
                Fr::accorde(count($removed), 'composant'),
                Fr::nom(count($removed), 'supprimé'),
                implode(', ', $removed),
            );
        }

        if ($remainder > 0) {
            // Sous-ventiler est légitime — un comptable peut n'avoir réparti qu'une part
            // de la base. On l'accepte, mais jamais en silence.
            $messages[] = sprintf(
                '%s € de base amortissable ne sont rattachés à aucun composant.',
                number_format($remainder / 100, 0, ',', ' '),
            );
        }

        if ($removed !== [] || $remainder > 0) {
            $notification->warning()->body(implode(' ', $messages));
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

        // Format français EN PREMIER, et explicitement : `Carbon::parse('01/06/2019')`
        // rend le 6 janvier, strtotime lisant les barres obliques à l'américaine. Même
        // règle que `RepriseDossier::parseDate()`.
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = \Illuminate\Support\Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            // Aller-retour : `createFromFormat` accepte « 31/02/2019 » en débordant sur
            // mars. On refuse ce que l'utilisateur n'a pas écrit.
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
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

    /**
     * Recale toute la ventilation sur la base amortissable courante.
     *
     * Pendant in-app de `openlmnp:repair-components --fix --all`, pour les instances sans
     * accès console — c'est-à-dire tous les comptes du cloud. Réécrit chaque composant depuis
     * son POURCENTAGE, bases saisies à la main comprises : c'est ce que l'utilisateur demande
     * en cliquant, et l'écran le dit avant de le faire.
     */
    public function realignToBase(): void
    {
        $property = Property::find($this->propertyId);

        if (! $property) {
            return;
        }

        $ecart = app(DepreciationService::class)->overAllocation($property);

        if ($ecart <= 0) {
            Notification::make()
                ->success()
                ->title('Rien à recaler')
                ->body('La ventilation tient déjà dans la base amortissable.')
                ->send();

            return;
        }

        $recales = app(DepreciationService::class)->realignAllToBase($property);

        unset($this->editorData);

        // ⚠️ Le conteneur de l'éditeur porte `wire:ignore` : Livewire n'y re-rend RIEN, et le
        // composant Alpine garde les données de son montage. Sans ce dispatch, le bandeau
        // disparaissait et la notification annonçait le succès pendant que les montants
        // affichés restaient les anciens — et un enregistrement depuis cet écran aurait
        // RÉÉCRIT la dérive qu'on vient de corriger. Trouvé au navigateur : aucun test
        // Livewire ne voit ce que voit l'utilisateur devant l'écran.
        // `updatedPropertyId()` fait déjà exactement ça au changement de bien.
        $this->dispatch('components-loaded', data: $this->editorData);

        if ($recales === 0) {
            Notification::make()
                ->danger()
                ->title('Recalage impossible')
                ->body('Vérifiez la valeur retenue et la part du terrain sur la fiche du bien.')
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Ventilation recalée')
            ->body(sprintf(
                '%d composant(s) recalculés sur la base actuelle. L\'excédent de %s € a disparu.',
                $recales,
                number_format($ecart / 100, 0, ',', ' '),
            ))
            ->send();
    }
}
