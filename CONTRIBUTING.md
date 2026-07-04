# Contribuer à OpenLMNP

Merci de votre intérêt pour OpenLMNP ! Ce logiciel libre (licence **AGPLv3**) vit grâce
aux contributions de sa communauté. Ce guide explique comment participer efficacement.

## Sommaire

- [Signaler un bug ou proposer une idée](#signaler-un-bug-ou-proposer-une-idée)
- [Proposer une modification (Pull Request)](#proposer-une-modification-pull-request)
- [Lancer les tests](#lancer-les-tests)
- [Conventions de code](#conventions-de-code)
- [Environnement de développement](#environnement-de-développement)

## Signaler un bug ou proposer une idée

Ouvrez une **issue** sur le dépôt GitHub. Avant de créer une issue :

- Vérifiez qu'une issue similaire n'existe pas déjà.
- Pour un **bug** : décrivez le comportement attendu, le comportement observé, et les étapes
  pour le reproduire (version, environnement, capture d'écran si utile).
- Pour une **idée** : expliquez le besoin fiscal ou comptable concret que la fonctionnalité
  couvrirait.

Pour toute modification non triviale, **ouvrez une issue avant de coder** afin d'en discuter
l'approche : cela évite de développer quelque chose qui ne pourra pas être fusionné.

## Proposer une modification (Pull Request)

1. **Forkez** le dépôt et clonez votre fork.
2. Créez une **branche dédiée** :
   ```bash
   git checkout -b feature/ma-fonctionnalite
   ```
3. Développez votre modification (voir [conventions de code](#conventions-de-code)).
4. **Lancez les tests** et assurez-vous qu'ils passent tous (voir ci-dessous).
5. Ajoutez ou mettez à jour les **tests** couvrant votre changement.
6. Rédigez un message de commit clair, au format
   [Conventional Commits](https://www.conventionalcommits.org/) :
   ```bash
   git commit -m "feat: description concise du changement"
   ```
   Préfixes usuels : `feat:` (fonctionnalité), `fix:` (correction), `docs:` (documentation),
   `test:` (tests), `refactor:` (remaniement).
7. Poussez votre branche et **ouvrez une Pull Request** vers `main`, en décrivant le
   « pourquoi » autant que le « quoi ».

Une bonne PR est **ciblée** : une seule préoccupation à la fois, plutôt que plusieurs
changements sans rapport regroupés ensemble.

## Lancer les tests

OpenLMNP est couvert par une suite de tests **Pest PHP**. Elle doit passer intégralement
avant toute PR.

```bash
# Lancer tous les tests
vendor/bin/pest

# Lancer un sous-ensemble ciblé
vendor/bin/pest --filter="Depreciation"
vendor/bin/pest --filter="FiscalYear"
vendor/bin/pest --filter="Airbnb"
vendor/bin/pest --filter="Filament"
```

Les tests couvrent notamment les calculs d'amortissement, le résultat fiscal, les emprunts,
l'import CSV, le FEC, la génération de la liasse, et les pages Filament (dont l'isolation
des données entre utilisateurs). Toute nouvelle logique métier — en particulier les calculs
financiers — doit être **accompagnée de tests**.

## Conventions de code

- **Langue du code** : **anglais** — noms de variables, classes, méthodes, tables.
- **Langue de l'interface utilisateur** : **français** (l'application s'adresse à un public
  francophone). Pensez aux **accents corrects** (é, è, à, ç, œ…).
- **Commentaires** : en français si cela clarifie le contexte métier fiscal, sinon en anglais.
- **Calculs financiers** : utilisez **bcmath** exclusivement, jamais de type `float`
  (erreurs d'arrondi inacceptables en comptabilité).
- **Montants en base** : stockés en **centimes** (entiers) pour éviter toute imprécision.
- **Dates** : format **ISO 8601** (`Y-m-d`).
- **Style** : respectez les conventions Laravel et le style du code existant. Si le projet
  fournit un outil de formatage (par ex. Laravel Pint), passez-le avant de committer.

## Environnement de développement

Installation locale sans Docker :

```bash
git clone <votre-fork>
cd openlmnp
composer install
cp .env.docker .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
php artisan serve
```

L'application tourne sur `http://localhost:8000`. Voir [docs/INSTALLATION.md](docs/INSTALLATION.md)
pour les prérequis détaillés (PHP 8.4, extensions, Node.js pour les assets).

---

En contribuant, vous acceptez que vos apports soient distribués sous la licence
**AGPLv3** du projet. Merci pour votre aide !
