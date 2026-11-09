<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un repère de loyer d'annonce, par commune et par type de bien — la « Carte des loyers »
 * (ANIL, data.gouv.fr, Licence Ouverte 2.0). Voir `App\Services\OpenData\RentMapSource` pour
 * la lecture du CSV et `App\Console\Commands\RefreshRentMap` pour l'import.
 *
 * ⚠️ Donnée de RÉFÉRENCE publique, partagée par tous les utilisateurs : contrairement au
 * reste du modèle métier, ce modèle ne porte AUCUN scope utilisateur.
 *
 * ⚠️ `per_m2_cents` mesure le loyer d'une location NON MEUBLÉE, charges comprises — jamais
 * une recette de meublé. Ne JAMAIS l'injecter dans un calcul fiscal ni dans le simulateur
 * lui-même : c'est un repère AFFICHÉ, séparé, jamais un terme d'une formule.
 */
class RentMapIndicator extends Model
{
    protected $fillable = [
        'insee_code',
        'property_type',
        'vintage',
        'per_m2_cents',
        'low_per_m2_cents',
        'high_per_m2_cents',
        'observations',
        'mesh_observations',
        'prediction_level',
        'r2',
    ];

    protected function casts(): array
    {
        return [
            'vintage' => 'integer',
            'per_m2_cents' => 'integer',
            'low_per_m2_cents' => 'integer',
            'high_per_m2_cents' => 'integer',
            'observations' => 'integer',
            'mesh_observations' => 'integer',
            'r2' => 'float',
        ];
    }

    /**
     * Le repère du bien, si sa commune est connue (`insee_code` déjà résolu — typiquement par
     * l'action « Estimer (DVF) », qui est la SEULE écriture de cette colonne) et couverte par
     * la source.
     *
     * ⚠️ Ne fait QUE lire la base : aucun appel réseau. Résoudre une commune inconnue ici
     * romprait la garantie « aucune requête HTTP au rendu du simulateur ».
     */
    public static function forProperty(Property $property): ?self
    {
        if ($property->insee_code === null) {
            return null;
        }

        return self::query()
            ->where('insee_code', $property->insee_code)
            ->where('property_type', \App\Services\OpenData\RentMapSource::typeForPropertyType($property->type))
            ->first();
    }

    /** Vrai quand la source elle-même invite à la prudence sur cette ligne. */
    public function callsForCaution(): bool
    {
        return \App\Services\OpenData\RentMapSource::callsForCaution([
            'r2' => $this->r2,
            'observations' => $this->observations,
        ]);
    }

    /**
     * Millésime RÉELLEMENT en base — le plus récent présent dans la table — ou `null` si elle
     * est vide (aucun import n'a jamais réussi). C'est CE millésime qui doit être affiché
     * partout (simulateur, outil MCP), jamais `RentMapSource::VINTAGE`, qui n'est qu'un repli.
     */
    public static function currentVintage(): ?int
    {
        $vintage = self::query()->max('vintage');

        return $vintage !== null ? (int) $vintage : null;
    }
}
