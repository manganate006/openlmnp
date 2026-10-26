<?php

namespace App\Support;

/**
 * Accords du français que l'interface écrit à la main.
 *
 * ⚠️ `Str::plural()` est un pluraliseur ANGLAIS : il rend « sas » pour « sa » et
 * « sons » pour « son ». Il tombe juste sur « jours » ou « biens » par coïncidence,
 * pas par compétence — d'où cette poignée de fonctions plutôt qu'un appel de plus.
 *
 * ⚠️ Et la règle n'est pas celle de l'anglais : **zéro prend le SINGULIER**
 * (« 0 jour », là où l'anglais écrit « 0 days »). C'est `abs($nombre) > 1` qui la
 * porte, et c'est la raison pour laquelle un `> 1` naïf sur un compteur signé ne
 * suffirait pas.
 */
final class Fr
{
    /**
     * Le nombre et son nom, accordés ensemble : « 1 jour », « 7 jours ».
     *
     * Les séparer est précisément le défaut qu'on cherche à empêcher : une vue qui
     * écrit `{{ $n }} jours` affiche « 1 jours » le jour où le réglage vaut 1.
     */
    public static function accorde(int $nombre, string $singulier, ?string $pluriel = null): string
    {
        return $nombre.' '.self::nom($nombre, $singulier, $pluriel);
    }

    /**
     * Le nom seul, accordé — quand le nombre est déjà écrit à côté (ou remplacé par
     * un déterminant : « votre bien » contre « vos 2 biens »).
     *
     * @param  string|null  $pluriel  à fournir pour tout pluriel irrégulier (« journal »
     *                                → « journaux »), le défaut se contentant d'un « s ».
     */
    public static function nom(int $nombre, string $singulier, ?string $pluriel = null): string
    {
        return abs($nombre) > 1 ? ($pluriel ?? $singulier.'s') : $singulier;
    }
}
