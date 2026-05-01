<div align="center">

# OpenLMNP

**Logiciel open source de comptabilité LMNP**

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Filament](https://img.shields.io/badge/Filament-5.6-FBBF24?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZD0iTTEyIDJMMiAyMmgyMEwxMiAyeiIgZmlsbD0id2hpdGUiLz48L3N2Zz4=)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![License](https://img.shields.io/badge/Licence-AGPLv3-green)
![Tests](https://img.shields.io/badge/Tests-55%20pass%C3%A9s-brightgreen)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)

Gérez vos biens en location meublée, calculez vos amortissements,
et produisez votre liasse fiscale au régime réel.

[English version](README.en.md)

</div>

---

## Fonctionnalités

- **Multi-utilisateurs** — Chaque propriétaire voit uniquement ses données
- **Biens immobiliers** — Adresse, surfaces, quote-part résidence principale, valeur vénale
- **Amortissement par composant** — Gros œuvre, toiture, plomberie, agencements (durées standards)
- **Travaux & Mobilier** — Amortissement dédié ou au prorata, gestion neuf/occasion avec justificatifs adaptés
- **Recettes** — Saisie manuelle ou import CSV Airbnb/Booking
- **Charges** — Catégorisées, prorata automatique, justificatifs uploadés
- **Emprunts** — Tableau d'amortissement auto, intérêts déductibles
- **Simulateur** — Comparaison micro-BIC vs régime réel avec verdict
- **Projection pluriannuelle** — Tableau sur 5 à 20 ans
- **Liasse fiscale PDF** — Lignes Cerfa 2031, 2033-A/B/C/D, case 2042-C-PRO
- **FEC conforme** — Article A.47 A-1 du LPF, 18 colonnes, format légal
- **Écritures comptables** — Génération automatique (plan comptable LMNP)
- **Assistants guidés (wizards)** — Onboarding, création de bien, clôture fiscale, emprunt, import annuel
- **Export CSV** — Recettes et charges exportables
- **Dark mode** — Natif Filament
- **Guide d'utilisation intégré** — Organisé en 3 temps : mise en route, suivi régulier, déclaration annuelle
- **55 tests automatisés** — Pest PHP, 141 assertions

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
Compte démo : `demo@openlmnp.fr` / `demo1234`

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
| FiscalYearService | 4 | Résultat fiscal, plafonnement, comparaison micro-BIC |
| LoanService | 3 | Tableau amortissement, capital, intérêts déductibles |
| AirbnbImportService | 5 | CSV FR/EN, doublons, montants négatifs, format européen |
| FecService | 2 | 18 colonnes, format légal |
| TaxReturnService | 1 | Génération PDF liasse fiscale |
| AccountingEntryService | 3 | Écritures équilibrées, comptes PCG, quote-part |
| Pages Filament | 22 | Auth, CRUD, simulateur, projection, isolation données |
| Wizards | 7 | Onboarding, clôture fiscale, emprunt, import annuel |
| **Total** | **55** | **141 assertions** |

## Documentation

- [Cahier des charges](docs/cahier-des-charges-lmnp-app.md)
- [Fiscalité LMNP Airbnb](docs/fiscalite-lmnp-airbnb.md)
- [Simulation Vinted](docs/synthese-projet-vinted.md)
- [Guide UI](docs/ui-design-openlmnp.md)

## Roadmap

### v0.1 (actuel)

- [x] Multi-utilisateurs + isolation données
- [x] CRUD biens, recettes, charges, emprunts, exercices
- [x] Amortissement par composant + prorata temporis
- [x] Simulateur micro-BIC vs réel
- [x] Projection pluriannuelle
- [x] Liasse fiscale PDF (2031, 2033-A/B/C/D)
- [x] FEC conforme
- [x] Import CSV Airbnb
- [x] Export CSV
- [x] Assistants guidés (wizards) pour toutes les saisies
- [x] Guide d'aide intégré (mise en route / suivi régulier / déclaration annuelle)
- [x] Justificatifs occasion (ZIP, PDF, photos) avec tooltips contextuels
- [x] 55 tests automatisés

### v1.0 (à venir)

- [ ] Télétransmission EDI-TDFC
- [ ] Import relevés bancaires
- [ ] Audit trail (historique modifications)
- [ ] Backup automatique SQLite
- [ ] PWA (Progressive Web App)
- [ ] Repo public + CI/CD GitHub Actions

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
- Développé avec l'aide de [Claude Code](https://claude.ai/code) (Anthropic)

---

<div align="center">
<sub>OpenLMNP est un outil d'aide à la comptabilité. Il ne remplace pas un expert-comptable pour les cas complexes.</sub>
</div>
