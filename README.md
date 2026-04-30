<div align="center">

# OpenLMNP

**Logiciel open source de comptabilite LMNP**

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Filament](https://img.shields.io/badge/Filament-5.6-FBBF24?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZD0iTTEyIDJMMiAyMmgyMEwxMiAyeiIgZmlsbD0id2hpdGUiLz48L3N2Zz4=)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![License](https://img.shields.io/badge/Licence-AGPLv3-green)
![Tests](https://img.shields.io/badge/Tests-48%20pass%C3%A9s-brightgreen)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)

Gerez vos biens en location meublee, calculez vos amortissements,
et produisez votre liasse fiscale au regime reel.

[English version](README.en.md)

</div>

---

## Fonctionnalites

- **Multi-utilisateurs** — Chaque proprietaire voit uniquement ses donnees
- **Biens immobiliers** — Adresse, surfaces, quote-part residence principale, valeur venale
- **Amortissement par composant** — Gros oeuvre, toiture, plomberie, agencements (durees standards)
- **Travaux & Mobilier** — Amortissement dedie ou au prorata
- **Recettes** — Saisie manuelle ou import CSV Airbnb/Booking
- **Charges** — Categorisees, prorata automatique, justificatifs uploades
- **Emprunts** — Tableau d'amortissement auto, interets deductibles
- **Simulateur** — Comparaison micro-BIC vs regime reel avec verdict
- **Projection pluriannuelle** — Tableau sur 5 a 20 ans
- **Liasse fiscale PDF** — Lignes Cerfa 2031, 2033-A/B/C/D, case 2042-C-PRO
- **FEC conforme** — Article A.47 A-1 du LPF, 18 colonnes, format legal
- **Ecritures comptables** — Generation automatique (plan comptable LMNP)
- **Export CSV** — Recettes et charges exportables
- **Dark mode** — Natif Filament
- **Documentation in-app** — Guide d'utilisation complet
- **48 tests automatises** — Pest PHP, 126 assertions

## Stack technique

| Composant | Technologie |
|-----------|-------------|
| Framework | Laravel 13 |
| Admin UI | Filament 5 |
| Interactivite | Livewire 4 |
| Base de donnees | SQLite (PostgreSQL en option) |
| PDF | DomPDF |
| Calculs financiers | PHP bcmath (precision decimale) |
| Tests | Pest PHP |
| Deploiement | Docker |

## Installation rapide (Docker)

```bash
git clone https://github.com/manganate006/openlmnp.git
cd openlmnp
docker build -t openlmnp .
docker run -d --name openlmnp -p 8090:8000 --restart unless-stopped openlmnp
```

Acces : `http://localhost:8090/app`
Compte demo : `demo@openlmnp.fr` / `demo1234`

## Installation developpement

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

| Variable | Description | Defaut |
|----------|-------------|--------|
| `DB_CONNECTION` | Base de donnees | `sqlite` |
| `DB_DATABASE` | Chemin SQLite | `database/database.sqlite` |
| `APP_LOCALE` | Langue | `fr` |

Ajoutez votre SIREN dans votre profil utilisateur pour les documents fiscaux.

## Tests

```bash
# Lancer tous les tests
vendor/bin/pest

# Par categorie
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
| FiscalYearService | 4 | Resultat fiscal, plafonnement, comparaison micro-BIC |
| LoanService | 3 | Tableau amortissement, capital, interets deductibles |
| AirbnbImportService | 5 | CSV FR/EN, doublons, montants negatifs, format europeen |
| FecService | 2 | 18 colonnes, format legal |
| TaxReturnService | 1 | Generation PDF liasse fiscale |
| AccountingEntryService | 3 | Ecritures equilibrees, comptes PCG, quote-part |
| Pages Filament | 22 | Auth, CRUD, simulateur, projection, isolation donnees |
| **Total** | **48** | **126 assertions** |

## Documentation

- [Cahier des charges](docs/cahier-des-charges-lmnp-app.md)
- [Fiscalite LMNP Airbnb](docs/fiscalite-lmnp-airbnb.md)
- [Simulation Vinted](docs/synthese-projet-vinted.md)
- [Guide UI](docs/ui-design-openlmnp.md)

## Roadmap

### v0.1 (actuel)

- [x] Multi-utilisateurs + isolation donnees
- [x] CRUD biens, recettes, charges, emprunts, exercices
- [x] Amortissement par composant + prorata temporis
- [x] Simulateur micro-BIC vs reel
- [x] Projection pluriannuelle
- [x] Liasse fiscale PDF (2031, 2033-A/B/C/D)
- [x] FEC conforme
- [x] Import CSV Airbnb
- [x] Export CSV
- [x] 48 tests automatises

### v1.0 (a venir)

- [ ] Teletransmission EDI-TDFC
- [ ] Import releves bancaires
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
vendor/bin/pest  # verifier que les tests passent
git commit -m "feat: description"
git push origin feature/ma-fonctionnalite
# Ouvrir une PR
```

## Licence

[AGPLv3](LICENSE) — Logiciel libre. Vous pouvez l'utiliser, le modifier et le redistribuer
a condition de partager les modifications sous la meme licence.

## Credits

- [Laravel](https://laravel.com) — Framework PHP
- [Filament](https://filamentphp.com) — Admin panel
- [Pest PHP](https://pestphp.com) — Framework de tests
- Developpe avec l'aide de [Claude Code](https://claude.ai/code) (Anthropic)

---

<div align="center">
<sub>OpenLMNP est un outil d'aide a la comptabilite. Il ne remplace pas un expert-comptable pour les cas complexes.</sub>
</div>
