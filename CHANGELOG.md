# Changelog

Toutes les évolutions notables d'OpenLMNP. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/).

## [1.1.1] — 2026-08-06

### Nouveautés

- **Image Docker officielle** [`manganate06/openlmnp`](https://hub.docker.com/r/manganate06/openlmnp)
  publiée automatiquement à chaque release (amd64 + arm64) — installation en une commande,
  sans build local

### Corrections

- **Sécurité Docker** : `APP_KEY` n'est plus figée dans l'image au build (une image publiée
  aurait partagé la même clé entre toutes les installations). Elle est générée au premier
  démarrage, unique par instance, persistée dans `storage/app/.app_key` et surchargeable par
  `-e APP_KEY=…`
- `version.json` resynchronisé avec la version publiée (la notification de mise à jour
  se déclenchait à tort)

## [1.1.0] — 2026-07-20

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

## [1.0.0] — 2026-07-02

Première version stable — comptabilité LMNP (Location Meublée Non Professionnelle) au régime réel
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
- **Serveur MCP** intégré (assistants IA : Claude Desktop, Claude Code…)

### Déploiement

- Image **Docker** + `docker-compose`
- Script **LXC Proxmox** (style community-scripts) avec mot de passe admin aléatoire à l'installation

### Technique

- Laravel 13 · Filament 5 · PHP 8.3+ · SQLite · DomPDF · Maatwebsite/Excel
- **102 tests Pest** (266 assertions)
- Licence **AGPLv3**
