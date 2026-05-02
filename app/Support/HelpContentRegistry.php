<?php

namespace App\Support;

class HelpContentRegistry
{
    private static array $pages = [
        'filament.admin.pages.dashboard' => ['view' => 'dashboard', 'title' => 'Tableau de bord'],
        'filament.admin.pages.simulator' => ['view' => 'simulator', 'title' => 'Simulateur'],
        'filament.admin.pages.projection' => ['view' => 'projection', 'title' => 'Projection pluriannuelle'],
        'filament.admin.pages.import-airbnb' => ['view' => 'import-airbnb', 'title' => 'Import Airbnb'],
        'filament.admin.pages.annual-import-wizard' => ['view' => 'annual-import-wizard', 'title' => 'Import annuel'],
        'filament.admin.pages.fiscal-year-wizard' => ['view' => 'fiscal-year-wizard', 'title' => 'Assistant exercice fiscal'],
        'filament.admin.pages.onboarding-wizard' => ['view' => 'onboarding-wizard', 'title' => 'Premier lancement'],
        'filament.admin.pages.loan-detail' => ['view' => 'loan-detail', 'title' => 'Détail emprunt'],
        'filament.admin.pages.teledeclaration' => ['view' => 'teledeclaration', 'title' => 'Télédéclaration'],
        'filament.admin.pages.badges' => ['view' => 'badges', 'title' => 'Badges'],
        'filament.admin.pages.help-page' => ['view' => 'help-page', 'title' => 'Aide'],
    ];

    private static array $resources = [
        'filament.admin.resources.properties' => ['view' => 'properties', 'title' => 'Biens immobiliers'],
        'filament.admin.resources.incomes' => ['view' => 'incomes', 'title' => 'Recettes'],
        'filament.admin.resources.expenses' => ['view' => 'expenses', 'title' => 'Charges'],
        'filament.admin.resources.loans' => ['view' => 'loans', 'title' => 'Emprunts'],
        'filament.admin.resources.furniture' => ['view' => 'furniture', 'title' => 'Mobilier'],
        'filament.admin.resources.property-components' => ['view' => 'property-components', 'title' => 'Composants'],
        'filament.admin.resources.property-works' => ['view' => 'property-works', 'title' => 'Travaux'],
        'filament.admin.resources.fiscal-years' => ['view' => 'fiscal-years', 'title' => 'Exercices fiscaux'],
    ];

    public static function resolve(?string $routeName): array
    {
        if (! $routeName) {
            return ['view' => 'help._fallback', 'title' => 'Aide'];
        }

        // Direct match for pages
        if (isset(self::$pages[$routeName])) {
            $entry = self::$pages[$routeName];

            return self::withViewPrefix($entry);
        }

        // Resource routes: strip .index/.create/.edit suffix
        $base = preg_replace('/\.(index|create|edit)$/', '', $routeName);
        if (isset(self::$resources[$base])) {
            $entry = self::$resources[$base];

            return self::withViewPrefix($entry);
        }

        return ['view' => 'help._fallback', 'title' => 'Aide'];
    }

    private static function withViewPrefix(array $entry): array
    {
        $view = "help.{$entry['view']}";

        if (! view()->exists($view)) {
            $view = 'help._fallback';
        }

        return ['view' => $view, 'title' => $entry['title']];
    }
}
