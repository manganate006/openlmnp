# Mode démonstration

OpenLMNP intègre un **mode démonstration multi-utilisateurs** qui laisse n'importe quel
visiteur essayer le logiciel sans créer de compte. Chaque visiteur obtient un **bac à sable
isolé et éphémère** : sa propre copie de données fictives, invisible des autres visiteurs,
automatiquement supprimée au bout de quelques heures.

Ce mode est idéal pour une instance publique de démonstration. Il est **désactivé par défaut** ;
une installation personnelle classique n'a aucune raison de l'activer.

## Sommaire

- [Activer le mode démo](#activer-le-mode-démo)
- [Accéder à la démo](#accéder-à-la-démo)
- [Fonctionnement : sandbox isolé par visiteur](#fonctionnement--sandbox-isolé-par-visiteur)
- [Données de démonstration](#données-de-démonstration)
- [Purge automatique des comptes expirés](#purge-automatique-des-comptes-expirés)
- [Variables d'environnement](#variables-denvironnement)

## Activer le mode démo

Positionnez la variable d'environnement `DEMO_MODE=true`. Avec Docker :

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -e DEMO_MODE=true \
  --restart unless-stopped openlmnp
```

Ou dans votre fichier `.env` :

```env
DEMO_MODE=true
DEMO_TTL_HOURS=24
DEMO_MAX_ACCOUNTS=200
```

Quand `DEMO_MODE=false` (défaut), tout le dispositif démo est inactif : la route `/demo`
renvoie une erreur 404 et le bouton n'apparaît pas.

## Accéder à la démo

Deux points d'entrée, une fois le mode activé :

- **Bouton « Découvrir la démo »** affiché sur la page de connexion (`/login`)
- **URL directe** `/demo`

Dans les deux cas, le visiteur est immédiatement connecté à un compte de démonstration
tout neuf, pré-rempli de données fictives, et redirigé vers le tableau de bord.

## Fonctionnement : sandbox isolé par visiteur

Le mode démo n'utilise **pas** de compte partagé. À chaque visite de `/demo` :

1. Un **utilisateur éphémère** est créé, avec un email unique généré aléatoirement
   (`demo-xxxxxxxx@demo.local`) et marqué `is_demo = true`.
2. Une **copie privée** des données fictives est générée pour ce seul utilisateur.
3. Le visiteur est connecté à ce compte et n'a accès qu'à ses propres données.

L'isolation entre visiteurs est garantie au niveau du modèle : chaque enregistrement est
rattaché à un `user_id`, et un scope global empêche tout utilisateur de voir ou de modifier
les données d'un autre. Un visiteur peut donc saisir, modifier ou supprimer librement :
il ne touche que **sa** copie, jamais celle d'un autre ni un vrai compte.

Chaque compte démo porte une date d'expiration (`demo_expires_at`), fixée à la création
en ajoutant `DEMO_TTL_HOURS` heures.

## Données de démonstration

Le jeu de données fictif est généré par le service `DemoDataService`. Il décrit un bien
d'exemple entièrement **inventé** — *Villa Les Oliviers* à Mougins — avec ses composants
d'amortissement, travaux, mobilier, recettes, charges et un emprunt, afin d'illustrer toutes
les fonctionnalités du logiciel.

Le même service alimente le seeder historique `DemoSeeder` (compte fixe `demo@openlmnp.fr`),
conservé pour la rétrocompatibilité et les environnements de développement :

```bash
php artisan db:seed --class=DemoSeeder
```

## Purge automatique des comptes expirés

Les comptes démo étant éphémères, une commande les supprime une fois expirés,
avec toutes leurs données rattachées :

```bash
php artisan openlmnp:demo-cleanup
```

La commande ne supprime **que** les utilisateurs `is_demo = true` dont la date d'expiration
est passée. Les comptes réels (`is_demo = false`) ne sont **jamais** touchés.

Cette commande est planifiée **toutes les heures** (voir `routes/console.php`), donc aucune
action manuelle n'est nécessaire sur une instance en fonctionnement. Un mécanisme de sécurité
complémentaire limite le nombre de comptes démo actifs simultanés à `DEMO_MAX_ACCOUNTS` :
au-delà, une purge des expirés est déclenchée, et si la limite reste atteinte, une nouvelle
session démo est refusée temporairement (le service reste protégé contre l'abus).

## Variables d'environnement

| Variable | Description | Défaut |
|----------|-------------|--------|
| `DEMO_MODE` | Active le mode démonstration | `false` |
| `DEMO_TTL_HOURS` | Durée de vie d'un compte démo, en heures | `24` |
| `DEMO_MAX_ACCOUNTS` | Nombre maximum de comptes démo actifs simultanés | `200` |

---

Voir aussi : [INSTALLATION.md](INSTALLATION.md) · [FONCTIONNALITES.md](FONCTIONNALITES.md) · [FAQ.md](FAQ.md)
