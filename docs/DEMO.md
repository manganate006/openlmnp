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
d'exemple entièrement **inventé** — *Villa Les Oliviers* à Mougins — afin d'illustrer toutes
les fonctionnalités du logiciel :

- **1 bien** acquis en juin 2020, mis en location saisonnière en juin 2022, décomposé en
  6 composants d'amortissement ;
- **2 chantiers de travaux** (piscine, aménagement chambre et salle de bain) et
  **5 lignes de mobilier / équipements** ;
- **1 emprunt** de 500 000 € sur 25 ans, rattaché au bien, avec son tableau
  d'amortissement complet ;
- des **recettes Airbnb saisonnières** de juin 2022 jusqu'au dernier mois écoulé, et des
  **charges récurrentes** (taxe foncière, assurance PNO, énergie, internet, comptabilité)
  chaque année depuis 2022 ;
- la **chaîne d'exercices fiscaux clôturés** de 2022 à l'année précédente, avec propagation
  des reports d'amortissements différés.

Le même service alimente le seeder historique `DemoSeeder` (compte fixe `demo@openlmnp.fr`),
conservé pour la rétrocompatibilité et les environnements de développement. Le seed est
idempotent : le relancer **réinitialise** le compte fixe sur ce jeu de données de référence :

```bash
php artisan db:seed --class=DemoSeeder
```

## Prévenir avant d'effacer

Le bac à sable vit `DEMO_TTL_HOURS` (24 h par défaut), et le visiteur doit pouvoir
l'apprendre **avant** d'y avoir investi du temps. Quatre dispositifs, du plus discret au
plus insistant :

| Où | Quand |
|---|---|
| Sous-titre du bouton de connexion | Avant même d'entrer |
| Pastille de compte à rebours (bas gauche) | En continu, dès la première seconde |
| Bandeau bas, non bloquant | Paliers « doux » |
| Modale | Paliers graves |

### Les paliers

`DEMO_REMINDERS` liste des couples `heures restantes:forme`, séparés par des virgules.
Deux formes seulement : `banner` et `modal` — toute autre valeur est **ignorée**, jamais
interprétée par défaut.

```
DEMO_REMINDERS="96:banner,24:modal,23:banner,18:banner,12:banner,6:modal,1:modal"
```

Les paliers s'expriment en heures **restantes**, jamais écoulées. C'est ce qui supprime tout
cas particulier entre un bac à sable de 24 h et un bac à sable prolongé à 7 jours : « il
reste 6 h » a le même sens pour les deux.

Un palier n'est retenu que s'il est **strictement sous la durée de vie** du bac à sable —
sans quoi « il reste 24 h » se déclencherait à la première seconde d'un sandbox de 24 h, qui
n'a jamais 24 h pleines devant lui mais 23 h 59.

`DEMO_REMINDER_MIN_GAP_HOURS` (2 par défaut) empêche deux relances rapprochées : servir un
palier rend caducs ceux situés juste en dessous.

### Prolongation

Le visiteur peut prolonger **une fois** de `DEMO_EXTENDED_TTL_DAYS` jours (7 par défaut), en
laissant une adresse e-mail avec consentement explicite. Il reçoit alors un **lien de
reprise** signé, qui le reconnecte à son bac à sable depuis n'importe quel appareil et sans
mot de passe — traiter ce lien comme une clé : qui l'a, y entre.

Un rappel part avant l'effacement (`openlmnp:demo-expiry-notify`, planifiée toutes les heures
**avant** la purge). Il n'écrit qu'aux comptes ayant laissé une adresse **et** donné leur
consentement, et jamais sur une date d'expiration inconnue.

⚠️ Les bacs à sable prolongés sont **exclus** du plafond `DEMO_MAX_ACCOUNTS` : vivant 7 jours
au lieu de 24 h, les compter reviendrait à laisser quelques dizaines de visiteurs occuper
tout le quota pendant une semaine.

### Sur une instance auto-hébergée

`DEMO_URL_PRO` est **vide par défaut** : aucune offre commerciale n'est proposée, et « garder
mes données » propose alors directement la prolongation. Rien à désactiver.

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
| `DEMO_REMINDERS` | `96:banner,24:modal,23:banner,18:banner,12:banner,6:modal,1:modal` | Paliers de relance, en **heures restantes** |
| `DEMO_REMINDER_MIN_GAP_HOURS` | `2` | Espacement minimal entre deux relances |
| `DEMO_EXTENDED_TTL_DAYS` | `7` | Durée de la prolongation accordée contre une adresse |
| `DEMO_URL_PRO` | *(vide)* | Cible commerciale de « garder mes données ». Vide = pas d'offre |
| `DEMO_IFRAME_TIMEOUT_MS` | `4000` | Délai avant de considérer que le cadre d'offre ne s'affichera pas |

---

Voir aussi : [INSTALLATION.md](INSTALLATION.md) · [FONCTIONNALITES.md](FONCTIONNALITES.md) · [FAQ.md](FAQ.md)

