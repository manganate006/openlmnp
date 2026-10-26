# Couverture de tests

OpenLMNP est couvert par **1 516 tests automatisés (6 212 assertions)** écrits avec
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
vendor/bin/pest --filter="Vault"
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
| Serveur MCP | 22 | Auth, isolation données, CRUD, justificatifs (garde anti-SSRF : IP privées + redirections), analyse de justificatifs, nombre d'outils annoncé, audit |
| API MCP démo (lecture seule) | 11 | Jeton démo public, écritures bloquées, quota de requêtes |
| Pages Filament | 53 | Auth, CRUD, simulateur, projection, isolation données, aide et profil |
| Socle IA (accès, quotas, client, flux SSE, journal, profil, raisonnement) | 123 | Résolution de clé, plafonds, erreurs typées, aucune fuite de clé, propagation Docker |
| Normalisation des justificatifs | 50 | Montants en centimes, taux de TVA, dates, catégories, durées, reclassement, relevés |
| Analyse de justificatifs (service) | 39 | Extraction par type, réparation, cascade, repli texte PDF, doublons, création, journal, liasse 2033 (cases jamais inventées, repli 870→318, contrôle ligne 572, refus d'enregistrement hors assistant de reprise) |
| Page « Importer un justificatif » | 29 | Consentement, analyse, correction, confirmation, doublon, quota, démo, isolation, aperçu, réanalyse, aide |
| Service des justificatifs | 14 | Type imposé d'après l'extension (un HTML déguisé en PDF n'est jamais servi en text/html), téléchargement forcé hors images et PDF |
| Administration IA | 12 | Agrégats tous comptes, coûts bcmath, refus, purge, aucune clé affichée, aide contextuelle |
| Navigation & liens | 19 | Orphelins, liens, modes Simple/Avancé/Guidé, badges, page de connexion |
| Wizards | 8 | Onboarding, clôture fiscale, emprunt, import annuel |
| Mode démo | 7 | Sandbox éphémère isolé par visiteur, purge automatique |
| Isolation multi-utilisateurs | 10 | Scopes via le bien, modèles enfants, page loan-detail |
| Mesure d'audience opt-in (GTM) | 21 | Désactivée par défaut, injection conditionnelle |
| API de provisioning | 11 | Jeton requis (404/401), création idempotente, suspension idempotente (réponse uniforme) |
| Inscription (RegistrationGate) | 8 | Mode auto (fermée après le premier compte), démo exclue, true/false |
| Commande reset-password | 4 | Lien de réinitialisation, --password, validations |
| Télémétrie instances (opt-out) | 4 | Check-in anonyme, désactivable, silencieux sur erreur |
| Page politique de confidentialité | 4 | Rendu et accessibilité de la page légale |
| En-têtes de sécurité (F8) | 1 | X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy |
| Coffre-fort — chiffrement | 24 | Aller-retour octet pour octet, altération et troncature détectées, mauvaise phrase de passe refusée, changement de phrase sans réécriture des fichiers, flux sans charge mémoire |
| Coffre-fort — service et migration | 40 | Service en flux (423 verrouillé, 403 pour autrui, Content-Type par extension), dépôt chiffré, migration idempotente et reprenable, chemins introuvables tolérés, refus côté API MCP |
| Smoke (framework) | 2 | Amorçage de l'application |
| Assistant conversationnel | 71 | Index documentaire, garde-fous de périmètre, pont vers les outils MCP, composant Livewire, isolation |
| Conversion HEIC côté navigateur | 3 | Assets Filament, bibliothèque vendorisée, remplacement par un JPEG avant téléversement |
| Localisation française | 11 | Locale fr par défaut, messages d'authentification, de pagination, de validation et du courtier de mots de passe |
| Mise à jour en place | 10 | UPDATE_SELF_APPLY : neutralisée sur une instance immuable (image Docker), consigne docker pull |
| Estimation de la valeur vénale (DVF) | 38 | Millésimes, échantillon, estimation, action d'écran, outil MCP |
| Recueil d'avis | 36 | Éligibilité, audiences, variantes, cooldown, survie à la purge des comptes démo |
| Éditeur d'amortissements | 33 | Bases en euros, synchronisation des composants, prorata de première année, non-régression |
| Fournisseurs d'IA | 41 | Résolution et capacités, pilotes natifs Anthropic et Gemini, catalogue de modèles |
| Connexion OpenRouter (OAuth PKCE) | 30 | Défi et vérificateur, rejeu, expiration, autre compte, aucune fuite de clé |
| Vérification d'un fournisseur | 11 | Refus sans clé, contrôles gratuits, sondes facturées, code d'échec |
| Justificatifs d'avant Laravel 11 | 9 | Repli sur l'ancienne racine de stockage |
| Liasse 2033-C | 8 | Bouclage du tableau |
| Exercices fiscaux orphelins | 8 | Détection et rattachement |
| Requêtes simultanées et SQLite | 4 | Workers, WAL, mode de transaction |
| Import annuel CSV | 2 | Recettes Airbnb effectivement importées |
| Soldes d'ouverture (reprise) | 15 | Report repris d'une liasse N-1, N-1 réel prioritaire, N-1 vide signalé, cumul de contrôle inerte, exposition MCP |
| Déficits reportables et 2033-D | 22 | Imputation FIFO par millésime, péremption à 10 ans, ordre amortissements différés → déficits, cases 982/983/984, commande de réparation, encart d'information |
| Contrôle de reprise | 16 | Seuils 1 € / 1 %, un cas par diagnostic, lignes recopiées vs reconstituées, exercice N-1 vide |
| Fidélité du plan d'amortissement | 15 | Date de départ par composant, catégorie Cerfa explicite (et rétro-classement qui ne déplace rien), traitement et durée des frais d'acquisition, dotations recopiées d'une liasse, cumuls d'ouverture qui n'entrent jamais dans la dotation |
| Éditeur d'amortissements — reprise | 5 | Composant à nom libre, refus d'un composant sans nom, colonnes de reprise préservées par un enregistrement de ventilation |
| Commande recompute-depreciation | 6 | Rapport puis `--fix`, dotations manuelles jamais écrasées, cumul d'ouverture aberrant signalé sans correction |
| Import CSV générique | 17 | Détection du séparateur, montants FR/EN, intitulés sans accents, mappage corrigeable, doublons sur les quatre cibles, mobilier et travaux, lignes illisibles isolées |
| Écran d'import CSV | 5 | Aperçu qui n'écrit rien, mappage réparé à la main, isolation entre utilisateurs y compris hors formulaire |
| Export / import du dossier | 13 | Aller-retour identique, `schema_version` refusée si trop récente, contrôle d'appartenance, archive antérieure relisible, transaction annulée en cas d'échec |
| Assistant de reprise (`/reprise`) | 38 | Les cinq étapes, la case Cerfa à côté de chaque montant, euros→centimes une seule fois, choix de méthode et réemploi de l'éditeur existant, contrôle branché sur `ReprisesCheckService`, rien écrit dans `fiscal_years` avant la fin, exercice clôturé protégé, isolation entre comptes, les deux portes d'entrée, lecture IA de la liasse (consentement exigé, relecture avant toute écriture, frais d'acquisition écartés, provenance « ia » gravée) |
| Couverture de l'aide contextuelle | 6 | Toute fiche d'aide recensée (l'index de l'assistant part du registre, pas du dossier des vues), aucune entrée sans vue, libellés cités par l'aide vérifiés dans l'écran, 2033-D expliqué comme le code le calcule |
| Charges récurrentes | 25 | Calendrier des échéances (non-débordement des mois courts, heures ignorées), idempotence, bornes d'année civile, refus sur exercice clôturé, action Filament et recalcul des exercices |
| Duplication d'une charge | 13 | Date avancée d'une période, conversion euros/centimes, justificatifs non recopiés, invitation à générer posée après enregistrement |
| Contrôles de cohérence de la liasse | 10 | 044 = 490 et 572 = 254, trois états, tolérance de troncature, concordance entre le PDF et l'écran de télédéclaration |
| Colonnes déclarées vs schéma | 3 | Audit générique des `$fillable` de tous les modèles, `ack_number` filtrable, paire transmitted_at/ack_number |
| Expiration de démo : rendu réel du message et routage | 8 | Les deux notifications sont **rendues pour de vrai** (`Notification::fake()` n'appelle jamais `toMail()`, d'où une fatale restée invisible), compte à rebours basculé en jours au-delà de 24 h côté Alpine comme côté serveur, adresse laissée par le visiteur honorée, adresse **de paiement** honorée après promotion du bac à sable, cron qui signale un échec au lieu de rendre SUCCESS |
| Dépôt d'une liasse | 11 | Date et numéro d'accusé saisis ET effacés ensemble (un accusé orphelin se lirait comme un dépôt), bornes de la date posées sur l'**état horodaté** que renvoie réellement le sélecteur, badge « Déposée » absent tant que rien n'est saisi, bandeau de l'écran de télédéclaration, valeur servie par `get_fiscal_year` |
| Coffre-fort × analyse IA | 6 | Le justificatif rangé par l'analyse est **scellé** (coffre actif, et même coffre **verrouillé**) ; en clair sans coffre ; un fichier déjà scellé se refuse **en nommant le coffre** et non « format non pris en charge » ; le clair de transit ne survit pas à l'analyse ; le migrateur n'encapsule pas un fichier en cours de relecture |
| Inscription sur invitation | 34 | Jeton **jamais stocké en clair** ; usage unique (UPDATE conditionnel) ; adresse prise sur la LIGNE et non sur le formulaire ; un jeton révoqué rend la MÊME réponse qu'un jeton inconnu, octet pour octet ; expiration figée à l'émission ; relance qui tue le lien précédent ; « invite » n'est pas « false » ; le lien imprimé même sans SMTP, et bâti sur APP_URL hors requête HTTP |
| Citation du .env par l'entrypoint | 2 | La vraie boucle du vrai `docker-entrypoint.sh`, jugée par le VRAI phpdotenv : une valeur avec espace (`APP_NAME`, `MAIL_FROM_NAME`) doit faire l'aller-retour, et la mise à jour d'une ligne existante ne doit ni dupliquer ni casser |
| `is_dedicated` dans l'analyse IA | 8 | La règle `! is_primary_residence` (elle n'en avait AUCUN) et sa confiance à 0,6 ; les deux branches de `createFurniture()` qui divergeaient sur le repli ; les corrections manuelles qui survivent à un changement de type, **et** un champ intact qui suit bien la nouvelle proposition |
| Relevé annuel de plateforme | 29 | Une recette par mois loué, au **brut**, commission à part ; sommes qui retombent au centime sur le récapitulatif ; dernier jour du mois, **février bissextile** compris ; commission jamais négative ; mois remboursé signalé au lieu d'être escamoté ; taxe de séjour signalée mais non répartie ; **garde contre le double comptage d'une année** ; l'année corrigée à l'écran fait foi ; invariant `required` ↔ `properties` du schéma ; **et la confirmation d'un relevé bancaire**, chemin que rien ne couvrait |
| Version & changelog | 18 | Page « Nouveautés » ouverte à tous, relevé mis en cache et sans appel réseau au montage, notes de version rendues et assainies, plus aucune version en dur |
| Suites ajoutées depuis (détail non ventilé) | 313 | Coffre-fort à paire de clés, **module IA** (socle, huit fournisseurs, analyse de justificatifs, assistant, lecture de liasse), orphelins d'exercices, avis in-app, import annuel, estimation DVF de la valeur vénale, justificatifs restés à la racine d'avant Laravel 11, frais d'acquisition, valeur brute des actifs, rapport de diagnostic, formulaires annoncés, recouvrement du cumul repris, quote-part appliquée une seule fois, consentement aux traceurs… |
| **Total** | **1 516** | **6 212 assertions** |

| **Total mesuré par la suite** | **1 188** | **4 917 assertions** |

> Les lignes ci-dessus regroupent par thème, pas par fichier : leur somme approche le total sans
> l'égaler. Le chiffre qui fait foi est celui de la dernière ligne, rendu par la suite elle-même.

## Principes

- **Calculs financiers** : tous les services de calcul (amortissements, résultat fiscal,
  emprunts, TVA) sont testés au centime près — les montants sont manipulés en centimes
  (entiers) via bcmath, jamais en flottants.
- **Isolation multi-utilisateurs** : des tests dédiés vérifient qu'un utilisateur ne peut
  jamais voir ni modifier les données d'un autre (scopes globaux, pages Filament, API MCP).
- **Base en mémoire** : la suite tourne sur SQLite `:memory:`, aucune donnée persistante
  n'est nécessaire.
