<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Micro-BIC — abattements et plafonds (CGI art. 50-0)
    |--------------------------------------------------------------------------
    |
    | Barème triennal publié au BOI-BAREME-000044 et actualisé par le BOFiP
    | ACTU-2025-00127 (loi n° 2024-1039 du 19 novembre 2024 visant à renforcer les
    | outils de régulation des meublés de tourisme à l'échelle locale, art. 7) :
    | pour les revenus perçus à compter de 2026, l'ensemble des activités de
    | location en meublé relève d'un seuil de 83 600 € et d'un abattement de 50 %,
    | à l'exception des meublés de tourisme NON classés, qui restent à 15 000 €
    | et 30 %. Vérifié le 2026-10-01 sur bofip.impots.gouv.fr.
    |
    | Indexé par ANNÉE DE PERCEPTION DES REVENUS (= l'exercice fiscal de
    | l'application). Un millésime absent reprend le dernier connu : voir
    | App\Support\MicroBicRules::scheduleFor().
    |
    | Montants en CENTIMES. `rate` en points de pourcentage (entier).
    |
    */

    'schedule' => [

        // Avant la loi Le Meur : abattement 50 % / plafond 77 700 € pour le non
        // classé comme pour le classé. Conservé pour les exercices clôturés
        // antérieurs, jamais utilisé comme repli pour une année inconnue.
        2023 => [
            'furnished_tourism_unclassified' => ['rate' => 50, 'ceiling' => 7_770_000],
            'furnished_tourism_classified'   => ['rate' => 71, 'ceiling' => 18_870_000],
            'long_term'                      => ['rate' => 50, 'ceiling' => 7_770_000],
        ],

        2025 => [
            'furnished_tourism_unclassified' => ['rate' => 30, 'ceiling' => 1_500_000],
            'furnished_tourism_classified'   => ['rate' => 50, 'ceiling' => 7_770_000],
            'long_term'                      => ['rate' => 50, 'ceiling' => 7_770_000],
        ],

        // Révision triennale du barème (1er janvier 2026), applicable jusqu'en 2028.
        2026 => [
            'furnished_tourism_unclassified' => ['rate' => 30, 'ceiling' => 1_500_000],
            'furnished_tourism_classified'   => ['rate' => 50, 'ceiling' => 8_360_000],
            'long_term'                      => ['rate' => 50, 'ceiling' => 8_360_000],
        ],

    ],

];
