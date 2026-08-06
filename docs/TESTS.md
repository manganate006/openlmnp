# Couverture de tests

OpenLMNP est couvert par **233 tests automatisés (626 assertions)** écrits avec
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
| AirbnbImportService | 7 | CSV FR/EN, doublons, montants négatifs, format européen, plafond de taille |
| FecService | 2 | 18 colonnes, format légal |
| TaxReturnService | 1 | Génération PDF liasse fiscale |
| AccountingEntryService | 3 | Écritures équilibrées, comptes PCG, quote-part |
| BadgeService | 15 | Attribution, dédoublonnage, heatmap, score |
| TVA (helper + déclaration) | 11 | TVA collectée/déductible, trimestriel, calculs HT/TTC |
| Serveur MCP | 17 | Auth, isolation données, CRUD, justificatifs (garde anti-SSRF), audit |
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
| **Total** | **233** | **626 assertions** |

## Principes

- **Calculs financiers** : tous les services de calcul (amortissements, résultat fiscal,
  emprunts, TVA) sont testés au centime près — les montants sont manipulés en centimes
  (entiers) via bcmath, jamais en flottants.
- **Isolation multi-utilisateurs** : des tests dédiés vérifient qu'un utilisateur ne peut
  jamais voir ni modifier les données d'un autre (scopes globaux, pages Filament, API MCP).
- **Base en mémoire** : la suite tourne sur SQLite `:memory:`, aucune donnée persistante
  n'est nécessaire.
