<?php

namespace App\Support;

class HelpContentRegistry
{
    private static array $pages = [
        'filament.admin.pages.dashboard' => ['view' => 'dashboard', 'title' => 'Tableau de bord'],
        'filament.admin.pages.simulator' => ['view' => 'simulator', 'title' => 'Simulateur'],
        'filament.admin.pages.projection' => ['view' => 'projection', 'title' => 'Projection pluriannuelle'],
        'filament.admin.pages.import-airbnb' => ['view' => 'import-airbnb', 'title' => 'Import Airbnb'],
        'filament.admin.pages.import-csv' => ['view' => 'import-csv', 'title' => 'Import CSV'],
        'filament.admin.pages.annual-import-wizard' => ['view' => 'annual-import-wizard', 'title' => 'Import annuel'],
        'filament.admin.pages.fiscal-year-wizard' => ['view' => 'fiscal-year-wizard', 'title' => 'Assistant exercice fiscal'],
        'filament.admin.pages.onboarding-wizard' => ['view' => 'onboarding-wizard', 'title' => 'Premier lancement'],
        'filament.admin.pages.reprise-dossier' => ['view' => 'reprise-dossier', 'title' => 'Reprendre un dossier existant'],
        'filament.admin.pages.loan-detail' => ['view' => 'loan-detail', 'title' => 'Détail emprunt'],
        'filament.admin.pages.teledeclaration' => ['view' => 'teledeclaration', 'title' => 'Télédéclaration'],
        'filament.admin.pages.badges' => ['view' => 'badges', 'title' => 'Badges'],
        'filament.admin.pages.depreciation-editor' => ['view' => 'property-components', 'title' => 'Composants'],
        'filament.admin.pages.vault' => ['view' => 'vault', 'title' => 'Coffre-fort'],
        'filament.admin.pages.help-page' => ['view' => 'help-page', 'title' => 'Aide'],
        'filament.admin.pages.import-document' => ['view' => 'import-document', 'title' => 'Importer un justificatif'],
        // ⚠️ Clé = le SLUG de la page (`nouveautes`), pas le nom de la classe `WhatsNew` :
        // Filament dérive le nom de route du slug. Se tromper ici est muet — repli sur
        // `help._fallback`, l'écran n'a plus d'aide et rien ne le signale.
        'filament.admin.pages.nouveautes' => ['view' => 'whats-new', 'title' => 'Version et nouveautés'],
        'filament.admin.pages.admin-stats' => ['view' => 'admin-stats', 'title' => 'Statistiques'],
        'filament.admin.pages.admin-ai' => ['view' => 'admin-ai', 'title' => 'Intelligence artificielle'],
        'filament.admin.pages.admin-mcp' => ['view' => 'admin-mcp', 'title' => 'API MCP'],
        'filament.admin.pages.admin-update' => ['view' => 'admin-update', 'title' => 'Mises à jour'],
        'filament.admin.pages.admin-invitations' => ['view' => 'admin-invitations', 'title' => 'Invitations'],
        'filament.admin.pages.linked-dossiers' => ['view' => 'linked-dossiers', 'title' => 'Dossiers liés'],
        'filament.admin.pages.system-status' => ['view' => 'system-status', 'title' => 'État du système'],
        'filament.admin.auth.profile' => ['view' => 'assistant', 'title' => 'Profil et assistant'],
    ];

    private static array $resources = [
        'filament.admin.resources.properties' => ['view' => 'properties', 'title' => 'Biens immobiliers'],
        'filament.admin.resources.incomes' => ['view' => 'incomes', 'title' => 'Recettes'],
        'filament.admin.resources.expenses' => ['view' => 'expenses', 'title' => 'Charges'],
        'filament.admin.resources.loans' => ['view' => 'loans', 'title' => 'Emprunts'],
        'filament.admin.resources.furniture' => ['view' => 'furniture', 'title' => 'Mobilier'],
        'filament.admin.resources.property-works' => ['view' => 'property-works', 'title' => 'Travaux'],
        'filament.admin.resources.fiscal-years' => ['view' => 'fiscal-years', 'title' => 'Exercices fiscaux'],
    ];

    /**
     * Trois questions d'exemple par page, proposées à l'ouverture de l'assistant.
     *
     * @var array<string, array<int, string>>
     */
    private static array $suggestions = [
        'filament.admin.pages.dashboard' => [
            'À quoi servent les indicateurs du tableau de bord ?',
            'Comment ajouter un deuxième bien ?',
            'Par où commencer quand je débute ?',
        ],
        'filament.admin.pages.simulator' => [
            'Comment fonctionne la comparaison micro-BIC / réel ?',
            'Quels montants dois-je saisir dans le simulateur ?',
            'Pourquoi le simulateur me conseille le régime réel ?',
        ],
        'filament.admin.pages.projection' => [
            'Que montre la projection pluriannuelle ?',
            'Pourquoi mon résultat devient positif dans quelques années ?',
            'Comment sont projetés les amortissements ?',
        ],
        'filament.admin.pages.import-airbnb' => [
            'Quel fichier CSV Airbnb dois-je importer ?',
            'Que faire si des recettes sont importées en double ?',
            'Comment sont traités les montants négatifs ?',
        ],
        'filament.admin.pages.annual-import-wizard' => [
            'À quoi sert l\'import annuel ?',
            'Dans quel ordre importer mes fichiers ?',
            'Puis-je relancer un import déjà effectué ?',
        ],
        'filament.admin.pages.fiscal-year-wizard' => [
            'Que dois-je faire avant de clôturer un exercice ?',
            'Pourquoi je ne peux pas créer l\'exercice suivant ?',
            'Comment corriger un exercice déjà clôturé ?',
        ],
        'filament.admin.pages.onboarding-wizard' => [
            'Quelles informations préparer avant de commencer ?',
            'Puis-je reprendre l\'assistant plus tard ?',
            'Comment saisir la valeur vénale de mon bien ?',
        ],
        'filament.admin.pages.loan-detail' => [
            'Comment est calculé le tableau d\'amortissement ?',
            'Quelle part de mon emprunt est déductible ?',
            'Comment saisir une assurance emprunteur ?',
        ],
        'filament.admin.pages.teledeclaration' => [
            'Que contient la liasse fiscale générée ?',
            'Comment transmettre ma déclaration ?',
            'Que faire du fichier FEC ?',
        ],
        'filament.admin.pages.badges' => [
            'À quoi servent les badges ?',
            'Comment débloquer un badge ?',
            'Les badges ont-ils un effet sur ma comptabilité ?',
        ],
        'filament.admin.pages.help-page' => [
            'Que contient le guide complet ?',
            'Où trouver la FAQ ?',
            'Comment contacter le support ?',
        ],
        'filament.admin.pages.import-document' => [
            'Quels documents puis-je déposer ?',
            'Que veut dire « à vérifier » sur un champ ?',
            'Un document analysé compte-t-il dans mon quota si je l\'annule ?',
        ],
        'filament.admin.pages.linked-dossiers' => [
            'Qu\'est-ce qu\'un dossier lié ?',
            'Comment ouvrir un dossier lié que j\'ai déjà créé ?',
            'Puis-je changer le SIREN d\'un dossier déjà créé ?',
        ],
        'filament.admin.pages.admin-stats' => [
            'Que montrent ces statistiques ?',
            'D\'où viennent ces chiffres ?',
            'Comment sauvegarder mes données ?',
        ],
        'filament.admin.pages.admin-ai' => [
            'Quelle différence entre un refus pour quota et un refus des garde-fous ?',
            'Comment est calculé le coût moyen par document ?',
            'Comment changer le modèle ou les quotas ?',
        ],
        'filament.admin.pages.admin-mcp' => [
            'À quoi sert le journal d\'audit MCP ?',
            'Comment désactiver l\'API MCP pour un utilisateur ?',
            'Que contient un jeton MCP ?',
        ],
        'filament.admin.pages.admin-update' => [
            'Comment mettre à jour OpenLMNP ?',
            'Que se passe-t-il pour mes données lors d\'une mise à jour ?',
            'Comment connaître ma version actuelle ?',
        ],
        'filament.admin.pages.system-status' => [
            'Que vérifie la page État du système ?',
            'Où sont stockées mes données en auto-hébergé ?',
            'Comment activer le serveur MCP ?',
        ],
        'filament.admin.auth.profile' => [
            'Que fait exactement l\'assistant ?',
            'Que se passe-t-il si j\'autorise l\'assistant à consulter mes données ?',
            'À quoi sert le mode de navigation ?',
        ],
        'filament.admin.resources.properties' => [
            'Quelle valeur saisir dans « valeur vénale » ?',
            'À quoi sert la quote-part ?',
            'Comment répartir mon bien en composants ?',
        ],
        'filament.admin.resources.incomes' => [
            'Dois-je saisir les loyers avant ou après commission ?',
            'Où saisir la taxe de séjour ?',
            'Comment importer mes recettes Airbnb ?',
        ],
        'filament.admin.resources.expenses' => [
            'Où saisir une facture d\'assurance ?',
            'Quelle catégorie pour la taxe foncière ?',
            'Quelle différence entre une charge dédiée et partagée ?',
        ],
        'filament.admin.resources.loans' => [
            'Comment saisir mon emprunt ?',
            'Les intérêts sont-ils déduits automatiquement ?',
            'Comment gérer un remboursement anticipé ?',
        ],
        'filament.admin.resources.furniture' => [
            'Sur combien d\'années le mobilier est-il amorti ?',
            'À partir de quel montant amortir un meuble ?',
            'Comment saisir un lot de petit équipement ?',
        ],
        'filament.admin.resources.property-components' => [
            'À quoi sert l\'amortissement par composant ?',
            'Quelles durées utiliser pour chaque composant ?',
            'Puis-je modifier la répartition proposée ?',
        ],
        'filament.admin.resources.property-works' => [
            'Quelle différence entre travaux et charge d\'entretien ?',
            'Sur combien d\'années amortir des travaux ?',
            'Comment joindre la facture des travaux ?',
        ],
        'filament.admin.resources.fiscal-years' => [
            'Que dois-je vérifier avant de clôturer ?',
            'Pourquoi mon amortissement est-il plafonné ?',
            'Comment est calculé le résultat fiscal ?',
        ],
    ];

    /**
     * @var array<int, string>
     */
    private static array $defaultSuggestions = [
        'Comment saisir une charge ?',
        'Que dois-je faire avant de clôturer un exercice ?',
        'Comment importer mes recettes Airbnb ?',
    ];

    /**
     * Toutes les pages documentées, indexées par nom de route canonique.
     *
     * @return array<string, array{view: string, title: string}>
     */
    public static function all(): array
    {
        $entries = [];

        foreach ([self::$pages, self::$resources] as $group) {
            foreach ($group as $routeName => $entry) {
                $entries[$routeName] = self::withViewPrefix($entry);
            }
        }

        return $entries;
    }

    /**
     * Ramène un nom de route au nom canonique du registre (suffixe de resource retiré).
     */
    public static function normalize(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        if (isset(self::$pages[$routeName])) {
            return $routeName;
        }

        $base = preg_replace('/\.(index|create|edit)$/', '', $routeName);

        return isset(self::$resources[$base]) ? $base : null;
    }

    /**
     * @return array<int, string>
     */
    public static function suggestions(?string $routeName): array
    {
        $canonical = self::normalize($routeName);

        return self::$suggestions[$canonical] ?? self::$defaultSuggestions;
    }

    public static function resolve(?string $routeName): array
    {
        if (! $routeName) {
            return ['view' => 'help._fallback', 'title' => 'Aide'];
        }

        // Filament dérive le nom d'une Page de son slug, SEGMENT DE PARAMÈTRE COMPRIS :
        // le slug `depreciation-editor/{propertyId?}` donne la route
        // `filament.admin.pages.depreciation-editor.{propertyId?}`. Sans cette
        // normalisation, une telle page ne peut pas être recensée ici — et l'échec est
        // muet, `resolve()` retombant sur le fallback. C'est ce qui avait prive
        // l'éditeur d'amortissements de toute aide contextuelle.
        $routeName = preg_replace('/\.\{[^}]*\}$/', '', $routeName);

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
