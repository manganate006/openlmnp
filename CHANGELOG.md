# Changelog

Toutes les évolutions notables d'OpenLMNP. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/).

## [1.2.0] - 2026-09-03

### Ajouts

- **Les amortissements se règlent enfin au montant, et plus seulement au pourcentage.**
  L'écran *Amortissements* gagne un onglet **Montants** : base amortissable, durée et
  dotation annuelle en euros, composant par composant. Jusqu'ici seuls des curseurs en
  pourcentages entiers étaient proposés, ce qui rendait quasi impossible de basculer une
  comptabilité LMNP existante — les montants d'OpenLMNP ne pouvaient pas coïncider avec
  ceux déjà pratiqués par un comptable. Merci à @ovrtn de l'avoir signalé (#8)
- Une base saisie à la main est **verrouillée** : ni les curseurs, ni la commande de
  réparation ne la recalculent. Ventiler moins que la base amortissable devient permis, et
  signalé ; ventiler plus reste refusé

### Corrections

- **Le prorata de première année comptait un jour de trop, sur tous les amortissements.**
  Composants, travaux, mobilier et frais d'acquisition calculaient les jours restants par
  `diffInDays(...) + 1`, alors que cette fonction inclut déjà la journée en cours : toute
  première année était majorée de 1/365, soit **+0,27 %**. Un bien mis en location le
  1er janvier amortissait 100,27 % de sa dotation. ⚠️ **Les exercices déjà enregistrés
  gardent l'ancien calcul** : lancez « Recalculer la chaîne » depuis la page Exercices, ou
  `php artisan openlmnp:repair-orphan-fiscal-years --fix`
- **Supprimer un bien laissait ses montants dans les exercices déjà calculés.** Aucune clé
  étrangère ne pouvait s'en charger — un exercice agrège tous les biens d'une année — et
  rien ne le signalait. Nouvelle commande `openlmnp:repair-orphan-fiscal-years` (rapport
  par défaut, `--fix` pour agir, `--closed` en plus pour un exercice clôturé)
- **L'écran de saisie des composants demandait des centimes bruts** — `50000` pour 500 €,
  sans libellé ni symbole — alors que sa propre table affichait des euros. Il était masqué
  du menu mais toujours accessible, et la checklist du tableau de bord y envoyait. Il est
  supprimé, tout passe par l'écran *Amortissements*
- La fiche d'aide des composants n'était atteignable que depuis cet écran masqué : l'écran
  réellement utilisé n'avait donc aucune aide contextuelle
- Le montant de chaque composant s'affichait avec un symbole euro en double
- L'assistant de premier lancement annonçait des durées fausses (« Plomberie 25 ans »,
  « Revêtements ») qui ne correspondaient à aucun des composants réellement créés

### Interne

- `base_amount` devient la source de vérité d'un composant ; `percentage` est dérivé, et
  l'invariant « total = 100 % » se vérifie en centimes contre la base amortissable — c'est
  ce qui rend représentable une ventilation exacte du type 33,33 / 33,33 / 33,34
- La formule de ventilation, écrite à cinq endroits, vit désormais dans `DepreciationService`
- `saveComponents()` met à jour les composants au lieu de tout supprimer et recréer : les
  identifiants sont stables et l'appariement se fait par id, plus par nom
- Validation côté serveur de la ventilation, qui ne reposait que sur du JavaScript
- Trois méthodes mortes retirées du modèle, dont un calcul de prorata **mensuel** qui
  contredisait le calcul **journalier** réellement utilisé
- 32 tests supplémentaires, dont un jeu de valeurs d'or qui verrouille le calcul complet
  d'un bien (quote-part, prorata, travaux antérieurs, mobilier, frais, fin de plan)

## [1.1.7] - 2026-09-03

### Corrections

- **Thème sombre : un dernier reste de blanc sur la bordure du graphique d'amortissement.**
  La refonte des couleurs du 25 août avait remplacé les 173 `var(--fi-*)` des vues, mais
  l'éditeur d'amortissements lisait le sien depuis JavaScript
  (`getPropertyValue('--fi-body-bg')`), là où le garde-fou ne regardait pas : le test retire
  les blocs `<script>` avant de scanner. La bordure des segments du donut retombait donc sur
  son repli blanc en thème sombre. Elle suit désormais le jeu de jetons, comme le reste (#5)

### Interne

- `PanelStylesheetTest` couvre à présent les propriétés CSS lues depuis JavaScript, l'angle
  mort qui avait laissé passer le cas ci-dessus — vérifié en réintroduisant la régression
- `server.json`, le manifeste publié au registre MCP officiel, était resté à la version 1.1.4
  alors que deux versions étaient sorties depuis : il est resynchronisé

## [1.1.6] - 2026-09-02

### Corrections

- **L'assurance d'emprunt à taux variable était comptée cent fois trop cher.** Le taux
  d'assurance est un pourcentage annuel, au même titre que le taux d'intérêt, mais la
  division par 100 manquait au calcul du tableau d'amortissement. Sur un prêt de 242 000 €
  assuré à 2,21 %, l'assurance ressortait à près de 44 000 € par mois au lieu de 447 €, et
  gonflait d'autant les charges déductibles - jusqu'à afficher plus de 500 000 € de charges
  sur un exercice. Les intérêts, eux, étaient corrects. Seuls les emprunts en assurance
  « variable » sont concernés ; l'assurance à montant fixe ne passait pas par ce calcul

### Interne

- Nouvelle commande `php artisan openlmnp:repair-loan-insurance`. Le tableau d'amortissement
  et les totaux d'un exercice sont **stockés** : corriger le calcul ne suffit pas à réparer
  les données déjà enregistrées. La commande régénère les tableaux concernés puis recalcule
  les exercices, en laissant de côté les exercices clôturés - une déclaration déposée ne se
  réécrit pas en silence, elle est signalée pour décision. Rapport seul par défaut, `--fix`
  pour appliquer

## [1.1.5] - 2026-09-02

### Corrections

- **Les montants saisis dans l'assistant de configuration étaient enregistrés cent fois trop
  grands.** Un bien déclaré à 250 000 € partait en base à 25 000 000 €, et les composants
  d'amortissement étaient calculés sur ce montant. La conversion des euros en centimes était
  appliquée deux fois : une première par le formulaire lui-même, une seconde à
  l'enregistrement. Prix d'achat, frais de notaire, honoraires d'agence et valeur vénale
  étaient concernés ; les montants d'emprunt, qui suivent un autre chemin, ne l'étaient pas
- **Aucun composant d'amortissement n'était créé par l'assistant.** L'option « Générer les
  6 composants par défaut » s'affichait désactivée alors que l'étape annonce le contraire :
  sa valeur par défaut n'était pas appliquée au remplissage du formulaire. Sans composants,
  le bien n'était pas amorti du tout

### Interne

- Nouvelle commande `php artisan openlmnp:repair-components`. Elle resynchronise les
  composants d'amortissement restés calculés sur un ancien montant - typiquement après la
  correction manuelle d'un prix, qui laissait derrière elle des composants périmés sans que
  rien ne le signale sur la fiche du bien. Elle se contente d'un rapport par défaut, `--fix`
  applique les corrections. La base d'un composant étant modifiable à la main, seuls les
  écarts d'un facteur dix ou plus sont corrigés d'office ; les écarts plus faibles sont
  signalés et ne sont repris qu'avec `--all`

## [1.1.4] - 2026-09-02

### Améliorations

- **Le serveur HTTP ne servait qu'une requête à la fois.** Le serveur intégré de PHP est
  mono-processus par défaut : il traitait une requête, puis la suivante, et mettait le reste
  en file. Mesuré sur dix requêtes parallèles, le temps par requête allait de 0,049 à 0,324 s
  en escalier régulier - la signature d'une sérialisation. L'instance sert désormais quatre
  requêtes en parallèle (0,071 à 0,170 s sur la même mesure). La valeur se surcharge par
  `-e PHP_CLI_SERVER_WORKERS=<n>` pour une machine plus grosse
- **Journalisation réglable sans reconstruire l'image** : `LOG_CHANNEL`, `LOG_LEVEL` et
  `LOG_DAILY_DAYS` manquaient à la liste des variables que l'entrypoint propage vers `.env`,
  si bien qu'un `docker run -e LOG_LEVEL=debug` restait sans effet. Il est de nouveau possible
  de passer temporairement une instance en mode verbeux pour diagnostiquer un incident, puis
  de revenir en arrière

### Interne

- Nouvel endpoint `GET /api/admin/lifecycle-signals`, protégé par `PROVISION_TOKEN` et donc
  **absent des instances auto-hébergées** (404 sans jeton). Il ne renvoie que des agrégats
  d'usage - étape d'installation, nombre de biens, exercices clôturés, présence de la liasse
  et du FEC - jamais un montant ni une donnée fiscale, ce qu'un test verrouille

## [1.1.3] - 2026-08-25

### Améliorations

- **Image Docker allégée** : build multi-étapes sur base Alpine, images de base épinglées
  par digest - l'image passe d'environ 1 Go à environ 230 Mo (#4, merci @cocool97)
- **Mise à jour des instances Docker** : l'image officielle étant immuable (ni `rsync`, ni
  Composer, ni npm dans le runtime), la mise à jour en place est désormais explicitement
  neutralisée et la page *Mises à jour* indique la marche à suivre - `docker pull` puis
  recréation du conteneur, les données des volumes étant conservées. La détection de
  nouvelle version reste active

### Corrections

- **Thème sombre** : de nombreux textes s'affichaient en blanc sur fond blanc, donc
  illisibles (simulateur, télédéclaration, état du système, projection, aides...). Les vues
  du panel s'appuyaient sur des variables CSS `--fi-*` que Filament 5 n'expose pas : elles
  retombaient toujours sur leur repli clair, laissant les cartes blanches en thème sombre.
  Les couleurs de l'interface proviennent maintenant d'un jeu de jetons unique, décliné en
  clair et en sombre (#5, merci @cocool97)
- **Icônes des badges** : affichées en gris au lieu de leur couleur, pour la même raison
- **Format des dates** : les champs date se saisissaient au format ISO (AAAA-MM-JJ) alors
  que les tableaux affichaient JJ/MM/AAAA. Les sélecteurs de date utilisent désormais le
  calendrier français (JJ/MM/AAAA, semaine commençant le lundi) sur tous les formulaires
  (#6, merci @cocool97)
- **État du système** : le bouton « Lancer les tests » échouait sur les installations Docker
  avec `vendor/bin/pest: not found`. Pest est une dépendance de développement, absente des
  images de production : le bouton est maintenant désactivé, avec une explication, au lieu
  de présenter l'erreur comme un échec des tests (#7, merci @cocool97)

## [1.1.2] - 2026-08-18

### Corrections

- **Sécurité** : le compte de démonstration fixe (`demo@openlmnp.fr`, mot de passe
  publié dans le README pour la démo officielle) n'est plus créé que si
  `DEMO_MODE=true`. Il était auparavant seedé sur toute installation
  self-hébergée, quel que soit ce réglage (#2)
- **Docker** : le conteneur ne démarrait plus lorsque `database/`/`storage/`
  étaient montés via des dossiers hôtes (bind-mounts) plutôt que des volumes
  nommés - correctif du `Dockerfile` et resynchronisation de `storage/` par
  l'entrypoint, compatible `podman-compose` (#3, merci @cocool97)

## [1.1.1] - 2026-08-06

### Nouveautés

- **Image Docker officielle** [`manganate06/openlmnp`](https://hub.docker.com/r/manganate06/openlmnp)
  publiée automatiquement à chaque release (amd64 + arm64) - installation en une commande,
  sans build local

### Corrections

- **Sécurité Docker** : `APP_KEY` n'est plus figée dans l'image au build (une image publiée
  aurait partagé la même clé entre toutes les installations). Elle est générée au premier
  démarrage, unique par instance, persistée dans `storage/app/.app_key` et surchargeable par
  `-e APP_KEY=...`
- `version.json` resynchronisé avec la version publiée (la notification de mise à jour
  se déclenchait à tort)

## [1.1.0] - 2026-07-20

### Nouveautés

- **Mode démo** multi-utilisateurs isolé : bac à sable éphémère par visiteur avec jeu de données réaliste, purge automatique
- **Inscription self-hosted** limitée au premier compte par défaut (`ALLOW_REGISTRATION=auto`)
- **API de provisioning** et suspension de comptes (offre cloud)
- **Page publique de politique de confidentialité**
- **Google Tag Manager** en intégration optionnelle et désactivable (mesure d'audience)

### Corrections

- Résultat fiscal : consultation des exercices clôturés sans recalcul intempestif
- Docker : resynchronisation des migrations masquées par le volume `database/`, propagation correcte des variables d'environnement runtime vers `.env`
- Script d'installation community-scripts : génère `APP_KEY` sans dépendre d'`artisan` (avant `composer install`, sur une release fraîchement extraite)

### Technique

- **189 tests Pest** (513 assertions)

## [1.0.0] - 2026-07-02

Première version stable - comptabilité LMNP (Location Meublée Non Professionnelle) au régime réel
pour les loueurs Airbnb.

### Fonctionnalités

- **Biens & activité** : biens, revenus, charges, mobilier, travaux
- **Amortissements** : décomposition par composants, base amortissable, quote-part usage mixte, prorata temporis
- **Emprunts** : tableau d'amortissement, capital restant dû, intérêts déductibles
- **Résultat fiscal** : calcul réel, plafonnement des amortissements, quote-part des charges
- **Comparateur micro-BIC / réel**
- **Imports** : CSV Airbnb (EN/FR) et relevés bancaires (doublons, format européen)
- **Exports** : FEC (18 colonnes) et liasse fiscale PDF (2031/2033)
- **Simulateur & projection** pluriannuelle
- **Serveur MCP** intégré (assistants IA : Claude Desktop, Claude Code...)

### Déploiement

- Image **Docker** + `docker-compose`
- Script **LXC Proxmox** (style community-scripts) avec mot de passe admin aléatoire à l'installation

### Technique

- Laravel 13 · Filament 5 · PHP 8.3+ · SQLite · DomPDF · Maatwebsite/Excel
- **102 tests Pest** (266 assertions)
- Licence **AGPLv3**
