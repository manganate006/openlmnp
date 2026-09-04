# Couverture de tests

OpenLMNP est couvert par **577 tests automatisés (1 584 assertions)** écrits avec
[Pest PHP](https://pestphp.com). La suite s'exécute à chaque push via
[GitHub Actions](https://github.com/manganate006/openlmnp/actions/workflows/tests.yml).

## Lancer les tests

```bash
# Tous les tests
vendor/bin/pest

# Par catégorie
vendor/bin/pest --filter="Depreciation"
vendor/bin/pest --filter="FiscalYear"
vendor/bin/pest --filter="Loan"
vendor/bin/pest --filter="Airbnb"
vendor/bin/pest --filter="Fec"
vendor/bin/pest --filter="Filament"
```

## Détail par suite

| Suite | Tests | Couverture |
|-------|------|------------|
| DepreciationService | 5 | Composants, base amortissable, quote-part, prorata |
| FiscalYearService | 6 | Résultat fiscal, plafonnement, quote-part charges, micro-BIC |
| FiscalYearChain | 12 | Chaîne d'exercices : première année, année proposée, validation N−1 |
| LoanService | 3 | Tableau amortissement, capital restant, intérêts déductibles |
| AirbnbImportService | 7 | CSV FR/EN, doublons, montants négatifs, format européen, plafond de taille (lecture des montants et dates déléguée à `Csv\CsvValues`) |
| FecService | 2 | 18 colonnes, format légal |
| TaxReturnService | 1 | Génération PDF liasse fiscale |
| AccountingEntryService | 3 | Écritures équilibrées, comptes PCG, quote-part |
| BadgeService | 15 | Attribution, dédoublonnage, heatmap, score |
| TVA (helper + déclaration) | 11 | TVA collectée/déductible, trimestriel, calculs HT/TTC |
| Serveur MCP | 18 | Auth, isolation données, CRUD, justificatifs (garde anti-SSRF : IP privées + redirections), audit |
| API MCP démo (lecture seule) | 11 | Jeton démo public, écritures bloquées, quota de requêtes |
| Pages Filament | 43 | Auth, CRUD, simulateur, projection, isolation données |
| Navigation & liens | 19 | Orphelins, liens, modes Simple/Avancé/Guidé, badges, page de connexion |
| Wizards | 8 | Onboarding, clôture fiscale, emprunt, import annuel |
| Mode démo | 7 | Sandbox éphémère isolé par visiteur, purge automatique |
| Isolation multi-utilisateurs | 10 | Scopes via le bien, modèles enfants, page loan-detail |
| Mesure d'audience opt-in (GTM) | 21 | Désactivée par défaut, injection conditionnelle |
| API de provisioning | 11 | Jeton requis (404/401), création idempotente, suspension idempotente (réponse uniforme) |
| Inscription (RegistrationGate) | 8 | Mode auto (fermée après le premier compte), démo exclue, true/false |
| Commande reset-password | 4 | Lien de réinitialisation, --password, validations |
| Télémétrie instances (opt-out) | 4 | Check-in anonyme, désactivable, silencieux sur erreur |
| Page politique de confidentialité | 2 | Rendu et accessibilité de la page légale |
| En-têtes de sécurité (F8) | 1 | X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy |
| Smoke (framework) | 2 | Amorçage de l'application |
| Soldes d'ouverture (reprise) | 15 | Report repris d'une liasse N-1, N-1 réel prioritaire, N-1 vide signalé, cumul de contrôle inerte, exposition MCP |
| Déficits reportables et 2033-D | 22 | Imputation FIFO par millésime, péremption à 10 ans, ordre amortissements différés → déficits, cases 982/983/984, commande de réparation, encart d'information |
| Contrôle de reprise | 16 | Seuils 1 € / 1 %, un cas par diagnostic, lignes recopiées vs reconstituées, exercice N-1 vide |
| Fidélité du plan d'amortissement | 15 | Date de départ par composant, catégorie Cerfa explicite (et rétro-classement qui ne déplace rien), traitement et durée des frais d'acquisition, dotations recopiées d'une liasse, cumuls d'ouverture qui n'entrent jamais dans la dotation |
| Éditeur d'amortissements — reprise | 5 | Composant à nom libre, refus d'un composant sans nom, colonnes de reprise préservées par un enregistrement de ventilation |
| Commande recompute-depreciation | 6 | Rapport puis `--fix`, dotations manuelles jamais écrasées, cumul d'ouverture aberrant signalé sans correction |
| Import CSV générique | 17 | Détection du séparateur, montants FR/EN, intitulés sans accents, mappage corrigeable, doublons sur les quatre cibles, mobilier et travaux, lignes illisibles isolées |
| Écran d'import CSV | 5 | Aperçu qui n'écrit rien, mappage réparé à la main, isolation entre utilisateurs y compris hors formulaire |
| Export / import du dossier | 13 | Aller-retour identique, `schema_version` refusée si trop récente, contrôle d'appartenance, archive antérieure relisible, transaction annulée en cas d'échec |
| Assistant de reprise (`/reprise`) | 30 | Les cinq étapes, la case Cerfa à côté de chaque montant, euros→centimes une seule fois, choix de méthode et réemploi de l'éditeur existant, contrôle branché sur `ReprisesCheckService`, rien écrit dans `fiscal_years` avant la fin, exercice clôturé protégé, isolation entre comptes, les deux portes d'entrée |
| Couverture de l'aide contextuelle | 6 | Toute fiche d'aide recensée (l'index de l'assistant part du registre, pas du dossier des vues), aucune entrée sans vue, libellés cités par l'aide vérifiés dans l'écran, 2033-D expliqué comme le code le calcule |
| Suites ajoutées depuis (détail non ventilé) | 193 | Coffre-fort, IA, orphelins d'exercices, avis in-app, import annuel, estimation DVF de la valeur vénale, justificatifs restés à la racine d'avant Laravel 11… |
| **Total** | **577** | **1 584 assertions** |

> Le détail par suite est tenu à jour lot par lot ; la ligne « suites ajoutées depuis »
> absorbe les suites fusionnées sans ventilation, pour que le tableau reste juste au total.

## Principes

- **Calculs financiers** : tous les services de calcul (amortissements, résultat fiscal,
  emprunts, TVA) sont testés au centime près — les montants sont manipulés en centimes
  (entiers) via bcmath, jamais en flottants.
- **Isolation multi-utilisateurs** : des tests dédiés vérifient qu'un utilisateur ne peut
  jamais voir ni modifier les données d'un autre (scopes globaux, pages Filament, API MCP).
- **Base en mémoire** : la suite tourne sur SQLite `:memory:`, aucune donnée persistante
  n'est nécessaire.
