<div align="center">

# OpenLMNP

**Open source accounting software for French LMNP furnished rentals**

Manage your furnished rental properties, compute depreciation,
and produce your French tax return under the « régime réel », with no subscription and on your own server.

[![Tests](https://github.com/manganate006/openlmnp/actions/workflows/tests.yml/badge.svg)](https://github.com/manganate006/openlmnp/actions/workflows/tests.yml)
[![Release](https://img.shields.io/github/v/release/manganate006/openlmnp?label=Release&color=blue)](https://github.com/manganate006/openlmnp/releases)
[![License](https://img.shields.io/github/license/manganate006/openlmnp?label=License&color=green)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Filament](https://img.shields.io/badge/Filament-5-FBBF24)
![Docker Pulls](https://img.shields.io/docker/pulls/manganate06/openlmnp?logo=docker&logoColor=white&label=Docker%20pulls)

**[🌐 Website](https://openlmnp.fr)** · **[🚀 Live demo](https://app.openlmnp.fr)** · **[📦 Install](#quick-install-docker)** · **[📚 Documentation](#documentation)** · **[🇫🇷 Français](README.md)**

![OpenLMNP 2.0 demo: dashboard, properties, expenses, AI receipt import, receipt vault, simulator, projection and filing helper with the 2031 bis appendix](docs/screenshots/demo.gif)

</div>

> **🎯 Try it without installing anything**: the [online demo](https://app.openlmnp.fr) spawns an
> ephemeral, isolated sandbox for each visitor, pre-filled with several fiscal years of fictional accounting (since 2022).
> Login: `demo@openlmnp.fr` / `demo2026`.

> **🆕 New in 2.0**: an encrypted vault for your receipts, AI document analysis (optional,
> your own key or a local model), joint-ownership files with the 2031 bis appendix. Details in
> the [changelog](CHANGELOG.md#200---2026-11-09) (in French); before updating, read
> [Upgrading to 2.0](#upgrading-to-20).

## Table of contents

- [Why OpenLMNP?](#why-openlmnp)
- [Features](#features)
- [Screenshots](#screenshots)
- [Documentation](#documentation)
- [Quick install (Docker)](#quick-install-docker)
- [Upgrading to 2.0](#upgrading-to-20)
- [Other installs](#other-installs)
- [Configuration](#configuration)
- [Backup](#backup)
- [Tests](#tests)
- [Contributing](#contributing)
- [How to support us?](#-how-to-support-us)
- [Acknowledgements](#acknowledgements)
- [License](#license)

## Why OpenLMNP?

For a French furnished rental, the « régime réel » tax regime almost always beats the
micro-BIC flat allowance, but it requires component-based depreciation, a full tax return
(forms 2031, 2033) and a legally compliant FEC ledger. The usual options each have a flaw:

| | **OpenLMNP** | DIY spreadsheet | Accounting SaaS | Accountant |
|---|---|---|---|---|
| **Cost** | Free (AGPLv3) | Free | Yearly subscription | Yearly fees |
| **Your data** | At home (self-hosted) | At home | Third-party cloud | Third party |
| **Component depreciation** | ✅ Automatic | ⚠️ Formulas to maintain | ✅ | ✅ |
| **Tax return + FEC** | ✅ Generated | ❌ | ✅ | ✅ |
| **Cent-accurate math (bcmath)** | ✅ | ⚠️ Floating-point rounding | ✅ | ✅ |

OpenLMNP automates the « régime réel » end to end while remaining an **assistance tool**:
for complex situations (para-hotel VAT, switching to LMP status, jointly managed joint
ownership...), a chartered accountant is still recommended.

## Features

### 🏠 Accounting & depreciation
- **Component-based depreciation**: structure, roof, plumbing, fittings (standard durations), split by percentage or by amount
- **Works & furniture**: dedicated or prorated depreciation, new/second-hand handling
- **Loans**: automatic amortization schedule, deductible interest
- **Recurring expenses**: monthly or quarterly instalments generated, one-click expense duplication
- **Accounting entries**: generated automatically following the LMNP chart of accounts
- **Multi-property**: address, surfaces, quota share, situation (separate unit, part of or independent unit within the main residence), micro-BIC classification
- **Dedicated loan**: a loan that only finances the rented unit is deducted in full, with no quota share
- **Joint ownership (indivision)**: a file in the name of the joint owners, each owner's share, the **2031 bis-SD** appendix and the amount each one reports on their own 2042-C-PRO
- **Take over existing books**: 5-step wizard starting from your accountant's last tax return, checked figure by figure

### 📋 Tax & filings
- **Chained fiscal years**: prior-year carryovers, deferred depreciation, capping rules, carried-forward losses tracking
- **Micro-BIC vs régime réel simulator**: with a quantified verdict, official rates and caps (above the cap, micro-BIC is shown as unavailable)
- **Multi-year projection**: 5 to 20 year table, regime switch year
- **Interactive filing helper**: Cerfa 2031, 2033-A/B/C/D, 2042-C-PRO lines with "Copy" buttons, filing date and receipt number recorded
- **Tax return PDF**: 2031-SD and 2033-A to D, "result breakdown" and "fixed assets" appendices, cross-form consistency checks
- **Compliant FEC**: article A.47 A-1 of the French tax code, 18 columns, legal format
- **VAT return**: for a VAT-registered property

### 🔌 Import & integrations
- **Airbnb / Booking CSV import**: FR/EN formats, duplicate detection; generic CSV import for income, expenses, furniture and works
- **AI document analysis** *(optional, off by default)*: drop an invoice, a property-tax notice, a bank loan schedule, a bank statement or the yearly Airbnb, Booking or Abritel statement. Fields are suggested, you review before anything is saved
- **AI reading of the tax return** in the takeover wizard: the 2033-C table and carry-over boxes are pre-filled from your accountant's return, for you to review
- **AI provider of your choice**: OpenRouter, Anthropic, Google, OpenAI, Mistral (receipts processed in Europe), or a model running on your own machine, and then nothing leaves it
- **CSV export**: income, expenses, filing lines
- **MCP API (46 tools)**: drive your accounting from an AI assistant (Claude, etc.), shared joint-ownership files included
- **Built-in assistant (optional)**: answers your questions about using the software, never gives tax advice and never touches your data
- **New versions flagged**: in the app, based on GitHub releases (a Docker instance updates with `docker pull`)

### 🏛️ Public data
Each service has its own switch, and each one says what it sends:
- **Market value (DVF)**: estimated from actual sales in the town; on click, only the town is sent to data.gouv.fr
- **Sirene**: company active or closed, legal form and APE code of your SIREN; on click, only the SIREN is sent
- **Energy rating (DPE, ADEME)**: certificate found at the property's address and what its class allows; on click, the address is sent to IGN and ADEME
- **Rent map (ANIL)**: rent per m² benchmark in the simulator, imported yearly without any property data
- **Address autocomplete (BAN)**: the beginning of the address is sent to IGN while you type

### 🛡️ Comfort & safety
- **Receipt vault** *(optional)*: files encrypted with a passphrase the instance never knows. Lose it and it cannot be recovered
- **Multi-user**: each owner only ever sees their own data; registration open, closed or **by invitation**
- **Linked files** *(optional)*: a joint-ownership file shared between co-owners, opened in one click from their own account
- **Guided wizards**: onboarding, property creation, fiscal year closing, loan, yearly import
- **Receipts**: files attached to expenses, works and furniture, ZIP export
- **Built-in guide & progress badges**: getting started, regular bookkeeping, yearly filing
- **Diagnostic report** to report a calculation issue, **dark mode**, [automated tests](docs/TESTS.md) run on every push

## Screenshots

| Receipt import (AI) | Receipt vault | Joint ownership and 2031 bis |
|---|---|---|
| [![Receipt import: what is sent to the AI service](docs/screenshots/import-justificatif.png)](docs/screenshots/import-justificatif.png) | [![Receipt vault](docs/screenshots/coffre-fort.png)](docs/screenshots/coffre-fort.png) | [![Filing helper for a joint-ownership file with the 2031 bis appendix](docs/screenshots/indivision-2031bis.png)](docs/screenshots/indivision-2031bis.png) |

More screenshots: [simulator](docs/screenshots/simulateur.png) · [projection](docs/screenshots/projection.png) · [filing helper](docs/screenshots/teledeclaration.png) · [dashboard](docs/screenshots/dashboard.png) · [expenses](docs/screenshots/charges.png)

## Documentation

In French (the software targets French tax law): [Installation](docs/INSTALLATION.md) ·
[Features](docs/FONCTIONNALITES.md) · [FAQ](docs/FAQ.md) · [LMNP / Airbnb tax guide](docs/fiscalite-lmnp-airbnb.md) ·
[Demo mode](docs/DEMO.md) · [Tests](docs/TESTS.md) · [UI design](docs/ui-design-openlmnp.md) ·
[Changelog](CHANGELOG.md) · [Security](SECURITY.md) · [Contributing](CONTRIBUTING.md)

## Tech stack

Laravel 13 · Filament 5 · Livewire 4 · SQLite (PostgreSQL optional) · DomPDF · PHP bcmath
math (decimal precision) · Pest PHP · Docker (amd64 and arm64).

## Quick install (Docker)

Using the official [`manganate06/openlmnp`](https://hub.docker.com/r/manganate06/openlmnp) image:

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -v openlmnp-database:/var/www/html/database \
  -v openlmnp-storage:/var/www/html/storage \
  --restart unless-stopped manganate06/openlmnp:latest
```

Or building the image from source, with the provided [`docker-compose.yml`](docker-compose.yml):

```bash
git clone https://github.com/manganate006/openlmnp.git
cd openlmnp
docker compose up -d
```

Access: `http://localhost:8090`
First login: create your account on `/register`: the first account becomes the
administrator, and sign-up then closes by itself.

## Upgrading to 2.0

**Back up first** (see [Backup](#backup)), then `docker pull manganate06/openlmnp:latest`
and restart the container on the same volumes.

- The container runs as `www-data`: volume ownership is fixed on startup.
- **Rolling back to 1.6.11 works** without restoring the database, but an **enabled vault**
  must be decrypted first: `php artisan openlmnp:vault-migrate <email> --decrypt`.
- AI stays off (`AI_ENABLED=false`) until you configure it.
- The FEC of fiscal years closed before 2.0 counted the platform commission twice:
  `php artisan openlmnp:repair-accounting-entries` lists them (`--fix --closed` to rebuild).

Details, in French: [changelog](CHANGELOG.md#200---2026-11-09) and
[docs/INSTALLATION.md](docs/INSTALLATION.md#mise-à-jour).

## Other installs

- **PaaS (Coolify, Dokploy, Easypanel)**: [`docker-compose.coolify.yml`](docker-compose.coolify.yml)
  is ready to use ([details](docs/INSTALLATION.md#déploiement-sur-un-paas-coolify-dokploy-easypanel)).
- **Proxmox LXC** (Debian 13, nginx, PHP 8.4-FPM; random admin password in
  `/opt/openlmnp/admin_credentials.txt`):
  ```bash
  bash -c "$(curl -fsSL https://raw.githubusercontent.com/manganate006/openlmnp/main/community-scripts/ct/openlmnp.sh)"
  ```
- **Development**:
  ```bash
  git clone https://github.com/manganate006/openlmnp.git && cd openlmnp
  composer install && cp .env.docker .env && php artisan key:generate
  touch database/database.sqlite && php artisan migrate:fresh --seed && php artisan serve
  ```

## Configuration

Once the administrator account is created, add your SIREN number in your profile for tax
documents. Main variables:

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_CONNECTION` | Database | `sqlite` |
| `DB_DATABASE` | SQLite path | `database/database.sqlite` |
| `APP_URL` | Public address of the instance, scheme included (`https://lmnp.example.com`) | `http://localhost` |
| `TRUSTED_HOSTS` | Other host names accepted when `APP_URL` holds a domain (IP, aliases), comma-separated (otherwise error 400) | empty |
| `APP_LOCALE` | Language | `fr` |
| `ALLOW_REGISTRATION` | Registration: `auto` (until first account), `true` (always), `false` (never), `invite` (by an administrator's invitation) | `auto` |
| `AI_ENABLED` / `AI_PROVIDER` | AI features and provider (`openrouter`, `anthropic`, `gemini`, `mistral`, `openai`, `local`); see [the guide](docs/INSTALLATION.md#intelligence-artificielle-optionnel) | `false` / `auto` |
| `DOCUMENTS_VAULT_ENABLED` | Offers the receipt vault (each user turns it on or not) | `true` |
| `LINKED_DOSSIERS_ENABLED` | Linked files, shared between co-owners | `false` |
| `FEEDBACK_ENABLED` | One-off invitation to give feedback; it stays in your database, nothing is sent without you | `true` |
| `TELEMETRY_ENABLED` | Anonymous daily ping (random identifier + version) to count installed instances; no accounting or personal data | `true` |
| `DVF_ENABLED`, `SIRENE_ENABLED`, `DPE_ENABLED`, `RENT_MAP_ENABLED`, `ADDRESS_LOOKUP_ENABLED` | Public data services (see [Features](#features)), one switch each | `true` |

### Emails (optional)

No email is sent by default. The "Forgot password" link works once the `MAIL_*` variables are
set; without SMTP, run `php artisan openlmnp:reset-password you@example.com` on the server.
All variables: [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Backup

Two volumes: `database` (SQLite, in WAL mode) and `storage` (receipts). Do not copy
`database.sqlite` while running: use `sqlite3 database.sqlite ".backup 'snapshot.sqlite'"`
or stop the container. [Full procedure](docs/INSTALLATION.md#sauvegarde-et-restauration).

## Tests

Pest PHP suite run on every push (calculations, tax return and FEC, pages, multi-user isolation):
`vendor/bin/pest`, or `vendor/bin/pest --filter="Depreciation"` for one group.
Details: [docs/TESTS.md](docs/TESTS.md).

## Contributing

Contributions are welcome! Please open an issue before submitting a PR, and read
[CONTRIBUTING.md](CONTRIBUTING.md). Security vulnerabilities must be reported **privately**,
never in a public issue: see [SECURITY.md](SECURITY.md). Run `vendor/bin/pest` before opening a PR.

## 💛 How to support us?

OpenLMNP is developed in my free time. If the tool is useful to you: ⭐ star the repo,
💛 [GitHub Sponsors](https://github.com/sponsors/manganate006), or 🐛 a bug or idea in the
[issues](https://github.com/manganate006/openlmnp/issues). Thank you!

## Acknowledgements

OpenLMNP owes a lot to the people who installed it, tried it on their own books and told
us what was wrong: [@cocool97](https://github.com/cocool97),
[@ptitzgeg-on-git](https://github.com/ptitzgeg-on-git), [@ovrtn](https://github.com/ovrtn)
and [@DamienFr](https://github.com/DamienFr). Their contributions (fixes, reports, security)
are listed on the [acknowledgements page](https://openlmnp.fr/remerciements) (in French).

## License

[AGPLv3](LICENSE), free software. You may use, modify and redistribute it,
provided modifications are shared under the same license.

## Credits

Built with [Laravel](https://laravel.com), [Filament](https://filamentphp.com) and [Pest PHP](https://pestphp.com).

---

<div align="center">
<sub>OpenLMNP is an accounting assistance tool. It does not replace a chartered accountant for complex cases.</sub>
</div>
