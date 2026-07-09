<div align="center">

# OpenLMNP

**Logiciel open source de comptabilité LMNP**

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Filament](https://img.shields.io/badge/Filament-5.6-FBBF24?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZD0iTTEyIDJMMiAyMmgyMEwxMiAyeiIgZmlsbD0id2hpdGUiLz48L3N2Zz4=)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![License](https://img.shields.io/badge/Licence-AGPLv3-green)
![Tests](https://img.shields.io/badge/Tests-167%20pass%C3%A9s-brightgreen)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)

Gérez vos biens en location meublée, calculez vos amortissements,
et produisez votre liasse fiscale au régime réel.

[English version](README.en.md)

</div>

---

## Captures d'écran

<details>
<summary>Tableau de bord</summary>

![Dashboard](docs/screenshots/dashboard.png)

</details>

<details>
<summary>Charges</summary>

![Charges](docs/screenshots/charges.png)

</details>

<details>
<summary>Télédéclaration</summary>

![Télédéclaration](docs/screenshots/teledeclaration.png)

</details>

## Fonctionnalités

- **Multi-utilisateurs** — Chaque propriétaire voit uniquement ses données
- **Biens immobiliers** — Adresse, surfaces, quote-part résidence principale, valeur vénale
- **Amortissement par composant** — Gros œuvre, toiture, plomberie, agencements (durées standards)
- **Travaux & Mobilier** — Amortissement dédié ou au prorata, gestion neuf/occasion avec justificatifs
- **Recettes** — Saisie manuelle ou import CSV Airbnb/Booking
- **Charges** — Catégorisées, prorata automatique, justificatifs uploadés
- **Emprunts** — Tableau d'amortissement auto, intérêts déductibles
- **Simulateur** — Comparaison micro-BIC vs régime réel avec verdict
- **Projection pluriannuelle** — Tableau sur 5 à 20 ans
- **Télédéclaration interactive** — Lignes Cerfa 2031, 2033-A/B/C/D, 2042-C-PRO avec boutons « Copier »
- **Liasse fiscale PDF** — Génération complète
- **FEC conforme** — Article A.47 A-1 du LPF, 18 colonnes, format légal
- **Écritures comptables** — Génération automatique (plan comptable LMNP)
- **API MCP** — Intégration avec assistants IA (Claude, etc.)
- **Mises à jour automatiques** — Notification et déploiement depuis GitHub
- **Assistants guidés (wizards)** — Onboarding, création de bien, clôture fiscale, emprunt, import annuel
- **Export CSV** — Recettes, charges, télédéclaration
- **Dark mode** — Natif Filament
- **Guide d'utilisation intégré** — Organisé en 3 temps : mise en route, suivi régulier, déclaration annuelle
- **167 tests automatisés** — Pest PHP, 472 assertions

## Documentation

Une documentation complète est disponible dans le dossier [`docs/`](docs/) :

| Document | Contenu |
|----------|---------|
| [Installation](docs/INSTALLATION.md) | Auto-hébergement via Docker : build, run, volumes de persistance, variables d'environnement, mise à jour et sauvegarde |
| [Fonctionnalités](docs/FONCTIONNALITES.md) | Amortissement par composant, FEC, liasse fiscale 2031/2033, import CSV, simulateur, multi-biens, emprunts, justificatifs |
| [Mode démonstration](docs/DEMO.md) | Activer et utiliser le mode démo multi-utilisateurs (sandbox éphémère isolé par visiteur) |
| [FAQ](docs/FAQ.md) | Questions courantes : gratuité, confidentialité des données, régime réel vs micro-BIC, sauvegardes… |
| [Guide fiscal LMNP / Airbnb](docs/fiscalite-lmnp-airbnb.md) | Règles fiscales du régime réel : amortissements, abattements, plafonds, réforme 2026 |
| [Guide de conception UI](docs/ui-design-openlmnp.md) | Choix de design de l'interface Filament |

Pour contribuer au projet, voir [CONTRIBUTING.md](CONTRIBUTING.md).

## Stack technique

| Composant | Technologie |
|-----------|-------------|
| Framework | Laravel 13 |
| Admin UI | Filament 5 |
| Interactivité | Livewire 4 |
| Base de données | SQLite (PostgreSQL en option) |
| PDF | DomPDF |
| Calculs financiers | PHP bcmath (précision décimale) |
| Tests | Pest PHP |
| Déploiement | Docker |

## Installation rapide (Docker)

```bash
git clone https://github.com/manganate006/openlmnp.git
cd openlmnp
docker build -t openlmnp .
docker run -d --name openlmnp -p 8090:8000 --restart unless-stopped openlmnp
```

Accès : `http://localhost:8090`
Compte démo : `demo@openlmnp.fr` / `demo2026`

## Installation LXC Proxmox (script communautaire)

Sur un hôte Proxmox VE, crée un conteneur LXC prêt à l'emploi en une seule commande :

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/manganate006/openlmnp/main/community-scripts/ct/openlmnp.sh)"
```

Debian 13 · nginx + PHP 8.4-FPM · SQLite. Un mot de passe admin **aléatoire** est généré à
l'installation et enregistré dans `/opt/openlmnp/admin_credentials.txt`.

> ℹ️ Nécessite un dépôt public avec une *release* publiée (le script récupère la dernière release GitHub).

## Installation développement

```bash
git clone https://github.com/manganate006/openlmnp.git
cd openlmnp
composer install
cp .env.docker .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
php artisan serve
```

## Configuration

| Variable | Description | Défaut |
|----------|-------------|--------|
| `DB_CONNECTION` | Base de données | `sqlite` |
| `DB_DATABASE` | Chemin SQLite | `database/database.sqlite` |
| `APP_LOCALE` | Langue | `fr` |

Ajoutez votre SIREN dans votre profil utilisateur pour les documents fiscaux.

## Tests

```bash
# Lancer tous les tests
vendor/bin/pest

# Par catégorie
vendor/bin/pest --filter="Depreciation"
vendor/bin/pest --filter="FiscalYear"
vendor/bin/pest --filter="Loan"
vendor/bin/pest --filter="Airbnb"
vendor/bin/pest --filter="Fec"
vendor/bin/pest --filter="Filament"
```

### Couverture

| Suite | Tests | Couverture |
|-------|-------|------------|
| DepreciationService | 5 | Composants, base amortissable, quote-part, prorata |
| FiscalYearService | 6 | Résultat fiscal, plafonnement, quote-part charges, micro-BIC |
| FiscalYearChain | 12 | Chaîne d'exercices : première année, année proposée, validation N-1 |
| LoanService | 3 | Tableau amortissement, capital restant, intérêts déductibles |
| AirbnbImportService | 5 | CSV FR/EN, doublons, montants négatifs, format européen |
| FecService | 2 | 18 colonnes, format légal |
| TaxReturnService | 1 | Génération PDF liasse fiscale |
| AccountingEntryService | 3 | Écritures équilibrées, comptes PCG, quote-part |
| BadgeService | 15 | Attribution, dédoublonnage, heatmap, score |
| TVA (helper + déclaration) | 11 | TVA collectée/déductible, trimestriel, calculs HT/TTC |
| McpServer | 15 | Auth, isolation données, CRUD, justificatifs, audit |
| Pages Filament | 26 | Auth, CRUD, simulateur, projection, isolation données |
| Navigation (liens + badges) | 15 | Orphelins, liens, modes Simple/Avancé/Guidé, badges |
| Wizards | 8 | Onboarding, clôture fiscale, emprunt, import annuel |
| Mode démo | 7 | Sandbox éphémère isolé par visiteur, purge automatique |
| Isolation multi-utilisateurs | 10 | Scopes via le bien, modèles enfants, page loan-detail |
| Mesure d'audience opt-in (GTM) | 21 | Désactivée par défaut, injection conditionnelle |
| Smoke (framework) | 2 | Amorçage de l'application |
| **Total** | **167** | **472 assertions** |

## Contribution

Les contributions sont les bienvenues ! Merci d'ouvrir une issue avant de soumettre une PR.

```bash
# Fork + clone
git checkout -b feature/ma-fonctionnalite
# ... modifier ...
vendor/bin/pest  # vérifier que les tests passent
git commit -m "feat: description"
git push origin feature/ma-fonctionnalite
# Ouvrir une PR
```

## Licence

[AGPLv3](LICENSE) — Logiciel libre. Vous pouvez l'utiliser, le modifier et le redistribuer
à condition de partager les modifications sous la même licence.

## Crédits

- [Laravel](https://laravel.com) — Framework PHP
- [Filament](https://filamentphp.com) — Admin panel
- [Pest PHP](https://pestphp.com) — Framework de tests

---

<div align="center">
<sub>OpenLMNP est un outil d'aide à la comptabilité. Il ne remplace pas un expert-comptable pour les cas complexes.</sub>
</div>
