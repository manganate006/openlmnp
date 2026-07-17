# Installation — Auto-hébergement OpenLMNP

OpenLMNP est un logiciel **libre** que vous hébergez vous-même. Vos données comptables
restent sur votre machine ou votre serveur : rien n'est envoyé à un tiers.

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

> **Vie privée** : aucune mesure d'audience n'est active par défaut. L'intégration
> Google Tag Manager ne s'active que si vous définissez explicitement `GTM_CONTAINER_ID`.

Pour surcharger une variable au lancement, utilisez `-e` :

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -e APP_URL=https://lmnp.mondomaine.fr \
  --restart unless-stopped openlmnp
```

> `APP_KEY` est générée automatiquement au build. Ne la partagez jamais : elle chiffre
> les sessions et données sensibles.

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

```bash
cd openlmnp
git pull
docker build -t openlmnp .
docker rm -f openlmnp
# Relancez avec la même commande docker run que ci-dessus (volumes conservés)
```

Les données restent intactes tant que vous montez les mêmes volumes `database` et `storage`.

## Sauvegarde et restauration

La sauvegarde est triviale : il suffit de copier deux répertoires.

```bash
# Sauvegarde (avec volumes montés sur /opt/openlmnp-data)
tar czf openlmnp-backup-$(date +%F).tar.gz -C /opt/openlmnp-data database storage
```

Pour restaurer, arrêtez le conteneur, remplacez le contenu de `/opt/openlmnp-data/`
par votre archive, puis relancez le conteneur. Voir aussi la [FAQ](FAQ.md) sur les sauvegardes.

---

Voir aussi : [FONCTIONNALITES.md](FONCTIONNALITES.md) · [DEMO.md](DEMO.md) · [FAQ.md](FAQ.md)
