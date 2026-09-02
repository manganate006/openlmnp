# Changelog

Toutes les évolutions notables d'OpenLMNP. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/).

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
