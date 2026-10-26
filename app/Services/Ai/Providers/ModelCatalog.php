<?php

namespace App\Services\Ai\Providers;

/**
 * Catalogue des modèles proposés, et pays de leur éditeur.
 *
 * ⚠️ Deux pays, jamais un seul. Celui de l'ÉDITEUR du modèle et celui du
 * service qui ROUTE la requête sont distincts : un modèle français appelé via
 * OpenRouter transite par un intermédiaire américain. N'afficher que le premier
 * laisserait croire à une souveraineté qui n'existe pas — c'est la même
 * discipline que pour la politique de conservation, qu'on ne décrit jamais à
 * l'indicatif à la place d'un tiers.
 *
 * Ce catalogue ne porte AUCUN prix. Les tarifs changent tous les mois : une
 * table figée dans le code serait fausse en quelques semaines, et un chiffre
 * faux vaut moins que pas de chiffre du tout (même règle que `model_prices`,
 * volontairement vide). Les notes décrivent donc un usage, pas un coût absolu.
 */
final class ModelCatalog
{
    /** Drapeaux, par code pays. `self` = le modèle tourne chez l'utilisateur. */
    private const FLAGS = [
        'FR' => '🇫🇷',
        'US' => '🇺🇸',
        'CN' => '🇨🇳',
        'CA' => '🇨🇦',
        'IL' => '🇮🇱',
        'GB' => '🇬🇧',
        'DE' => '🇩🇪',
        'self' => '🏠',
    ];

    private const COUNTRY_NAMES = [
        'FR' => 'France',
        'US' => 'États-Unis',
        'CN' => 'Chine',
        'CA' => 'Canada',
        'IL' => 'Israël',
        'GB' => 'Royaume-Uni',
        'DE' => 'Allemagne',
        'self' => 'chez vous',
    ];

    /**
     * Options du sélecteur de modèle, pour un fournisseur et un rôle.
     *
     * La valeur enregistrée reste l'identifiant brut : le catalogue n'est
     * qu'une aide au choix, il ne change rien à ce qui part sur le réseau.
     *
     * @param  string  $feature  documents | chat
     * @return array<string, string>
     */
    public static function options(AiProvider $provider, string $feature): array
    {
        $entrees = (array) config("ai.providers.{$provider->key()}.catalog.{$feature}", []);
        $options = [];

        foreach ($entrees as $entree) {
            $model = (string) ($entree['model'] ?? '');

            if ($model === '') {
                continue;
            }

            $libelle = self::flag((string) ($entree['country'] ?? '')) . ' ' . ($entree['label'] ?? $model);
            $note = trim((string) ($entree['note'] ?? ''));

            $options[$model] = $note === '' ? $libelle : "{$libelle} — {$note}";
        }

        return $options;
    }

    /**
     * Drapeau de l'éditeur d'un identifiant de modèle.
     *
     * Le préfixe d'éditeur n'existe que chez les intermédiaires (`mistralai/…`).
     * Chez un fournisseur qui sert ses propres modèles, l'éditeur est le
     * fournisseur : c'est son pays qui répond.
     */
    public static function flagForModel(string $model, ?AiProvider $provider = null): string
    {
        return self::flag(self::countryForModel($model, $provider));
    }

    public static function countryForModel(string $model, ?AiProvider $provider = null): string
    {
        $model = strtolower(trim($model));

        foreach ((array) config('ai.model_publishers', []) as $prefixe => $pays) {
            if (str_starts_with($model, strtolower((string) $prefixe))) {
                return (string) $pays;
            }
        }

        if ($provider === null) {
            return '';
        }

        return (string) config("ai.providers.{$provider->key()}.country", '');
    }

    /** Pays du service qui route la requête — pas celui qui édite le modèle. */
    public static function countryForProvider(AiProvider $provider): string
    {
        return (string) config("ai.providers.{$provider->key()}.country", '');
    }

    public static function flag(string $country): string
    {
        return self::FLAGS[$country] ?? '🏳️';
    }

    /** Nom du pays, écrit : un drapeau seul ne se lit pas partout (Windows). */
    public static function countryName(string $country): string
    {
        return self::COUNTRY_NAMES[$country] ?? 'origine inconnue';
    }

    /** « 🇫🇷 France », ou une chaîne vide si le pays est inconnu. */
    public static function badge(string $country): string
    {
        return $country === '' ? '' : self::flag($country) . ' ' . self::countryName($country);
    }
}
