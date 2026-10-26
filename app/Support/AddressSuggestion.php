<?php

namespace App\Support;

/**
 * Traduction d'une réponse de la Base Adresse Nationale en suggestions exploitables.
 *
 * La BAN répond en GeoJSON : une collection de `features`, chacune portant ses attributs
 * dans `properties`. On n'en garde que ce qu'un formulaire de bien sait remplir, et on
 * écarte tout ce qui n'a pas de quoi le faire.
 *
 * ⚠️ `properties.name` est la VOIE seule (« 12 Rue de la Paix ») tandis que
 * `properties.label` y ajoute code postal et commune. C'est `label` qu'on affiche dans la
 * liste de suggestions — sans quoi deux « 12 Rue de la Paix » de deux villes différentes
 * seraient indiscernables — et `name` qu'on écrit dans le champ *Adresse*, pour ne pas y
 * recopier une ville qui a déjà son propre champ.
 */
class AddressSuggestion
{
    /**
     * @param  array<string, mixed>  $payload  réponse GeoJSON de la BAN
     * @return list<array{label: string, address: string, postal_code: string, city: string, insee_code: string}>
     */
    public static function fromBanPayload(array $payload): array
    {
        $suggestions = [];

        foreach ($payload['features'] ?? [] as $feature) {
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

            $label = trim((string) ($properties['label'] ?? ''));
            $city = trim((string) ($properties['city'] ?? ''));
            $postalCode = trim((string) ($properties['postcode'] ?? ''));

            // Sans libellé il n'y a rien à afficher, sans commune rien à remplir : une
            // suggestion incomplète coûte plus cher qu'une suggestion absente, elle laisse
            // croire au formulaire qu'il a été renseigné.
            if ($label === '' || $city === '') {
                continue;
            }

            $suggestions[] = [
                'label' => $label,
                'address' => trim((string) ($properties['name'] ?? '')) ?: $label,
                'postal_code' => $postalCode,
                'city' => $city,
                'insee_code' => trim((string) ($properties['citycode'] ?? '')),
            ];
        }

        return $suggestions;
    }
}
