<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Lease;
use App\Models\PropertyWork;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Les enregistrements qui portent des justificatifs, en un seul exemplaire.
 *
 * ⚠️ Cette liste vivait recopiée à six endroits : l'export ZIP des justificatifs, son
 * filtre sur l'écran des exercices, l'outil MCP d'export, celui de dépôt, l'export du
 * dossier et la migration du coffre. Ouvrir les pièces aux recettes et aux baux (#24)
 * en en oubliant un seul, c'était une pièce qui se dépose et qui ne ressort jamais de
 * l'export, sans rien pour le signaler. `DocumentOwnersTest` vérifie que tout
 * formulaire qui affiche des pièces justificatives est bien inscrit ici.
 */
final class DocumentOwners
{
    /**
     * Clé publique (MCP, filtre d'export) => classe, dossier dans l'archive, libellé,
     * et la date qui range une pièce sans date dans son année.
     *
     * @var array<string, array{class: class-string<Model>, folder: string, label: string, date: string}>
     */
    private const OWNERS = [
        'expense'   => ['class' => Expense::class,      'folder' => 'charges',  'label' => 'Charges',  'date' => 'expense_date'],
        'furniture' => ['class' => Furniture::class,    'folder' => 'mobilier', 'label' => 'Mobilier', 'date' => 'purchase_date'],
        'work'      => ['class' => PropertyWork::class, 'folder' => 'travaux',  'label' => 'Travaux',  'date' => 'work_date'],
        'income'    => ['class' => Income::class,       'folder' => 'recettes', 'label' => 'Recettes', 'date' => 'income_date'],
        'lease'     => ['class' => Lease::class,        'folder' => 'baux',     'label' => 'Baux',     'date' => 'start_date'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::OWNERS);
    }

    /** Pour un message d'erreur : « expense, furniture, work, income ou lease ». */
    public static function keyList(): string
    {
        $keys = self::keys();
        $last = array_pop($keys);

        return implode(', ', $keys) . ' ou ' . $last;
    }

    /** @return array<string, string> clé => libellé */
    public static function labels(): array
    {
        return array_map(fn (array $owner) => $owner['label'], self::OWNERS);
    }

    /** @return list<class-string<Model>> */
    public static function classes(): array
    {
        return array_column(self::OWNERS, 'class');
    }

    /** @return class-string<Model>|null */
    public static function classFor(string $key): ?string
    {
        return self::OWNERS[$key]['class'] ?? null;
    }

    /** Dossier de l'archive ; `autre` pour un porteur inconnu, comme avant. */
    public static function folderFor(string $class): string
    {
        foreach (self::OWNERS as $owner) {
            if ($owner['class'] === $class) {
                return $owner['folder'];
            }
        }

        return 'autre';
    }

    public static function dateOf(?Model $record): ?CarbonInterface
    {
        foreach (self::OWNERS as $owner) {
            if ($record instanceof $owner['class']) {
                return $record->{$owner['date']};
            }
        }

        return null;
    }

    /**
     * Identifiants des porteurs rattachés à ces biens, par classe. Sans les scopes
     * globaux : le coffre et l'export du dossier tournent sans session.
     *
     * @param  iterable<int>  $propertyIds
     * @param  list<class-string<Model>>|null  $classes
     * @return array<class-string<Model>, Collection<int, int>>
     */
    public static function ownedIds(iterable $propertyIds, ?array $classes = null): array
    {
        $propertyIds = collect($propertyIds)->all();
        $owned = [];

        foreach ($classes ?? self::classes() as $class) {
            $owned[$class] = $class::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->pluck('id');
        }

        return $owned;
    }
}
