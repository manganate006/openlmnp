# Installation — Auto-hébergement OpenLMNP

OpenLMNP est un logiciel **libre** que vous hébergez vous-même. **Aucune donnée
comptable, personnelle ou fiscale ne quitte jamais votre serveur.** Le logiciel envoie
seulement, une fois par jour, un compteur anonyme d'installation (un identifiant aléatoire
et le numéro de version, rien d'autre) — entièrement désactivable
(voir [Télémétrie anonyme](#télémétrie-anonyme)).

> Seule exception, **désactivée par défaut** : si vous activez les
> [fonctions d'intelligence artificielle](#intelligence-artificielle-optionnel), les
> documents que vous soumettez à l'analyse sont envoyés au service d'IA que vous avez
> configuré — qui peut être un modèle tournant sur votre propre machine.

La méthode recommandée est **Docker**. Une installation en environnement de développement
(sans Docker) est également décrite plus bas.

## Sommaire

- [Prérequis](#prérequis)
- [Installation Docker (recommandée)](#installation-docker-recommandée)
- [Persistance des données (volumes)](#persistance-des-données-volumes)
- [Déploiement sur un PaaS (Coolify, Dokploy, Easypanel)](#déploiement-sur-un-paas-coolify-dokploy-easypanel)
- [Comptes et connexion](#comptes-et-connexion)
- [Emails (optionnel)](#emails-optionnel)
- [Intelligence artificielle (optionnel)](#intelligence-artificielle-optionnel)
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

### Utilisateur du conteneur et droits des volumes

L'application ne tourne pas en root : le serveur web et les tâches planifiées s'exécutent
sous l'utilisateur **`www-data`** (UID et GID **82**, celui de l'image PHP officielle). Le
conteneur démarre en root le temps de préparer son environnement, remet les deux volumes
à `www-data`, puis lui passe la main. **Rien à faire à la mise à jour** : un volume créé par
une version précédente, qui appartenait entièrement à root, est corrigé au premier
démarrage.

- Sur l'hôte, les fichiers de `/opt/openlmnp-data/` appartiennent donc à l'UID 82.
- Les commandes `docker exec openlmnp php artisan …` de ce guide s'exécutent
  automatiquement sous `www-data`. Pour un autre outil lancé dans le conteneur, ajoutez
  `-u www-data` ; un fichier créé en root dans un volume serait illisible pour
  l'application jusqu'au redémarrage suivant, qui le corrige.
- Si les volumes ne peuvent pas changer de propriétaire (partage NFS en `root_squash`,
  certains stockages de podman sans privilège), le conteneur le signale dans ses journaux
  et continue de tourner en root, comme avant.
- Démarré directement sous un autre utilisateur (`--user 1000:1000`, `runAsUser` de
  Kubernetes), il ne touche à aucun droit : les deux volumes doivent alors être
  inscriptibles par cet utilisateur.

## Déploiement sur un PaaS (Coolify, Dokploy, Easypanel)

Si vous administrez déjà un PaaS auto-hébergé, le fichier
[`docker-compose.coolify.yml`](../docker-compose.coolify.yml) évite d'écrire la
configuration à la main : la plateforme se charge du domaine, du certificat TLS et du
reverse proxy, l'application n'expose que son port interne `8000`.

**Coolify** — *New Resource* → *Docker Compose (Empty)*, coller le fichier, *Deploy*.
Les variables `SERVICE_URL_*` sont remplies par Coolify au premier déploiement : rien
d'autre à régler pour obtenir un domaine en HTTPS.

**Dokploy, Easypanel, Portainer** — le même fichier convient en retirant la ligne
`SERVICE_URL_OPENLMNP_8000` et en réglant `APP_URL` sur l'URL publique de l'instance.

Trois points de vigilance, communs à toutes ces plateformes :

- **`APP_URL` doit porter le schéma** (`https://votre-domaine`, pas `votre-domaine`) :
  les liens de réinitialisation de mot de passe et les e-mails en sont construits.
- **Les deux volumes sont indispensables.** Sans eux, la base SQLite et les
  justificatifs vivent dans le conteneur et disparaissent à la première mise à jour.
- **Le proxy de la plateforme doit être de confiance** pour que l'application se sache
  en HTTPS. Les plages privées habituelles (dont le réseau Docker) le sont déjà par
  défaut ; `TRUSTED_PROXIES` permet de restreindre à l'adresse exacte du proxy.

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
- **Sur invitation seulement** : `-e ALLOW_REGISTRATION=invite`. Personne ne peut créer
  de compte sans un lien nominatif, que vous émettez depuis *Administration →
  Invitations* ou en ligne de commande :

  ```bash
  php artisan openlmnp:invite ami@exemple.fr --name="Prénom Nom" --days=7
  php artisan openlmnp:invite --list          # état de toutes les invitations
  php artisan openlmnp:invite ami@exemple.fr --resend   # nouveau lien, l'ancien meurt
  php artisan openlmnp:invite ami@exemple.fr --revoke
  ```

  Le lien est **à usage unique**, il expire (14 jours par défaut, `INVITATION_TTL_DAYS`),
  et l'adresse est verrouillée sur le formulaire — une invitation ne peut pas servir à
  créer le compte de quelqu'un d'autre. La commande **imprime toujours le lien**, y
  compris quand l'envoi du courriel échoue : une instance sans SMTP reste utilisable, il
  suffit de le transmettre autrement.

  > ⚠️ **Révoquer n'est pas suspendre.** Si la personne a déjà créé son compte, révoquer
  > son invitation ne lui retire aucun accès — le lien était déjà consommé.

Une fois l'inscription refermée, `/register` n'affiche plus de formulaire : la page renvoie
vers l'écran de connexion en expliquant que les comptes sont ouverts par l'administrateur.
Si votre instance dispose d'une page où l'on obtient un compte (formulaire interne, page
d'abonnement…), déclarez-la avec `-e OPENLMNP_SIGNUP_URL=https://exemple.fr/…` : `/register`
y redirigera, et l'écran de connexion proposera un lien « Créer un compte ». Vide par
défaut — une instance auto-hébergée n'affiche aucune sollicitation.

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

**Supprimer un compte (droit à l'effacement, RGPD art. 17) :**

```bash
docker exec openlmnp php artisan openlmnp:account-delete vous@exemple.fr --dry-run
# → affiche ce qui serait supprimé (biens, exercices, dossiers liés concernés…), sans rien effacer

docker exec openlmnp php artisan openlmnp:account-delete vous@exemple.fr
# → demande confirmation avant de supprimer définitivement le compte et toutes ses données

docker exec openlmnp php artisan openlmnp:account-delete vous@exemple.fr --force
# → saute la confirmation interactive (scripts)
```

Supprime les biens, exercices fiscaux, recettes, charges, amortissements, travaux,
mobilier, emprunts, justificatifs (coffre-fort et fichiers sur disque compris) et
jetons MCP du compte. Un dossier d'indivision dont ce compte est l'unique membre
actif est supprimé avec lui ; un dossier partagé reste accessible aux autres
membres. Refuse le compte de démonstration public et le dernier administrateur de
l'instance. Le même mécanisme est proposé à l'utilisateur lui-même, depuis
*Mon profil → Supprimer mon compte*.

Le journal des dossiers liés suit la même règle : celui d'un dossier supprimé est
effacé, et dans un dossier partagé qui survit, les lignes qui concernent le compte
supprimé gardent l'action et la date mais perdent son adresse e-mail. Pour une base
où des comptes ont été supprimés avant la version 2.0.0 finale :

```bash
docker exec openlmnp php artisan openlmnp:dossier-events-purge --dry-run
# → compte les lignes à supprimer ou à expurger, sans rien modifier

docker exec openlmnp php artisan openlmnp:dossier-events-purge
# → supprime le journal des dossiers qui n'existent plus et retire les adresses
#   des comptes disparus ; sans effet si on la relance
```

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

## Intelligence artificielle (optionnel)

OpenLMNP peut faire appel à un modèle d'intelligence artificielle pour lire vos
justificatifs et vous assister dans l'application. Ces fonctions sont **désactivées par
défaut** : sans `AI_ENABLED=true`, aucune interface d'IA n'apparaît et aucune requête
n'est émise.

Le fournisseur se choisit par `AI_PROVIDER` :

| `AI_PROVIDER` | Service visé | Sait lire un PDF ? | Rend le coût réel ? |
|---|---|---|---|
| `auto` *(défaut)* | déduit d'`AI_BASE_URL`, OpenRouter à défaut | — | — |
| `openrouter` | OpenRouter | **oui** | **oui** |
| `anthropic` | Anthropic, protocole natif | **oui** | non |
| `gemini` | Google Gemini, protocole natif | **oui** | non |
| `mistral` | Mistral AI — **français, données en Europe** | non | non |
| `openai` | OpenAI | non | non |
| `local` | tout serveur compatible OpenAI installé chez vous | non | non |

`AI_BASE_URL` reste prioritaire quand elle est renseignée : c'est ainsi qu'on place un
proxy ou une passerelle d'entreprise devant n'importe lequel de ces services. Un
`AI_PROVIDER` inconnu fait échouer les fonctions d'IA avec un message explicite, plutôt
que de retomber en silence sur OpenRouter — une faute de frappe ne doit pas envoyer vos
justificatifs ailleurs que là où vous croyez les envoyer.

> **Ce que vous perdez hors d'OpenRouter.** Aucun autre service ne renvoie le coût de
> l'appel : le journal et l'écran d'administration l'afficheront vide tant que vous
> n'aurez pas renseigné `AI_MODEL_PRICES`. Le rejeu d'un document déjà analysé n'existe
> pas non plus : une réparation ou une cascade renvoie — et refacture — le document.
> Enfin, un serveur compatible OpenAI (`openai`, `local`) ne sait pas recevoir de PDF :
> OpenLMNP en extrait la couche texte, et un PDF scanné doit être déposé en image.

### Option A — une clé OpenRouter

[OpenRouter](https://openrouter.ai) donne accès à la plupart des modèles du marché avec
une seule clé, à la consommation.

**Connexion en un clic (recommandé).** Laissez `AI_API_KEY` vide : chaque utilisateur
ouvre *Mon profil*, clique sur **« Se connecter avec OpenRouter »**, autorise l'accès, et
revient avec une clé **qui lui appartient** — sans copier-coller, et sans compte à créer
d'avance : OpenRouter propose son écran d'inscription à qui n'en a pas.

Trois points qui étonnent souvent :

- **Aucune inscription d'application n'est nécessaire.** Ni identifiant, ni secret : le
  procédé (OAuth PKCE) n'en demande pas. N'importe quelle instance auto-hébergée peut
  s'en servir telle quelle.
- **Votre instance n'a pas besoin d'être joignable depuis Internet.** OpenRouter ne
  rappelle jamais votre serveur : c'est le navigateur de l'utilisateur, déjà sur votre
  réseau, qui fait l'aller-retour. Un `localhost:8090` fonctionne, sur n'importe quel
  port.
- **Ne mettez pas `SESSION_SAME_SITE=strict`.** Le retour depuis OpenRouter est une
  navigation entre sites : en `strict`, le cookie de session n'est pas renvoyé et la
  connexion échoue à chaque fois, avec un message d'expiration trompeur.

Servie en **HTTP sur autre chose que `localhost`** (une adresse de réseau local, par
exemple), la redirection de retour peut être refusée par OpenRouter, qui ne garantit que
HTTPS et localhost. L'application vous en avertit sous le bouton, sans le désactiver — et
le champ de saisie manuelle reste juste en dessous. Pour le désactiver complètement :
`AI_OAUTH_ENABLED=false`.

**Clé d'instance.** Sur une instance à compte unique, ou pour fournir la clé à tout le
monde, créez-la sur [openrouter.ai/keys](https://openrouter.ai/keys) et passez-la :

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -e AI_ENABLED=true \
  -e AI_API_KEY=sk-or-v1-… \
  --restart unless-stopped openlmnp
```

Une facture de deux pages coûte de l'ordre de 0,003 $ avec le modèle par défaut. Avec une
clé d'instance, les utilisateurs ne voient plus aucun champ « clé » dans leur profil.

Une clé toute neuve n'a **aucun crédit** : les analyses échoueront tant que le compte
n'est pas rechargé. OpenLMNP le dit dès l'enregistrement de la clé quand OpenRouter le
lui indique, et propose le lien de recharge en cas d'échec.

### Option B — Anthropic ou Google en direct

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -e AI_ENABLED=true \
  -e AI_PROVIDER=anthropic \
  -e AI_API_KEY=sk-ant-… \
  --restart unless-stopped openlmnp
```

Remplacez par `-e AI_PROVIDER=gemini -e AI_API_KEY=AIza…` pour Google, ou par
`-e AI_PROVIDER=mistral` pour **Mistral AI** — le seul fournisseur français de la liste, si vous
tenez à ce que vos justificatifs ne quittent pas l'Europe. Les modèles par
défaut de chaque service s'appliquent : ne recopiez **pas** un identifiant de modèle
OpenRouter (`google/gemini-3.7-flash`), il n'est pas valide ailleurs — laissez
`AI_MODEL_*` vide, c'est le choix sûr.

Ces deux fournisseurs parlent leur **protocole natif** : ils reçoivent donc vos PDF tels
quels, sans passer par l'extraction de leur couche texte.

### Éprouver un fournisseur avant de s'en servir

```bash
php artisan ai:check-provider                       # celui qui est configuré
php artisan ai:check-provider --provider=anthropic  # un autre, clé demandée à la saisie
php artisan ai:check-provider --provider=all --force
php artisan ai:check-provider --free-only           # contrôle de clé seul, gratuit
```

La commande rejoue en petit ce que l'application fait en grand : elle vérifie la clé, tient une
conversation en flux, demande un JSON contraint, et fait lire une pièce jointe. Elle rend un code
d'échec si l'une de ces étapes ne passe pas.

C'est le seul moyen de savoir si un fournisseur convient : **un catalogue peut annoncer une
capacité qu'il n'honore pas**. Ce dépôt en a fait l'expérience — un modèle annoncé compatible avec
les sorties structurées encadrait son JSON d'un bloc de code, et l'extraction ne fonctionnait pas.
Seuls les trois derniers contrôles sont facturés, de l'ordre de quelques centimes.

> ⚠️ Contrairement à OpenRouter, ces deux protocoles n'ont **pas été validés par un appel
> réel** depuis ce dépôt. Éprouvez-les sur un document de test avant de vous en servir
> pour de bon — c'est la règle du projet pour tout nouveau modèle, et elle vaut a fortiori
> pour un nouveau protocole.

### Option C — un modèle local, rien ne sort de chez vous

`AI_BASE_URL` accepte n'importe quel serveur exposant l'API OpenAI : **Ollama**,
LM Studio, vLLM, llama.cpp. Vos documents ne quittent alors jamais votre réseau.

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -e AI_ENABLED=true \
  -e AI_BASE_URL=http://ollama:11434/v1 \
  -e AI_API_KEY=ollama \
  -e AI_MODEL_DOCUMENTS=qwen2.5vl:7b \
  -e AI_MODEL_CHAT=qwen2.5vl:7b \
  --restart unless-stopped openlmnp
```

Choisissez un modèle **capable de lire des images** pour l'analyse de documents.

#### Les PDF sur un serveur local

`AI_PDF_ENGINE` est un paramètre du service de lecture de fichiers **d'OpenRouter** : il
n'a aucun effet ailleurs, aucun autre service configurable ici ne sachant quoi faire d'un
PDF joint. OpenLMNP le gère pour vous : chez un fournisseur qui ne sait pas les recevoir,
il **extrait la couche texte du PDF** et envoie ce texte au modèle.

| `AI_PDF_TEXT_FALLBACK` | Effet |
|---|---|
| `auto` *(défaut)* | Extrait le texte dès que le fournisseur ne sait pas recevoir un PDF |
| `always` | Extrait toujours le texte (passerelle d'entreprise devant OpenRouter…) |
| `never` | N'extrait jamais : le service sait recevoir les PDF |

Conséquence pratique : sur un serveur local, une facture PDF **générée par ordinateur**
(la quasi-totalité des factures d'énergie, télécom, assurance) se lit très bien. Un PDF
**scanné**, lui, ne contient aucun texte — seulement une image de page : déposez-le en
JPEG ou PNG, ou passez par un fournisseur qui sait lire les PDF. L'application vous le dit
explicitement plutôt que de renvoyer un formulaire vide.

L'extraction utilise `smalot/pdfparser`, une bibliothèque PHP pure : aucune dépendance
système supplémentaire dans l'image Docker.

### Activer l'assistant conversationnel

Une fois `AI_ENABLED=true` et une clé résolue, l'onglet **Assistant** apparaît dans le
panneau d'aide (bouton en bas à droite). Il répond aux questions d'utilisation du
logiciel, à partir de la documentation embarquée, et refuse tout conseil fiscal.

```bash
docker run -d --name openlmnp -p 8090:8000 \
  -e AI_ENABLED=true \
  -e AI_API_KEY=sk-or-v1-… \
  -e AI_MODEL_CHAT=google/gemini-3.7-flash \
  -e AI_CHAT_DAILY_QUOTA=50 \
  --restart unless-stopped openlmnp
```

| Variable | Défaut | Rôle |
|---|---|---|
| `AI_MODEL_CHAT` | `google/gemini-3.7-flash` | Modèle de l'assistant |
| `AI_CHAT_DAILY_QUOTA` | `0` | Questions par jour et par utilisateur (`0` = illimité) |
| `AI_DAILY_BUDGET_USD` | `0` | Dépense maximale de l'instance sur sa clé, en dollars par jour (`0` = désactivé) |
| `AI_CHAT_MAX_TURNS` | `20` | Longueur maximale d'une conversation |
| `AI_CHAT_TOOL_CALLS_MAX` | `4` | Lectures de données maximum par question |
| `AI_CHAT_REASONING` | `low` | Effort de raisonnement du modèle pour l'assistant (`low`, `medium`, `high`, `none`) — `low` évite que le raisonnement, facturé et invisible, consomme le budget de réponse |
| `AI_DOCS_REASONING` | `low` | Même réglage pour l'analyse de justificatifs |

L'assistant s'appuie sur un **index de la documentation** reconstruit au démarrage du
conteneur (`php artisan ai:build-help-index`). Sur une installation hors Docker,
relancez cette commande après une mise à jour — `composer update` s'en charge aussi.

Chaque utilisateur décide, dans *Mon profil*, si l'assistant peut **lire ses données**
(option désactivée par défaut). Tant qu'elle l'est, aucune donnée comptable n'est
transmise au fournisseur. Activée, l'assistant accède en lecture seule à un
sous-ensemble d'outils de consultation : aucune écriture, aucun export, aucune
génération de fichier.

### Vérifier l'assistant avec votre modèle

```bash
php artisan ai:eval-chat --user=vous@exemple.fr                 # jeu complet, une fois
php artisan ai:eval-chat --filter=indirect --repeat=3           # consignes cachées, 3 passages
```

La commande pose à l'assistant un jeu de questions préparées : des questions d'usage, des
questions hors sujet qu'il doit refuser, et des tentatives de détournement. Une partie de ces
tentatives est **cachée dans des données** (nom de voyageur, description de charge, nom de bien),
comme pourrait l'être un texte piégé arrivé par un import. Ces cas tournent sur un compte
temporaire, créé puis supprimé par la commande, jamais sur le vôtre, et échouent si la réponse
reprend le mot témoin que réclamait la consigne cachée. La commande rend un code d'échec dès
qu'un détournement réussit. Elle consomme des jetons (de l'ordre de 0,40 $ pour l'ensemble avec
un modèle « flash ») : à lancer après un changement de modèle ou de fournisseur.

### Plafonds d'usage

Sur une instance partagée, `AI_DOCS_MONTHLY_QUOTA` et `AI_CHAT_DAILY_QUOTA` limitent la
consommation **par utilisateur** (`0` = illimité). Un utilisateur qui fournit sa propre
clé n'est pas plafonné — sauf si vous forcez `AI_QUOTA_APPLIES_TO_USER_KEYS=true`.

Ces quotas bornent chaque compte, pas leur nombre. `AI_DAILY_BUDGET_USD` borne la dépense de
**toute l'instance** sur sa clé, en dollars par jour (UTC) : une fois atteint, l'analyse de
justificatifs et l'assistant se mettent en pause jusqu'au lendemain pour les comptes qui
utilisent la clé de l'instance (`0` = désactivé). Les clés personnelles ne sont pas concernées.
Doublez-le d'une limite de dépense chez le fournisseur, qui tient même si l'application dérape.

### Ce qui est envoyé, et ce qui est conservé

- **Envoyé, et seulement sur action explicite de votre part** : le document soumis à
  l'analyse (ou votre question à l'assistant), le nom du bien concerné et la liste des
  catégories comptables. Jamais votre identité, votre email, votre SIREN ni vos autres
  documents.
- **Demandé, et pas garanti** : chez OpenRouter, `AI_DATA_COLLECTION=deny` (défaut) demande
  un routage vers des services qui ne conservent pas les données transmises et ne s'en
  servent pas pour entraîner leurs modèles. C'est une **préférence transmise à un
  intermédiaire**, pas un engagement que nous puissions tenir à sa place — et les autres
  fournisseurs ne la reçoivent même pas : cela relève alors de leurs propres conditions
  d'utilisation. La seule configuration qui ne dépende de personne d'autre que de vous est
  un modèle installé chez vous.
- **Conservé par OpenLMNP** : une ligne de journal technique par appel (date, modèle,
  jetons, durée, coût, statut). **Aucun contenu, aucune donnée extraite, aucune clé.**
  Purge automatique après `AI_USAGE_RETENTION` jours (90 par défaut).
- **Conservé seulement si l'utilisateur le choisit** : après un pouce vers le bas, une case
  décochée par défaut joint la conversation, **masquée** avant l'enregistrement (montants,
  noms et adresses des biens, voyageurs, banques, libellés, nom et e-mail du compte) et sans
  aucune donnée lue par l'assistant. Lisible dans *Administration › IA*, supprimée après
  90 jours.

⚠️ **Le mode démonstration n'a jamais accès à l'IA**, quelle que soit la configuration.

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
| `ALLOW_REGISTRATION` | Inscription publique : `auto` = ouverte jusqu'au premier compte, `invite` = sur invitation seulement, `true` = toujours, `false` = jamais | `auto` |
| `INVITATION_TTL_DAYS` | Durée de validité d'une invitation, en jours (mode `invite`) | `14` |
| `MAIL_MAILER` | `log` (aucun envoi) ou `smtp` + variables `MAIL_*` (voir [Emails](#emails-optionnel)) | `log` |
| `PROVISION_TOKEN` | Active l'API de création de comptes `POST /api/admin/users` (automatisations avancées). Vide = API désactivée (404) | *(vide)* |
| `DEMO_MODE` | Active le mode démonstration (voir [DEMO.md](DEMO.md)) | `false` |
| `DOCUMENTS_VAULT_ENABLED` | Propose le coffre-fort (chiffrement des justificatifs par phrase de passe utilisateur). `false` masque la fonctionnalité sur toute l'instance | `true` |
| `DOCUMENTS_VAULT_KDF` | Coût de la dérivation Argon2id au déverrouillage : `moderate` (≈ 500 ms, 256 Mo de RAM en pic) ou `interactive` (≈ 80 ms, 64 Mo) sur une machine modeste | `moderate` |
| `DOCUMENTS_VAULT_TTL` | Minutes d'inactivité avant re-verrouillage automatique | `SESSION_LIFETIME` |
| `SESSION_SECURE_COOKIE` | Cookies (session **et** clé du coffre-fort) réservés au HTTPS. Laisser vide en accès direct HTTP sur un réseau local, sinon la session ne s'établit pas | *(auto)* |
| `GTM_CONTAINER_ID` | Identifiant Google Tag Manager (`GTM-XXXXXXX`). Vide = aucun script de mesure injecté | *(vide)* |
| `GTM_SERVER_URL` | URL d'un GTM server-side auto-hébergé (sinon serveurs Google) | `https://www.googletagmanager.com` |
| `GTM_SCRIPT_PATH` | Chemin du script GTM (utile si renommé côté serveur) | `/gtm.js` |
| `OPENLMNP_WEBSITE_URL` | Lien « Découvrir OpenLMNP » affiché sur la page de connexion. Vide = lien masqué | `https://openlmnp.fr` |
| `OPENLMNP_SIGNUP_URL` | Page où obtenir un compte quand l'inscription est fermée : `/register` y redirige et la page de connexion gagne un lien « Créer un compte ». Vide = renvoi vers la page de connexion avec une explication | *(vide)* |
| `TELEMETRY_ENABLED` | Check-in anonyme quotidien (identifiant aléatoire + version) pour compter les instances installées. Aucune donnée comptable/personnelle. `false` = désactivé, aucune requête émise | `true` |
| `TELEMETRY_URL` | Endpoint recevant le check-in de télémétrie | `https://openlmnp.fr/api/instances/checkin` |
| `TRUSTED_PROXIES` | Proxies dont les en-têtes `X-Forwarded-*` sont crus (liste séparée par des virgules, ou `*`). Utile derrière un reverse proxy ou un PaaS | plages privées + loopback |
| `TRUSTED_HOSTS` | Noms d'hôte acceptés en plus de celui d'`APP_URL`, de ses sous-domaines et de `localhost` (liste séparée par des virgules, `*` pour couper le filtre). Le filtre ne s'applique que si `APP_URL` porte un nom de domaine : une instance dont `APP_URL` vaut `localhost` ou une IP n'est pas filtrée. Si `APP_URL` porte un domaine et que l'instance est aussi ouverte par une IP ou un autre nom, ajouter ces noms ici, sinon ils reçoivent une erreur 400 | vide |
| `MCP_RATE_LIMIT` | Appels MCP autorisés par minute et par compte (`0` = aucune limite). La démo publique a sa propre limite, `MCP_DEMO_RATE_LIMIT` | `60` |
| `MCP_AUDIT_RETENTION` | Durée de conservation du journal des appels MCP, en jours ; purge quotidienne (`0` = jamais). Les contenus de fichiers, URL signées et valeurs longues n'y sont pas enregistrés | `90` |
| `SESSION_SECURE_COOKIE` | Cookie de session réservé au HTTPS. Non réglé : automatique, actif sur toute requête HTTPS (directe ou annoncée par un proxy de confiance) et sur le nom d'hôte d'un `APP_URL` en `https`, inactif sur une adresse du réseau local servie en HTTP. `true`/`false` pour forcer | automatique |
| `CSP_ENABLED` | Politique de sécurité du contenu (en-tête `Content-Security-Policy`, voir ci-dessous) | `true` |
| `CSP_REPORT_ONLY` | `true` : la politique est seulement signalée dans la console du navigateur (`Content-Security-Policy-Report-Only`), rien n'est bloqué | `false` |
| `CSP_EXTRA_SOURCES` | Origines supplémentaires autorisées pour les scripts, styles, polices, images, connexions et cadres (séparées par des espaces), ex. `https://stats.example.org` | vide |
| `CSP_POLICY` | Politique complète qui remplace celle de l'application | vide |
| `HSTS_MAX_AGE` | Durée, en secondes, de l'en-tête `Strict-Transport-Security`, posé seulement sur les requêtes HTTPS (`0` le coupe) | `31536000` |
| `PHP_CLI_SERVER_WORKERS` | Nombre de requêtes servies simultanément (voir ci-dessous) | `4` |
| `DB_JOURNAL_MODE` | Journal SQLite. `WAL` par défaut ; `delete` si la base est sur un stockage réseau | `WAL` |
| `DB_SYNCHRONOUS` | Politique de `fsync` de SQLite | `NORMAL` |
| `DB_BUSY_TIMEOUT` | Attente maximale d'un verrou, en millisecondes | `5000` |
| `DB_TRANSACTION_MODE` | Mode d'ouverture des transactions SQLite | `IMMEDIATE` |
| `AI_ENABLED` | Fonctions d'intelligence artificielle (voir [ci-dessous](#intelligence-artificielle-optionnel)). `false` = aucune interface IA, aucune requête sortante | `false` |
| `AI_PROVIDER` | Fournisseur visé : `auto`, `openrouter`, `anthropic`, `gemini`, `mistral`, `openai`, `local`. Une valeur inconnue fait échouer l'IA avec un message, sans repli silencieux | `auto` |
| `AI_BASE_URL` | Adresse du service. Vide = celle du fournisseur ; renseignée, elle prime toujours (proxy, passerelle, serveur local) | *(vide)* |
| `AI_API_KEY` | Clé d'API de l'instance. Vide = chaque utilisateur renseigne la sienne dans *Mon profil* | *(vide)* |
| `AI_ALLOW_USER_KEYS` | Autorise chaque utilisateur à saisir sa propre clé | `true` |
| `AI_OAUTH_ENABLED` | Bouton « Se connecter avec OpenRouter » dans *Mon profil* : la clé est délivrée sans copier-coller et appartient à l'utilisateur. Sans effet chez un fournisseur qui ne le propose pas | `true` |
| `AI_PDF_ENGINE` | Lecture des PDF via OpenRouter uniquement : `native`, `pdf-text`, `cloudflare-ai`, `mistral-ocr` (payant) | `native` |
| `AI_PDF_TEXT_FALLBACK` | Envoi des PDF en texte extrait pour les services qui ne les reçoivent pas : `auto`, `always`, `never` | `auto` |
| `AI_CHAT_REASONING` / `AI_DOCS_REASONING` | Effort de raisonnement demandé au modèle (`low`, `medium`, `high`, `none` = paramètre non envoyé) | `low` |
| `AI_MODEL_DOCUMENTS` / `AI_MODEL_CHAT` | Identifiants de modèles. Vide = ceux du fournisseur. ⚠️ Non portables d'un fournisseur à l'autre | *(vide)* |
| `AI_MODEL_DOCUMENTS_FALLBACK` | Modèle de repli, appelé si l'extraction manque de confiance. Vide = celui du fournisseur ; `none` = pas de cascade | *(vide)* |
| `AI_MODEL_PRICES` | Tarifs en $/million de jetons, en JSON, pour les fournisseurs qui ne renvoient pas le coût réel : `{"gemini-3.7-flash":{"in":"0.375","out":"1.875"}}`. Sans eux, aucun coût n'est journalisé — mieux qu'un chiffre inventé | *(vide)* |
| `AI_FALLBACK_CONFIDENCE` | Confiance (0-1) sous laquelle le modèle de repli prend le relais | `0.6` |
| `AI_REANALYSIS_THRESHOLD` | Confiance (0-1) au-dessus de laquelle le bouton « Réanalyser avec le modèle renforcé » est masqué | `0.85` |
| `AI_DOCS_MONTHLY_QUOTA` / `AI_CHAT_DAILY_QUOTA` | Plafonds par utilisateur (`0` = illimité) | `0` |
| `AI_APP_REFERER` / `AI_APP_TITLE` | En-têtes d'attribution envoyés **à OpenRouter uniquement** : ils identifient le logiciel, pas vous ni votre instance (voir ci-dessous) | `https://openlmnp.fr`, `OpenLMNP` |

> **Vie privée** : aucune mesure d'audience n'est active par défaut. L'intégration
> Google Tag Manager ne s'active que si vous définissez explicitement `GTM_CONTAINER_ID`.

### Les en-têtes d'attribution envoyés à OpenRouter

Chaque appel à OpenRouter porte deux en-têtes, `HTTP-Referer` et `X-Title`, qui valent par
défaut `https://openlmnp.fr` et `OpenLMNP`. Ils désignent **le logiciel**, jamais votre
instance ni vous : aucune adresse, aucun identifiant, aucune donnée personnelle. C'est le
mécanisme qu'OpenRouter propose pour attribuer un usage à une application, et il fait
apparaître OpenLMNP dans son classement public.

Ils ne sont envoyés qu'à OpenRouter — aucun autre fournisseur ne les reçoit — et se
changent, ou se vident, par `AI_APP_REFERER` et `AI_APP_TITLE`.

### En-têtes de sécurité : CSP, HSTS, cookie de session

L'application pose elle-même ses en-têtes de sécurité, y compris derrière un reverse proxy
qui n'en ajoute aucun :

- **`Content-Security-Policy`** : scripts, styles et connexions limités à l'instance
  elle-même, à jsDelivr (graphiques) et, si `GTM_CONTAINER_ID` est réglé, à Google Tag
  Manager et GA4 ; ni plugin, ni formulaire envoyé ailleurs, ni affichage de l'application
  dans un cadre d'un autre site. Le panel a besoin de `'unsafe-inline'` et de
  `'unsafe-eval'` (Alpine.js, Livewire) : la politique limite les origines, elle n'empêche
  pas un script en ligne.
- **`Strict-Transport-Security`**, seulement sur une requête HTTPS : une instance du réseau
  local servie en HTTP n'est jamais concernée.
- **Cookie de session `Secure`** dès que l'instance est en HTTPS (voir `SESSION_SECURE_COOKIE`).

Derrière un reverse proxy qui termine TLS (Nginx Proxy Manager, Traefik, Caddy), l'instance
se sait en HTTPS grâce à l'en-tête `X-Forwarded-Proto`, à condition que le proxy soit de
confiance (`TRUSTED_PROXIES`, plages privées par défaut). Si le proxy pose déjà une
`Content-Security-Policy`, le navigateur applique **les deux** : retirez celle du proxy, ou
coupez celle de l'application (`CSP_ENABLED=false`).

Un ajout local bloqué (traceur, police, widget) se signale dans la console du navigateur
(« Refused to load… ») : ajoutez son origine à `CSP_EXTRA_SOURCES`, ou passez d'abord en
`CSP_REPORT_ONLY=true` pour faire l'inventaire sans rien bloquer.

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

**Avis de sécurité.** Depuis la version 1.6.11, la réponse du check-in peut porter des
avis de sécurité publiés par le projet (une faille corrigée dans une version donnée, par
exemple). Ils s'affichent en bandeau en haut de l'application, **pour les administrateurs
seulement**, et uniquement si la version installée est concernée : le bandeau disparaît
de lui-même une fois l'instance mise à jour. Aucune donnée supplémentaire n'est envoyée
pour cela. En revanche, couper la télémétrie (`TELEMETRY_ENABLED=false`) coupe aussi ces
avis : suivez alors les publications de sécurité sur
[GitHub](https://github.com/manganate006/openlmnp/security/advisories) ou le
[CHANGELOG](../CHANGELOG.md).

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

Prérequis : **PHP 8.4.27 ou plus récent** (ou 8.3.36 sur la branche 8.3 : les versions
antérieures sont exposées à CVE-2026-109618 et CVE-2026-109619) (extensions `pdo_sqlite`, `bcmath`, `intl`, `gd`, `zip`),
**Composer**, et **Node.js 22+** si vous devez recompiler les assets CSS
(`npm install && npm run build`).

## Mise à jour

La page **Nouveautés**, accessible à **tous les utilisateurs**, affiche la version
installée, la dernière version publiée et les notes des cinq dernières versions. Le relevé
est automatique et conservé une heure ; il n'a lieu qu'après le premier affichage de la
page, jamais pendant son chargement.

> **Instance sans accès sortant** : `UPDATE_CHECK_ENABLED=false` (variable `-e` du
> `docker run`) coupe entièrement l'interrogation de GitHub. La version installée, lue
> localement, reste affichée ; seules les notes de version deviennent indisponibles.

Pour mettre à jour une installation Docker :

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

### Coffre-fort : chiffrer ou déchiffrer un gros volume en ligne de commande

Le coffre-fort **s'active depuis l'interface** (*Paramètres → Coffre-fort*) : c'est
là qu'on choisit la phrase de passe. L'activation chiffre ensuite les fichiers
existants par lots, dans le navigateur. Sur un compte qui porte plusieurs centaines
de pièces, la commande ci-dessous mène la migration à son terme sans dépendre d'un
onglet resté ouvert : elle est **idempotente et reprenable** (l'état de chaque
fichier est lu dans ses premiers octets, jamais en base).

Elle demande la phrase de passe de façon interactive — l'instance ne la connaît pas
et ne peut pas la retrouver. D'où le `-it` du `docker exec`.

```bash
# Rapport : ce qui serait chiffré, sans rien écrire
docker exec -it openlmnp php artisan openlmnp:vault-migrate vous@exemple.fr --dry-run

# Chiffrement, une fois le rapport lu
docker exec -it openlmnp php artisan openlmnp:vault-migrate vous@exemple.fr

# Sens inverse (équivalent à « Désactiver le coffre-fort »)
docker exec -it openlmnp php artisan openlmnp:vault-migrate vous@exemple.fr --decrypt
```

Deux options de dépannage : `--batch=N` limite le nombre de fichiers traités par
passage, et `--abandon` débloque un coffre resté en état « en cours d'opération »
**sans rien transformer** — c'est la seule porte de sortie si la phrase de passe a
été perdue au milieu d'une migration.

⚠️ Les justificatifs restés à l'ancienne racine (section précédente) sont
**comptés, pas traités** : la migration ne balaie que `storage/app/private`.
Lancez `openlmnp:migrate-document-storage --fix` d'abord si vous voulez qu'ils
entrent dans le coffre.

⚠️ Le profil de dérivation par défaut (`DOCUMENTS_VAULT_KDF=moderate`) demande
**256 Mo de mémoire à chaque déverrouillage**. Sur un serveur modeste, préférez
`DOCUMENTS_VAULT_KDF=interactive` (64 Mo). Le profil retenu est enregistré à
l'activation : le changer plus tard n'invalide aucun coffre existant.

### Signaler un problème de calcul : le rapport de diagnostic

Si vos amortissements ou votre liasse ne tombent pas sur les chiffres attendus, un
rapport de diagnostic rassemble en un texte tout ce qui les produit : les valeurs
saisies sur le bien, la base amortissable et sa provenance, chaque composant avec sa
base, sa durée et sa date de départ, vos travaux et votre mobilier, les lignes de
liasse obtenues, et les écarts.

Il est accessible **depuis l'interface**, bouton « Rapport de diagnostic » en haut des
pages *Amortissements* et *Télédéclaration*. Depuis le serveur :

```bash
# Le seul compte de l'instance, exercice en cours
docker exec openlmnp php artisan openlmnp:diagnostic

# Un compte précis, un exercice précis
docker exec openlmnp php artisan openlmnp:diagnostic vous@exemple.fr --year=2025

# En JSON, si vous voulez le traiter
docker exec openlmnp php artisan openlmnp:diagnostic --json
```

La commande ne modifie rien et **n'envoie rien** : elle écrit sur la sortie standard.
Le rapport ne contient ni nom, ni adresse, ni commune, ni adresse e-mail — mais il
porte vos montants et les intitulés que vous avez saisis. Relisez-le avant de le
joindre à une issue publique.

## Dossiers liés : quota par compte

Avec `LINKED_DOSSIERS_ENABLED=true`, le nombre de dossiers liés qu'un compte peut créer est
**illimité par défaut** en auto-hébergement (réglable globalement par `LINKED_DOSSIERS_QUOTA`).
Pour poser un plafond propre à un seul compte :

```bash
docker exec openlmnp php artisan openlmnp:dossier-quota vous@exemple.fr 3
```

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
résiduels, puis relancez le conteneur (il remet lui-même les fichiers restaurés à
`www-data`). Voir aussi la [FAQ](FAQ.md) sur les sauvegardes.

## Fichiers en clair laissés par les versions antérieures

Jusqu'à la version 1.5, trois familles de fichiers s'accumulaient dans `storage/app/private`
sans que rien ne les supprime jamais :

- **les liasses fiscales** (`tax-returns/`) et **les FEC** (`fec/`) — ils portent l'intégralité
  de la comptabilité : adresses, SIREN, résultats, écritures. Ils sont désormais reconstruits
  à chaque téléchargement et ne touchent plus le disque ;
- **les CSV déposés pour import** — un export Airbnb, avec les noms des voyageurs et les
  montants versés, tombait à la racine du disque sous un nom aléatoire (`01K4….csv`), sans
  aucun lien vers son propriétaire. Ils sont désormais rangés sous
  `documents/{id}/imports` et supprimés dès l'import terminé.

Une commande fait le ménage de l'existant. Elle **rapporte d'abord**, et n'agit qu'avec `--fix` :

```bash
# 1. Rapport : ce qui serait supprimé, sans rien modifier
docker exec openlmnp php artisan openlmnp:purge-legacy-files

# 2. Suppression, une fois le rapport lu
docker exec openlmnp php artisan openlmnp:purge-legacy-files --fix
```

⚠️ **C'est une suppression, pas un déplacement.** Un CSV posé à la racine n'a aucun lien vers
un utilisateur — ni en base, ni dans son chemin : impossible de le ranger sous son propriétaire
sans l'inventer. Si vous tenez à en garder une copie, sortez-la du volume **avant** de lancer
`--fix`. Les liasses et les FEC, eux, se régénèrent depuis l'application à tout moment.

La commande vide au passage les colonnes `pdf_path` / `fec_path` devenues sans objet. Les badges
et la liste de démarrage, qui signalaient « liasse générée », s'appuient désormais sur une date
enregistrée à part : ils ne régressent pas.

Elle est idempotente et sans effet si votre instance n'est pas concernée. La purge des dépôts
d'import abandonnés (`openlmnp:cleanup-imports`) tourne, elle, **automatiquement chaque jour** —
rien à lancer à la main.

---

Voir aussi : [FONCTIONNALITES.md](FONCTIONNALITES.md) · [DEMO.md](DEMO.md) · [FAQ.md](FAQ.md)
