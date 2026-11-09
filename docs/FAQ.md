# Foire aux questions (FAQ)

## Sommaire

- [Le logiciel](#le-logiciel)
- [Le régime fiscal LMNP](#le-régime-fiscal-lmnp)
- [Vos données](#vos-données)
- [Utilisation](#utilisation)
- [L'assistant intégré](#lassistant-intégré)

## Comment importer mes revenus Airbnb ou Booking ?

Deux chemins, et **un seul à la fois** :

- le **CSV** de la plateforme (*Import Airbnb*), qui crée une recette par réservation ;
- le **rapport annuel de revenus** en PDF (*Importer un justificatif*), qui crée une recette par
  mois loué.

Les deux portent les mêmes euros sous deux mailles différentes : les importer tous les deux
compte votre année en double. Comme les montants ne coïncident jamais entre une réservation et un
total mensuel, la détection de doublons ne peut pas le voir toute seule — l'application vous
prévient si l'exercice porte déjà des recettes au moment où vous déposez un relevé annuel.

## Le logiciel

### OpenLMNP est-il gratuit ?

Oui. OpenLMNP est un **logiciel libre**, publié sous licence **AGPLv3**. Vous pouvez
l'utiliser, le modifier et le redistribuer gratuitement, à condition de partager vos
modifications sous la même licence. Il n'y a ni abonnement, ni fonctionnalité payante :
tout est inclus.

### À qui s'adresse-t-il ?

Aux particuliers qui louent un ou plusieurs logements meublés (LMNP) et souhaitent tenir
leur comptabilité au **régime réel** eux-mêmes, sans être comptables. L'interface est en
français et volontairement pédagogique.

### Remplace-t-il un expert-comptable ?

Pour les situations courantes, OpenLMNP vous permet d'être autonome. Pour les cas complexes
(cessions, situations patrimoniales particulières, montages spécifiques), un expert-comptable
reste recommandé. OpenLMNP est un **outil d'aide** à la comptabilité, pas un conseil fiscal
personnalisé.

### Comment l'installer ?

Le plus simple est **Docker**. Voir le guide détaillé : [INSTALLATION.md](INSTALLATION.md).
Un script d'installation en conteneur LXC Proxmox est également fourni.

### Puis-je l'essayer avant d'installer ?

Oui, si l'instance a le **mode démo** activé : un bouton « Découvrir la démo » sur la page
de connexion ouvre un bac à sable pré-rempli de données fictives. Voir [DEMO.md](DEMO.md).

## Le régime fiscal LMNP

### C'est quoi le LMNP au régime réel ?

Le statut **LMNP** (Loueur Meublé Non Professionnel) s'applique aux particuliers qui louent
un logement meublé. Au **régime réel**, vous déduisez de vos recettes vos **charges réelles**
(taxe foncière, assurance, intérêts d'emprunt, frais de gestion…) **et l'amortissement**
du bien et du mobilier. Cet amortissement réduit fortement — souvent à zéro — le revenu
imposable, sans être une dépense de trésorerie.

### Régime réel ou micro-BIC : lequel choisir ?

- Le **micro-BIC** applique un abattement forfaitaire sur vos recettes (aucune comptabilité).
- Le **régime réel** déduit vos charges et amortissements réels (comptabilité à tenir).

Le régime réel est généralement plus avantageux dès que vous avez des charges significatives,
un emprunt, ou un bien de valeur à amortir. OpenLMNP intègre un **simulateur** qui compare
les deux et rend un verdict chiffré pour votre situation. Pour les règles et seuils précis
(y compris la réforme 2026), voir [fiscalite-lmnp-airbnb.md](fiscalite-lmnp-airbnb.md).

### OpenLMNP produit-il ma déclaration ?

Il prépare les éléments de la **liasse fiscale** au réel : formulaires **2031** et
**2033-A/B/C/D**, report sur la **2042-C-PRO**, avec une télédéclaration interactive
(boutons « Copier ») et un export **PDF**. Il génère aussi le **FEC** conforme exigé
en cas de contrôle. Le dépôt final se fait dans votre espace impots.gouv.fr.

## Vos données

### Mes données restent-elles chez moi ?

Oui. OpenLMNP est **auto-hébergé** : vous l'installez sur votre machine ou votre serveur,
et vos données comptables restent stockées **localement** (base SQLite + fichiers). Rien
n'est transmis à un tiers ou à un service externe.

Une seule exception, que vous activez vous-même : les **fonctions d'intelligence
artificielle** (analyse de justificatifs, assistant). Elles sont désactivées par défaut et,
une fois activées, n'envoient un document que lorsque vous le déposez explicitement — voir
[« L'analyse de justificatifs envoie-t-elle mes documents ailleurs ? »](#lanalyse-de-justificatifs-envoie-t-elle-mes-documents-ailleurs).

### Comment sauvegarder mes données ?

Toutes vos données tiennent dans deux répertoires : la base (`database/`) et les fichiers
(`storage/`). Une sauvegarde consiste à archiver ces dossiers — en prenant d'abord un
**instantané cohérent** de la base, qui peut être en cours d'écriture :

```bash
sqlite3 /opt/openlmnp-data/database/database.sqlite \
        ".backup '/opt/openlmnp-data/database-snapshot.sqlite'"
tar czf openlmnp-backup-$(date +%F).tar.gz \
    -C /opt/openlmnp-data database-snapshot.sqlite storage
rm /opt/openlmnp-data/database-snapshot.sqlite
```

Copier `database.sqlite` tel quel pendant que l'instance tourne peut donner une base
**amputée de ses dernières écritures** : depuis la version 1.5.0 elles transitent par un
fichier `database.sqlite-wal` voisin. Arrêter le conteneur avant de copier fonctionne aussi.

En montant ces répertoires comme volumes Docker, vos données survivent aux mises à jour.
Voir la section sauvegarde de [INSTALLATION.md](INSTALLATION.md).

### Puis-je exporter mes données ?

Oui. Les recettes, charges et la télédéclaration sont exportables en **CSV**, la liasse en
**PDF**, et la comptabilité complète en **FEC**. Vos justificatifs peuvent aussi être
exportés groupés par exercice.

### Mes données sont-elles isolées si plusieurs personnes utilisent l'instance ?

Oui. OpenLMNP est **multi-utilisateurs** : chaque utilisateur ne voit et ne modifie que
ses propres biens et écritures, grâce à un cloisonnement appliqué au niveau du modèle de données.

### L'analyse de justificatifs envoie-t-elle mes documents ailleurs ?

Seulement si vous activez la fonction, et seulement pour les documents que vous déposez.

Par défaut, l'IA est **désactivée** : aucune interface n'apparaît, aucune requête ne sort.
Si vous l'activez avec une clé, chaque analyse envoie **le document déposé et le nom du
bien concerné** — jamais votre identité, votre adresse électronique, votre SIREN ni vos
autres données comptables. Chez OpenRouter, OpenLMNP demande explicitement de ne rien
conserver et de ne pas s'entraîner sur vos données ; ailleurs, cela relève des conditions
d'API de l'éditeur, que nous ne pouvons ni transmettre ni garantir à sa place.

Si cela ne vous convient pas, faites pointer `AI_BASE_URL` vers un modèle installé chez
vous (Ollama, LM Studio…) : rien ne quitte alors votre réseau. Voir
[INSTALLATION.md](INSTALLATION.md#option-c--un-modèle-local-rien-ne-sort-de-chez-vous).

### Dois-je créer une clé à la main pour activer l'IA ?

Non. Dans *Mon profil*, le bouton **« Se connecter avec OpenRouter »** vous redirige vers
votre compte, vous demande d'autoriser l'instance, et revient avec une clé **qui vous
appartient**. Rien à copier-coller, et pas besoin d'avoir un compte d'avance : OpenRouter
propose son écran d'inscription à qui n'en a pas.

La clé reste chiffrée sur votre instance et n'est jamais réaffichée. Le champ de saisie
manuelle reste disponible juste en dessous : c'est lui qu'il faut pour un serveur installé
chez vous, ou pour une clé fournie par un tiers.

### J'ai une clé, mais l'IA refuse de fonctionner

Le cas le plus fréquent est un compte **sans crédit** : un compte tout neuf en a rarement.
Les analyses échouent alors avec un message explicite et un lien de recharge. C'est le
compte du fournisseur qui est facturé à la consommation — de l'ordre de 0,003 $ par
facture avec le modèle par défaut — pas OpenLMNP.

Autre cas : un identifiant de modèle recopié d'un fournisseur à l'autre. Ils ne sont pas
portables (`google/gemini-3.7-flash` chez OpenRouter, `gemini-3.7-flash` chez Google) :
laissez `AI_MODEL_*` vide pour utiliser celui du fournisseur configuré.

Dans tous les cas, OpenLMNP ne conserve **aucun contenu de document** : le journal
technique n'enregistre que la date, le modèle, le nombre de jetons, la durée et le coût.

### L'IA peut-elle se tromper sur mes montants ?

Oui, et c'est pour cela qu'elle ne décide de rien.

Aucune écriture n'est créée à partir de la proposition d'un modèle : vous passez toujours
par un écran de relecture, où chaque champ affiche un indicateur de confiance et l'extrait
du document qui a servi à le remplir. Vous corrigez ce qui doit l'être, et ce sont **vos**
valeurs qui sont enregistrées.

Quelques garde-fous supplémentaires : les montants sont convertis en centimes par le code
(jamais par le modèle), les taux de TVA sont ramenés aux quatre taux français, une
catégorie inconnue devient « Divers » avec un avertissement, et une écriture du même
montant à trois jours près est signalée comme doublon possible.

### Combien coûte l'analyse d'un justificatif ?

De l'ordre de **0,003 $ par facture** avec le modèle par défaut (mesuré sur une facture
d'énergie d'une page). Un relevé bancaire de plusieurs pages coûte davantage.

Vous payez directement votre fournisseur, à la consommation — OpenLMNP ne prend aucune
marge. Sur une instance partagée, `AI_DOCS_MONTHLY_QUOTA` plafonne le nombre de documents
par utilisateur et par mois. La page *Administration → Intelligence artificielle* affiche
le coût réel, document par document.

## Utilisation

### Puis-je gérer plusieurs biens ?

Oui, sans limite. Chaque bien a ses propres composants, travaux, mobilier, recettes, charges
et emprunts, et le tableau de bord offre une vue consolidée.

### Je quitte mon expert-comptable : comment reprendre ma comptabilité sans tout ressaisir ?

Avec l'assistant **« Reprendre un dossier existant »** (écran de premier lancement, ou page
des exercices fiscaux). Il ne demande que votre **dernière liasse 2033** : chaque montant à
saisir porte le numéro de la case Cerfa où le lire, votre plan d'amortissement peut être
recopié ligne à ligne depuis le tableau 2033-C (les chiffres restent exactement ceux de votre
comptable), et vos reports — amortissements différés comme déficits par millésime — sont repris
comme soldes d'ouverture. Un écran de contrôle compare ensuite votre liasse à ce que
l'application reconstitue, ligne par ligne.

### Le contrôle de reprise affiche un écart : est-ce grave ?

Un écart n'est pas un échec : l'écran en nomme les causes probables **par ordre de
fréquence** — date de mise en location, part du terrain, frais d'acquisition passés en
charges par le cabinet, composant manquant. La plus courante (les frais d'acquisition) se
corrige d'un bouton, qui rejoue le contrôle sur place. Tant que le parcours n'est pas terminé,
rien n'est écrit dans vos exercices.

### Comment importer mes revenus Airbnb ou Booking ?

Depuis l'écran des recettes, importez le **relevé CSV** de la plateforme. OpenLMNP reconnaît
les formats français et anglais, gère la virgule décimale européenne et **détecte les doublons**
pour éviter les réimports.

### Comment mettre à jour le logiciel ?

OpenLMNP affiche une **notification de mise à jour** dans l'interface lorsqu'une nouvelle
version est disponible. La procédure de mise à jour Docker est décrite dans
[INSTALLATION.md](INSTALLATION.md) ; vos données sont conservées.

### Comment contribuer ou signaler un bug ?

Ouvrez une **issue** sur le dépôt GitHub, ou proposez une **pull request**. Les modalités
(tests à lancer, conventions de code) sont détaillées dans [CONTRIBUTING.md](../CONTRIBUTING.md).

## L'assistant intégré

### L'assistant donne-t-il des conseils fiscaux ?

**Non.** L'assistant répond uniquement aux questions sur **l'utilisation du logiciel**. Toute
question de choix de régime, d'interprétation de la loi ou de déductibilité d'une dépense
particulière est refusée et renvoyée vers le **simulateur** intégré et un expert-comptable. Un
contrôle côté serveur remplace toute réponse qui glisserait malgré tout vers le conseil
personnalisé.

### Mes conversations sont-elles enregistrées ?

Non. Une conversation vit le temps de la page et disparaît au rechargement. Seuls des
**compteurs** sont journalisés — jetons consommés, coût, durée, outils appelés, pouce éventuel —
jamais le texte des questions ni celui des réponses.

### L'assistant peut-il voir mes données ?

Seulement si vous l'y autorisez, dans *Mon profil*. L'option est **désactivée par défaut** :
tant qu'elle l'est, aucune donnée de votre comptabilité n'est transmise au fournisseur d'IA.
Activée, elle donne à l'assistant un accès en **lecture seule** à vos biens, recettes, charges,
emprunts et exercices, et la réponse indique ce qui a été consulté. L'assistant ne peut jamais
créer, modifier ni supprimer quoi que ce soit.

### L'assistant peut-il se tromper ?

Oui, comme tout modèle de langage. Il est contraint de s'appuyer sur la documentation embarquée
et de dire qu'il ne sait pas plutôt que d'inventer, mais une réponse doit toujours être vérifiée
avant de servir de base à une décision. Les pouces 👍/👎 aident à corriger le tir.

### Faut-il payer pour l'utiliser en auto-hébergement ?

L'assistant est désactivé par défaut. Pour l'activer, il faut une clé auprès d'un fournisseur
d'IA (ou un serveur local compatible) : voir [INSTALLATION.md](INSTALLATION.md). Le logiciel,
lui, reste entièrement gratuit et fonctionne sans assistant — l'aide contextuelle et le guide
intégré restent disponibles.


---

Voir aussi : [INSTALLATION.md](INSTALLATION.md) · [FONCTIONNALITES.md](FONCTIONNALITES.md) · [DEMO.md](DEMO.md) · [fiscalite-lmnp-airbnb.md](fiscalite-lmnp-airbnb.md)
