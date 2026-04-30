<div align="center">

# OpenLMNP

**Open-source accounting software for French furnished rentals (LMNP)**

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Filament](https://img.shields.io/badge/Filament-5.6-FBBF24)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![License](https://img.shields.io/badge/License-AGPLv3-green)
![Tests](https://img.shields.io/badge/Tests-48%20passed-brightgreen)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)

Manage your rental properties, calculate depreciation,
and generate your tax return under the French real regime.

[Version francaise](README.md)

</div>

---

## What is LMNP?

**LMNP** (Location Meublee Non Professionnelle) is the French tax regime for non-professional furnished rental property owners. OpenLMNP helps owners manage their accounting under the "regime reel" (actual expenses regime), which allows deducting real expenses and depreciation instead of a flat-rate deduction.

## Features

- **Multi-user** — Each owner sees only their own data
- **Properties** — Address, surfaces, quota share for primary residence, market value
- **Component depreciation** — Building structure, roof, plumbing, fittings (standard durations)
- **Works & Furniture** — Dedicated or prorated depreciation
- **Income** — Manual entry or Airbnb/Booking CSV import
- **Expenses** — Categorized, automatic prorata, receipt uploads
- **Loans** — Auto-generated amortization schedule, deductible interest
- **Simulator** — Micro-BIC vs real regime comparison with verdict
- **Multi-year projection** — 5 to 20 year table
- **Tax return PDF** — Cerfa lines 2031, 2033-A/B/C/D, 2042-C-PRO case mapping
- **FEC compliant** — Article A.47 A-1 LPF, 18 columns, legal format
- **Accounting entries** — Auto-generated (French chart of accounts)
- **CSV export** — Income and expenses
- **Dark mode** — Native Filament support
- **In-app documentation** — Complete user guide (French)
- **48 automated tests** — Pest PHP, 126 assertions

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Framework | Laravel 13 |
| Admin UI | Filament 5 |
| Reactivity | Livewire 4 |
| Database | SQLite (PostgreSQL optional) |
| PDF | DomPDF |
| Financial math | PHP bcmath (decimal precision) |
| Tests | Pest PHP |
| Deployment | Docker |

## Quick Start (Docker)

```bash
git clone https://github.com/manganate006/openlmnp.git
cd openlmnp
docker build -t openlmnp .
docker run -d --name openlmnp -p 8090:8000 --restart unless-stopped openlmnp
```

Access: `http://localhost:8090/app`
Demo account: `demo@openlmnp.fr` / `demo1234`

## Development Setup

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

## Tests

```bash
vendor/bin/pest
```

48 tests, 126 assertions covering: depreciation calculations, fiscal result, loan amortization, Airbnb CSV import, FEC generation, accounting entries, all Filament pages, and data isolation between users.

## French Tax Context

This software generates documents aligned with French tax forms:

| Form | Purpose |
|------|---------|
| **2031-SD** | BIC income declaration |
| **2033-A** | Simplified balance sheet |
| **2033-B** | Simplified income statement (lines 218-372) |
| **2033-C** | Fixed assets and depreciation (lines 430-572) |
| **2033-D** | Reportable deficits |
| **FEC** | Accounting entries file (legal requirement) |
| **2042-C-PRO** | Personal income tax (cases 5NA/5NK) |

## License

[AGPLv3](LICENSE) — Free software. You can use, modify and redistribute it
as long as you share modifications under the same license.

## Credits

- [Laravel](https://laravel.com) — PHP Framework
- [Filament](https://filamentphp.com) — Admin panel
- [Pest PHP](https://pestphp.com) — Testing framework
- Built with [Claude Code](https://claude.ai/code) (Anthropic)

---

<div align="center">
<sub>OpenLMNP is an accounting aid tool. It does not replace a professional accountant for complex cases.</sub>
</div>
