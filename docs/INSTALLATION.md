# Installation — Auto-hébergement OpenLMNP

OpenLMNP est un logiciel **libre** que vous hébergez vous-même. **Aucune donnée
comptable, personnelle ou fiscale ne quitte jamais votre serveur.** Le logiciel envoie
seulement, une fois par jour, un compteur anonyme d'installation (un identifiant aléatoire
et le numéro de version, rien d'autre) — entièrement désactivable
(voir [Télémétrie anonyme](#télémétrie-anonyme)).

La méthode recommandée est **Docker**. Une installation en environnement de développement
(sans Docker) est également décrite plus bas.

## Sommaire

- [Prérequis](#prérequis)
- [Installation Docker (recommandée)](#installation-docker-recommandée)
- [Persistance des données (volumes)](#persistance-des-données-volumes)
- [Comptes et connexion](#comptes-et-connexion)
- [Emails (optionnel)](#emails-optionnel)
- [Variables d'environnement](#variables-denvironnement)
- [Installation LXC Proxmox (script communautaire)](#installation-lxc-proxmox-script-communautaire)
- [Installation développement (sans Docker)](#installation-développement-sans-docker)
- [Mise à jour](#mise-à-jour)
- [Sauvegarde et restauration](#sauvegarde-et-restauration)

## Prérequis

- **Docker** installé ([docker.com/get-started](https://docs.docker.com/get-docker/))
- Un port libre sur la machine hôte (par défaut `8090`)

C'est tout : l'image Docker embarque PHP 8.4, les extensions nécessaires et SQLite.
Aucune base de données externe n'est requise.

## Installation Docker (recommandée)

**Option A — image officielle** ([`manganate06/openlmnp`](https://hub.docker.com/r/manganate06/openlmnp)
sur Docker Hub, publiée à chaque release, amd64 + arm64) :

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -v /opt/openlmnp-data/database:/var/www/html/database \
  -v /opt/openlmnp-data/storage:/var/www/html/storage \
  --restart unless-stopped manganate06/openlmnp:latest
```

**Option B — construire l'image depuis les sources** :

```bash
# 1. Récupérer le code
git clone https://github.com/manganate006/openlmnp.git
cd openlmnp

# 2. Construire l'image
docker build -t openlmnp .

# 3. Lancer le conteneur
docker run -d --name openlmnp -p 8090:8000 --restart unless-stopped openlmnp
```

L'application est ensuite accessible sur **`http://localhost:8090`**.

> Le conteneur expose le port interne `8000`. Ici il est publié sur `8090` de l'hôte
> (`-p 8090:8000`). Adaptez le premier nombre si `8090` est déjà utilisé.

Au premier démarrage, l'entrypoint crée automatiquement la base SQLite et applique
les migrations. Si une base existe déjà (voir volumes ci-dessous), elle est conservée
et seules les nouvelles migrations sont appliquées.

## Persistance des données (volumes)

Par défaut, les données vivent **à l'intérieur** du conteneur et sont perdues si vous
le supprimez. Pour les conserver entre deux reconstructions, montez deux volumes :

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -v /opt/openlmnp-data/database:/var/www/html/database \
  -v /opt/openlmnp-data/storage:/var/www/html/storage \
  --restart unless-stopped openlmnp
```

| Volume | Rôle |
|--------|------|
| `/var/www/html/database` | Base de données SQLite (`database.sqlite`) |
| `/var/www/html/storage`  | Justificatifs uploadés, logs, cache, sessions |

L'`Dockerfile` déclare déjà ces deux chemins comme volumes. En les mappant sur des
répertoires de l'hôte (ici `/opt/openlmnp-data/`), vous pouvez reconstruire l'image
sans jamais perdre vos écritures comptables.

## Comptes et connexion

Au premier accès, créez votre compte sur **`/register`** : le **premier compte devient
automatiquement administrateur**. La page d'inscription **se referme ensuite toute
seule** — par défaut (`ALLOW_REGISTRATION=auto`), une instance est prévue pour un seul
utilisateur, personne d'autre ne peut s'inscrire.

Deux variantes possibles :

- Plusieurs comptes sur la même instance (couple, associés…) : lancez le conteneur
  avec `-e ALLOW_REGISTRATION=true` — l'inscription reste ouverte en permanence et
  chaque compte ne voit que ses propres biens et écritures.
- Fermer complètement : `-e ALLOW_REGISTRATION=false`.

> Avec le script LXC Proxmox, le compte administrateur est créé pendant
> l'installation (identifiants dans `/opt/openlmnp/admin_credentials.txt`) —
> l'inscription est donc déjà refermée.

**Mot de passe oublié ?** Trois solutions, de la plus simple à la plus autonome :

1. Si vous avez configuré un SMTP (voir ci-dessous) : le lien « Mot de passe
   oublié » de la page de connexion fonctionne normalement.
2. Sans SMTP, depuis le serveur :
   ```bash
   docker exec openlmnp php artisan openlmnp:reset-password vous@exemple.fr
   # → affiche un lien de réinitialisation valable 60 minutes
   # ou directement :
   docker exec openlmnp php artisan openlmnp:reset-password vous@exemple.fr --password="NouveauMotDePasse"
   ```
3. En développement (`LOG_LEVEL=debug`), les emails — lien de réinitialisation
   inclus — sont écrits dans `storage/logs/laravel.log`.

## Emails (optionnel)

**Par défaut, aucun email ne part** (`MAIL_MAILER=log`) et l'application est
pleinement utilisable ainsi. Configurer un envoi réel ne sert qu'au lien « Mot de
passe oublié » : utile si plusieurs personnes utilisent l'instance, dispensable
sinon (la commande CLI ci-dessus couvre le besoin).

Pour de vrais envois, branchez le SMTP **de votre choix** (messagerie de votre
fournisseur d'accès, Gmail avec un mot de passe d'application, Brevo…) :

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -e MAIL_MAILER=smtp \
  -e MAIL_HOST=smtp.exemple.fr \
  -e MAIL_PORT=587 \
  -e MAIL_USERNAME=vous@exemple.fr \
  -e MAIL_PASSWORD=******** \
  -e MAIL_FROM_ADDRESS=vous@exemple.fr \
  -e MAIL_FROM_NAME=OpenLMNP \
  … (volumes habituels) … openlmnp
```

L'expéditeur est **votre propre adresse**. La délivrabilité (SPF/DKIM) est gérée
par votre fournisseur de messagerie : rien à configurer côté OpenLMNP.

## Variables d'environnement

L'image utilise le fichier `.env.docker` fourni. Les variables non sensibles utiles :

| Variable | Description | Défaut |
|----------|-------------|--------|
| `APP_NAME` | Nom affiché de l'application | `OpenLMNP` |
| `APP_URL` | URL publique de l'instance | `http://localhost:8090` |
| `APP_LOCALE` | Langue de l'interface | `fr` |
| `DB_CONNECTION` | Moteur de base de données | `sqlite` |
| `DB_DATABASE` | Chemin du fichier SQLite | `/var/www/html/database/database.sqlite` |
| `LOG_LEVEL` | Niveau de journalisation | `warning` |
| `ALLOW_REGISTRATION` | Inscription publique : `auto` = ouverte jusqu'au premier compte, `true` = toujours, `false` = jamais | `auto` |
| `MAIL_MAILER` | `log` (aucun envoi) ou `smtp` + variables `MAIL_*` (voir [Emails](#emails-optionnel)) | `log` |
| `PROVISION_TOKEN` | Active l'API de création de comptes `POST /api/admin/users` (automatisations avancées). Vide = API désactivée (404) | *(vide)* |
| `DEMO_MODE` | Active le mode démonstration (voir [DEMO.md](DEMO.md)) | `false` |
| `GTM_CONTAINER_ID` | Identifiant Google Tag Manager (`GTM-XXXXXXX`). Vide = aucun script de mesure injecté | *(vide)* |
| `GTM_SERVER_URL` | URL d'un GTM server-side auto-hébergé (sinon serveurs Google) | `https://www.googletagmanager.com` |
| `GTM_SCRIPT_PATH` | Chemin du script GTM (utile si renommé côté serveur) | `/gtm.js` |
| `TELEMETRY_ENABLED` | Check-in anonyme quotidien (identifiant aléatoire + version) pour compter les instances installées. Aucune donnée comptable/personnelle. `false` = désactivé, aucune requête émise | `true` |
| `TELEMETRY_URL` | Endpoint recevant le check-in de télémétrie | `https://openlmnp.fr/api/instances/checkin` |
| `PHP_CLI_SERVER_WORKERS` | Nombre de requêtes servies simultanément (voir ci-dessous) | `4` |
| `DB_JOURNAL_MODE` | Journal SQLite. `WAL` par défaut ; `delete` si la base est sur un stockage réseau | `WAL` |
| `DB_SYNCHRONOUS` | Politique de `fsync` de SQLite | `NORMAL` |
| `DB_BUSY_TIMEOUT` | Attente maximale d'un verrou, en millisecondes | `5000` |
| `DB_TRANSACTION_MODE` | Mode d'ouverture des transactions SQLite | `IMMEDIATE` |

> **Vie privée** : aucune mesure d'audience n'est active par défaut. L'intégration
> Google Tag Manager ne s'active que si vous définissez explicitement `GTM_CONTAINER_ID`.

### Requêtes simultanées et stockage de la base

Le conteneur sert **4 requêtes en parallèle**. C'est ce qui évite qu'un traitement long
(génération d'une liasse PDF, import d'un relevé, analyse d'un justificatif) ne fige
l'instance pour les autres utilisateurs — et pour vos autres onglets.

Les quatre réglages `DB_*` ci-dessus rendent cette simultanéité sûre pour SQLite ; ils
vont **ensemble**. Vous n'avez normalement rien à changer. Deux exceptions :

- **Base sur un stockage réseau** (NFS, SMB, partage d'un NAS) : le mode WAL a besoin de
  mémoire partagée et **ne fonctionne pas** dans ce cas. Lancez le conteneur avec
  `-e DB_JOURNAL_MODE=delete -e DB_SYNCHRONOUS=FULL`. Mieux encore, si vous le pouvez :
  placez le fichier de la base sur un disque local et ne gardez le réseau que pour les
  sauvegardes.
- **Machine plus puissante** : `-e PHP_CLI_SERVER_WORKERS=8` (ou plus). Chaque worker est
  un processus PHP, mais l'essentiel de son empreinte est partagé — mesuré sur l'instance
  de référence, passer de 1 à 4 workers coûte 7 Mio, et de 1 à 8 workers, 25 Mio.

> ⚠️ Si vous augmentez `PHP_CLI_SERVER_WORKERS`, ne désactivez pas `DB_JOURNAL_MODE=WAL`
> ni `DB_TRANSACTION_MODE=IMMEDIATE` sans nécessité : plusieurs requêtes concurrentes sur
> les réglages SQLite par défaut produisent des erreurs « database is locked ».

**Sauvegarde en mode WAL** : le fichier `database.sqlite` s'accompagne désormais de
fichiers `database.sqlite-wal` et `database.sqlite-shm`. Copier le seul `.sqlite` d'une
instance en service peut donner une base **incomplète**. Utilisez toujours la commande de
sauvegarde de SQLite, qui produit un instantané cohérent :

```bash
sqlite3 /opt/openlmnp-data/database/database.sqlite ".backup '/chemin/sauvegarde.sqlite'"
```

### Télémétrie anonyme

Pour savoir combien d'instances OpenLMNP sont installées, le logiciel envoie **une fois
par jour** un « check-in » minimal au projet :

- un **identifiant aléatoire** (UUID) généré au premier lancement, **sans aucun lien**
  avec vous, vos comptes ou vos biens ;
- le **numéro de version** de l'application ;
- rien d'autre : **aucune donnée comptable, personnelle, fiscale, ni votre adresse IP**
  n'est conservée.

C'est le **seul** flux sortant du logiciel (avec la vérification de mise à jour qui
interroge GitHub). Pour le **désactiver complètement** :

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -e TELEMETRY_ENABLED=false \
  --restart unless-stopped openlmnp
```

Aucune requête n'est alors émise. Vous pouvez aussi le rediriger vers votre propre
collecteur avec `TELEMETRY_URL`.

Pour surcharger une variable au lancement, utilisez `-e` :

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -e APP_URL=https://lmnp.mondomaine.fr \
  --restart unless-stopped openlmnp
```

> `APP_KEY` est générée automatiquement **au premier démarrage** (unique par instance)
> puis conservée dans `storage/app/.app_key` — elle survit aux mises à jour tant que le
> volume `storage` est monté. Vous pouvez aussi l'imposer avec `-e APP_KEY=…`.
> Ne la partagez jamais : elle chiffre les sessions et données sensibles.

## Installation LXC Proxmox (script communautaire)

Sur un hôte Proxmox VE, un script crée un conteneur LXC prêt à l'emploi en une commande :

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/manganate006/openlmnp/main/community-scripts/ct/openlmnp.sh)"
```

Debian 13, nginx + PHP 8.4-FPM, SQLite. Un mot de passe administrateur **aléatoire** est
généré à l'installation et enregistré dans `/opt/openlmnp/admin_credentials.txt`.

## Installation développement (sans Docker)

Pour contribuer au code ou faire tourner l'application localement sans conteneur :

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

L'application tourne alors sur `http://localhost:8000`.

Prérequis : **PHP 8.4** (extensions `pdo_sqlite`, `bcmath`, `intl`, `gd`, `zip`),
**Composer**, et **Node.js 22+** si vous devez recompiler les assets CSS
(`npm install && npm run build`).

## Mise à jour

OpenLMNP intègre une notification de mise à jour dans l'interface (comparaison avec
la dernière version publiée sur GitHub). Pour mettre à jour une installation Docker :

> **L'image Docker est immuable.** Elle ne contient ni `rsync`, ni Composer, ni npm, et
> son opcache tourne sans revalidation des fichiers : le bouton « Mise à jour » de la page
> *Mises à jour* y est donc masqué (`UPDATE_SELF_APPLY=false`), la détection de version
> restant active. On met à jour en récupérant la nouvelle image, jamais en réécrivant le
> code du conteneur.

**Avec l'image officielle (option A)** :

```bash
docker pull manganate06/openlmnp:latest
docker rm -f openlmnp
# Relancez avec la même commande docker run que ci-dessus (volumes conservés)
```

**Avec une image construite localement (option B)** :

```bash
cd openlmnp
git pull
docker build -t openlmnp .
docker rm -f openlmnp
# Relancez avec la même commande docker run que ci-dessus (volumes conservés)
```

Les données restent intactes tant que vous montez les mêmes volumes `database` et `storage`.

### Justificatifs déposés avant la version 1.1

Si votre instance existait avant la montée en Laravel 11, une partie de vos
justificatifs peut avoir cessé d'apparaître dans l'interface. Rien n'est perdu :
Laravel 11 a déplacé la racine du disque de stockage de `storage/app` vers
`storage/app/private`, et les fichiers déposés avant sont restés à l'ancien
emplacement. Depuis la version 1.4.1, l'application va les y chercher toute seule,
donc **vous n'avez rien à faire pour les revoir**.

Pour ranger définitivement ces fichiers au bon endroit, et ne plus dépendre de ce
repli :

```bash
# 1. Rapport : ce qui serait déplacé, sans rien modifier
docker exec openlmnp php artisan openlmnp:migrate-document-storage

# 2. Application, une fois le rapport lu
docker exec openlmnp php artisan openlmnp:migrate-document-storage --fix
```

La commande ne touche jamais à la base de données et n'écrase jamais un fichier
déjà présent à la nouvelle racine : ce cas est signalé, pas exécuté. Elle est sans
effet si votre instance n'est pas concernée.

## Sauvegarde et restauration

Tout tient dans deux répertoires : `database` (la base) et `storage` (vos justificatifs).

```bash
# 1. Instantané cohérent de la base, instance en service (avec volumes sur /opt/openlmnp-data)
sqlite3 /opt/openlmnp-data/database/database.sqlite \
        ".backup '/opt/openlmnp-data/database-snapshot.sqlite'"

# 2. L'instantané + les justificatifs
tar czf openlmnp-backup-$(date +%F).tar.gz \
    -C /opt/openlmnp-data database-snapshot.sqlite storage
rm /opt/openlmnp-data/database-snapshot.sqlite
```

> ⚠️ **Ne copiez pas `database.sqlite` directement pendant que l'instance tourne.** Depuis
> la version 1.5.0 la base est en mode WAL : les écritures récentes vivent dans un fichier
> `database.sqlite-wal` voisin, et copier le seul `.sqlite` donne une base **amputée de ses
> dernières transactions**, sans le moindre message d'erreur. `.backup` (ci-dessus) règle la
> question ; arrêter le conteneur avant de copier fonctionne aussi.

Pour restaurer, arrêtez le conteneur, remplacez `/opt/openlmnp-data/database/database.sqlite`
par l'instantané (renommé) et `storage` par le vôtre, supprimez d'éventuels `-wal`/`-shm`
résiduels, puis relancez le conteneur. Voir aussi la [FAQ](FAQ.md) sur les sauvegardes.

---

Voir aussi : [FONCTIONNALITES.md](FONCTIONNALITES.md) · [DEMO.md](DEMO.md) · [FAQ.md](FAQ.md)
