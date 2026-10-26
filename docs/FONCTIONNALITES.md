# Fonctionnalités d'OpenLMNP

OpenLMNP couvre l'ensemble du cycle comptable et fiscal d'un loueur meublé au **régime réel** :
de la description du bien jusqu'à la production de la liasse fiscale. Toutes les fonctions
décrites ici sont incluses dans le logiciel libre, sans limitation.

## Sommaire

- [Amortissement par composant](#amortissement-par-composant)
- [Reprendre une comptabilité existante](#reprendre-une-comptabilité-existante)
- [Multi-biens](#multi-biens)
- [Travaux et mobilier](#travaux-et-mobilier)
- [Emprunts](#emprunts)
- [Recettes et import CSV Airbnb / Booking](#recettes-et-import-csv-airbnb--booking)
- [Charges et pièces justificatives](#charges-et-pièces-justificatives)
- [Coffre-fort des justificatifs](#coffre-fort-des-justificatifs-chiffrement-par-phrase-de-passe)
- [Estimation de la valeur vénale (données DVF)](#estimation-de-la-valeur-vénale-données-dvf)
- [Autocomplétion d'adresse (Base Adresse Nationale)](#autocomplétion-dadresse-base-adresse-nationale)
- [Repère de loyer du marché (Carte des loyers)](#repère-de-loyer-du-marché-carte-des-loyers)
- [Analyse de justificatifs par IA](#analyse-de-justificatifs-par-ia)
- [Simulateur micro-BIC vs régime réel](#simulateur-micro-bic-vs-régime-réel)
- [Projection pluriannuelle](#projection-pluriannuelle)
- [Liasse fiscale 2031 / 2033](#liasse-fiscale-2031--2033)
- [FEC conforme DGFiP](#fec-conforme-dgfip)
- [Écritures comptables automatiques](#écritures-comptables-automatiques)
- [API MCP pour assistants IA](#api-mcp-pour-assistants-ia)
- [Assistant conversationnel](#assistant-conversationnel)
- [Version et nouveautés](#version-et-nouveautés)

## Amortissement par composant

L'amortissement est le cœur de l'intérêt fiscal du régime réel. OpenLMNP décompose le bien
en **composants** (gros œuvre, toiture, installations électriques, étanchéité, agencements
intérieurs, plomberie/sanitaire…), chacun avec sa propre durée d'amortissement.

- Ventilation **standard pré-remplie** modifiable ligne par ligne, au curseur (en pourcentage)
  ou **en euros**, base amortissable par base amortissable — c'est ce second mode qui permet
  de reprendre à l'identique un plan d'amortissement déjà tenu par un comptable
- Calcul de la **base amortissable** : valeur du bâti hors terrain (le terrain n'est jamais amortissable)
- **Quote-part** appliquée automatiquement lorsque seule une partie du bien est louée
  (ratio surface louée / surface totale)
- **Prorata temporis** : la première et la dernière année sont calculées au nombre de jours
- **Amortissement différé** : l'amortissement ne peut pas créer de déficit ; l'excédent
  est reporté sans limite de durée et déduit sur les exercices suivants
- **Frais de notaire et d'agence** : quatre traitements au choix, à aligner sur ce qu'a fait
  votre comptable — amortis séparément sur leur propre durée, intégrés au coût du bien (ils
  rejoignent alors la base amortissable et se répartissent terrain / bâti), passés en charges
  l'année de l'acquisition, ou non repris
- **Ligne du 2033-C** paramétrable sur chaque composant, travail et meuble, pour reproduire la
  présentation d'un cabinet
- **Rapport de diagnostic** : un texte reprenant tout ce qui produit vos amortissements et
  votre liasse — valeurs saisies, base amortissable, composants, travaux, mobilier, passage
  au résultat (totaux par bien, plafonnement, déficits), lignes obtenues, écarts. À joindre à une demande d'aide. Aucune donnée nominative, aucun envoi
  automatique

## Reprendre une comptabilité existante

Vous quittez un expert-comptable, ou un autre logiciel ? L'assistant **« Reprendre un dossier
existant »** — accessible depuis l'écran de premier lancement (« J'ai déjà une comptabilité
LMNP ») et depuis la page des exercices fiscaux — reprend votre dossier **à partir de la seule
dernière liasse**, sans ressaisir les exercices passés.

Cinq étapes : votre situation, votre bien, vos amortissements, vos reports, puis un contrôle.
**Chaque montant demandé porte le numéro de la case Cerfa où le lire** — 2033-D case 870 pour
les amortissements réputés différés, 2033-A case 030 pour le cumul d'amortissements, case 028
pour les immobilisations brutes, 2033-D cases 980 à 984 pour les déficits reportables.

- **Deux méthodes pour les amortissements**, avantages et inconvénients affichés : *recopier
  les lignes du tableau 2033-C* (une dizaine de champs, mais les chiffres restent exactement
  ceux de votre comptable — dotation comprise, telle quelle, arrondis inclus) ou *répartir
  automatiquement la base* (un clic, mais un plan différent du sien)
- **Soldes d'ouverture par exercice** : amortissements différés, déficits reportables **par
  millésime** (imputés du plus ancien au plus récent, périmés à dix ans), cumul déclaré par le
  cabinet. Créer un exercice sans son prédécesseur n'est plus refusé quand ces soldes sont
  saisis, et l'exercice porte un badge « Reprise »
- **L'étape de contrôle est la fonctionnalité** : elle met face à face ce que dit votre liasse
  et ce que l'application reconstitue, ligne par ligne, et nomme les causes probables d'un
  écart par ordre de fréquence (date de mise en location, part du terrain, frais d'acquisition
  passés en charges, composant manquant…). Quand l'écart correspond aux frais d'acquisition,
  un bouton les bascule en charges et rejoue le contrôle sur place
- **Rien n'est écrit avant la fin du parcours** : un assistant abandonné en route ne laisse
  aucun exercice derrière lui
- Le cumul d'amortissements d'ouverture est une donnée de **contrôle** : il sert à comparer,
  jamais à calculer — l'application ne compte pas deux fois le même amortissement

Sur un dossier déjà tenu dans OpenLMNP, `php artisan openlmnp:repair-deficits` reconstitue le
suivi des déficits (mode rapport par défaut).

## Inscription sur invitation

Par défaut, une instance OpenLMNP est mono-utilisateur : l'inscription se referme dès que le
premier compte existe. Deux autres modes sont possibles (`ALLOW_REGISTRATION`) — ouverte en
permanence, ou **sur invitation seulement**.

En mode `invite`, personne ne peut créer de compte de sa propre initiative. L'administrateur
émet un lien nominatif depuis *Administration → Invitations*, ou en ligne de commande :

```bash
php artisan openlmnp:invite ami@exemple.fr --name="Prénom Nom" --days=7
```

Ce que le lien garantit :

- **Usage unique** — il cesse de fonctionner dès qu'un compte a été créé avec lui.
- **Adresse verrouillée** — le formulaire affiche l'adresse invitée sans permettre de la
  changer : une invitation ne peut pas servir à créer le compte de quelqu'un d'autre.
- **Durée de vie** — 14 jours par défaut (`INVITATION_TTL_DAYS`), figée à l'émission.
- **Relance et révocation individuelles** — relancer régénère le lien et **tue le précédent**.

Seule une empreinte du jeton est enregistrée, jamais le jeton lui-même : une sauvegarde volée
ne livre aucun lien utilisable. Le lien ne s'affiche donc qu'une fois — et il est **toujours
imprimé**, même quand l'envoi du courriel échoue, pour qu'une instance sans serveur de
courriel reste utilisable.

> ⚠️ Révoquer une invitation ne suspend pas un compte déjà créé : le lien était déjà consommé.

## Multi-biens

Un même utilisateur peut gérer **plusieurs biens** meublés. Chaque bien porte ses propres
composants, travaux, mobilier, recettes, charges et emprunts. Le tableau de bord propose une
vue consolidée « Tous les biens » ainsi qu'un détail par bien.

Chaque bien décrit : adresse, surfaces (totale et louée), quote-part, date d'acquisition,
prix d'achat ou valeur vénale, frais de notaire, et part du terrain.

**Situation du logement** (`residence_situation`) : logement distinct, une ou plusieurs pièces
de la résidence principale, ou logement indépendant dans la résidence principale (ou sur la
même propriété — studio avec entrée propre, dépendance...). Ce dernier cas est, pour la loi,
un meublé **distinct** de la résidence principale : pas de limite de 120 jours, pas d'art. 35
bis, CFE due en principe, taxe d'habitation sur les résidences secondaires si le foyer
l'occupe une partie de l'année. La quote-part de surface (louée ÷ totale) s'applique dans tous
les cas ; seules les charges, travaux, mobilier et emprunts marqués « dédiés » y échappent.
L'estimation de valeur vénale (DVF) compare alors le **bâtiment entier**, et non le type du
seul logement loué.

## Travaux et mobilier

- **Travaux** : amortis sur leur propre durée, en totalité ou au prorata selon qu'ils
  concernent la partie louée ou les parties communes
- **Mobilier et équipements** : gestion du neuf et de l'occasion, avec justificatifs,
  amortis sur des durées courtes (typiquement 5 à 10 ans)

## Emprunts

- **Tableau d'amortissement généré automatiquement** à partir du montant, du taux, de la durée
  et de la date de première échéance (prêt amortissable classique ou in fine)
- Distinction **capital / intérêts** échéance par échéance
- **Intérêts déductibles** calculés pour l'exercice, avec application de la quote-part — sauf
  emprunt marqué **dédié** (finance exclusivement le logement loué, ex : prêt travaux d'un
  studio indépendant), déduit alors à 100 %
- Assurance emprunteur optionnellement déductible

## Recettes et import CSV Airbnb / Booking

- **Saisie manuelle** d'une recette (date, voyageur, plateforme, montant brut, commission,
  taxe de séjour, frais de ménage) avec calcul du **montant net imposable**
- **Import CSV** des relevés Airbnb et Booking :
  - reconnaissance des formats **français et anglais**
  - gestion du **format de nombres européen** (virgule décimale)
  - **détection des doublons** pour éviter les réimports
  - prise en compte des montants négatifs (remboursements, ajustements)

## Charges et pièces justificatives

- Charges **catégorisées** (taxe foncière, assurance, énergie, entretien, honoraires,
  frais de gestion, télécommunications…)
- **Prorata automatique** : une charge commune se voit appliquer la quote-part du bien,
  une charge 100 % dédiée est déduite intégralement, ou saisie d'une quote-part personnalisée
- **Charges récurrentes** : une charge mensuelle ou trimestrielle porte une action
  « Générer les échéances » qui crée les lignes manquantes jusqu'au 31 décembre de la même
  année — chacune reste une charge à part entière, au montant ajustable et avec son propre
  justificatif. La génération ne déborde pas sur l'exercice suivant, refuse un exercice
  clôturé et ne crée jamais de doublon
- **Pièces justificatives** : dépôt de fichiers (PDF, JPG, PNG) attachés aux charges,
  travaux et mobilier ; export groupé des justificatifs d'un exercice

## Coffre-fort des justificatifs (chiffrement par phrase de passe)

Optionnel, réversible, **désactivé par défaut**. Depuis *Paramètres → Coffre-fort*, vous
choisissez une phrase de passe — **distincte de votre mot de passe de connexion** — et vos
fichiers sont chiffrés sur le disque avec une clé dérivée d'elle.

- **Algorithme** : Argon2id pour dériver la clé, XChaCha20-Poly1305 en flux pour les
  fichiers (libsodium). Le chiffrement est **authentifié** : un fichier altéré ou tronqué
  est refusé, jamais servi tel quel.
- **La phrase de passe n'est enregistrée nulle part**, ni en clair ni sous forme dérivée.
  ⚠️ **La perdre, c'est perdre définitivement vos justificatifs** — personne ne peut les
  restituer, y compris l'hébergeur de l'instance.
- **En changer est instantané** : seule la clé est rescellée, aucun fichier n'est réécrit.
- **Les fichiers existants sont inclus** à l'activation, et restitués **à l'octet près** à
  la désactivation. Pour un gros volume, la commande
  `php artisan openlmnp:vault-migrate <email>` fait le même travail hors navigateur.
- **Périmètre** : le *contenu* des justificatifs et des photos de biens. Restent en clair
  le libellé, le montant, la date, le nom des fichiers et le reste de la comptabilité.
  Un coffre-fort protège la donnée **au repos** — pas contre quelqu'un qui exécuterait du
  code sur le serveur pendant que votre session est déverrouillée.
- **Conséquence sur l'API MCP** : tant que le coffre est activé, `attach_document` et
  `export_documents` sont refusés (un jeton d'API n'a pas de phrase de passe).
  `delete_document` reste disponible.
## Estimation de la valeur vénale (données DVF)

Quand vous mettez en location un bien que vous possédiez déjà, la base amortissable n'est pas
votre prix d'achat : c'est la **valeur vénale à la date de mise en location**. Sur un bien acheté
dix ans plus tôt, l'écart se compte souvent en dizaines de milliers d'euros d'amortissement.

Le bouton **« Estimer (DVF) »**, à côté du champ *Valeur vénale* de la fiche d'un bien, calcule
ce chiffre à partir des **ventes réelles de votre commune** : le fichier communal des
[Demandes de valeurs foncières](https://www.data.gouv.fr/datasets/demandes-de-valeurs-foncieres-geolocalisees)
publié par la DGFiP sur data.gouv.fr sous **Licence Ouverte 2.0**.

**Méthode.** Seules les ventes **mono-lot** du type de bien demandé sont retenues, leur prix au m²
est calculé, et on en prend la **médiane**. Le filtre mono-lot est essentiel : dans DVF, le prix
d'une vente est répété sur chacune de ses lignes, et le rapporter à la surface d'un seul lot d'une
vente qui en compte trois fausse le calcul. En dessous de cinq ventes exploitables, aucune valeur
n'est proposée — les millésimes voisins sont d'abord ajoutés.

**Ce n'est pas une expertise** : une médiane communale ignore l'étage, l'état, l'exposition et le
DPE. L'écran restitue l'échantillon (nombre de ventes, millésimes) pour que le chiffre soit
justifiable.

**Limites de la source** : DVF ne couvre ni l'Alsace-Moselle ni Mayotte, et ne remonte que cinq
ans. À Paris, Lyon et Marseille, l'estimation se fait par arrondissement.

**Vie privée et auto-hébergement.** Elle interroge un service public externe, comme les autres
fonctionnalités de données publiques décrites plus bas. Elle ne part **que sur un clic
explicite** — ouvrir la fiche d'un bien n'envoie rien — et transmet uniquement la commune, le
type de bien et la surface : ni adresse, ni montant. Une fois une commune consultée, le résultat
est conservé sur disque, donc l'instance refonctionne hors ligne. Pour la couper entièrement :

```bash
DVF_ENABLED=false
```

## Autocomplétion d'adresse (Base Adresse Nationale)

Sur les trois écrans qui demandent l'adresse d'un bien — *fiche d'un bien*, *premier lancement*,
*reprendre un dossier existant* — commencez à taper l'adresse : les propositions de la
[Base Adresse Nationale](https://adresse.data.gouv.fr) apparaissent, et en choisir une remplit
d'un coup l'**adresse**, le **code postal** et la **commune**. C'est surtout un code postal et
une ville qui cessent de se contredire.

**La saisie manuelle reste entière.** Les suggestions sont une aide, jamais une contrainte :
aucune validation n'en dépend, et un service injoignable, lent ou en erreur se traduit par une
absence de propositions — jamais par un formulaire qu'on ne peut plus valider.

**Vie privée et auto-hébergement.** C'est l'appel sortant **le plus bavard** du logiciel : il
révèle à la Géoplateforme de l'IGN le début d'adresse en cours de frappe, là où les autres
n'envoient rien sans un clic. Trois garde-fous : rien ne part avant **trois caractères**, la
requête attend **300 ms** sans frappe, et le débit est limité par compte. Pour le couper
entièrement — le script n'est alors même pas injecté dans les pages :

```bash
ADDRESS_LOOKUP_ENABLED=false
```

## Vérification du SIREN (base Sirene)

Dans *Mon profil*, le champ SIREN contrôle la clé du numéro dès la saisie : une faute de frappe
se voit sans rien interroger. Le bouton **« Vérifier dans Sirene »**, aussi proposé sur l'écran
de télédéclaration, cherche ensuite le numéro dans la base Sirene de l'Insee, par l'API
Recherche d'entreprises de l'État : entreprise active ou cessée, date de création, code APE.

Deux codes APE correspondent à la location meublée : **68.20A** (location de logements, durable)
et **55.20Z** (hébergement de courte durée). Un autre code n'est pas une erreur, mais mérite une
vérification si la location meublée est votre activité principale.

**Vie privée.** Seul le SIREN saisi est transmis, et seulement au clic. Rien n'est enregistré en
dehors d'un cache de la réponse publique. Pour couper la fonctionnalité : `SIRENE_ENABLED=false`.

## Dossiers liés (indivision partagée)

Un dossier lié est un dossier d'indivision **offert**, séparé de votre compte, partagé entre
indivisaires et ouvert par simple bascule (aucun mot de passe supplémentaire, aucune connexion
directe possible). Depuis *Dossiers liés* (menu Paramètres), son SIREN est vérifié dans la base
Sirene — nature juridique 2110 (indivision entre personnes physiques), établissement actif — et
verrouillé : ni le SIREN ni le caractère « indivision » ne peuvent plus changer ensuite. Un
sélecteur apparaît dans le menu utilisateur dès qu'un dossier existe ; un bandeau permanent
rappelle le dossier ouvert et permet d'y revenir.

Biens, recettes, charges, justificatifs, coffre-fort et badges restent propres à chaque dossier.
La clé d'intelligence artificielle, les jetons MCP, le mode de navigation et les avis restent
ceux du compte principal, quel que soit le dossier ouvert.

En auto-hébergement, le nombre de dossiers qu'un compte peut créer est **illimité par défaut**
(réglable par `LINKED_DOSSIERS_QUOTA`). Fonctionnalité derrière `LINKED_DOSSIERS_ENABLED`,
désactivée par défaut.

## Suppression de compte

Depuis *Mon profil*, le bouton **« Supprimer mon compte »** efface définitivement et
irréversiblement vos biens, exercices fiscaux, recettes, charges, amortissements, travaux,
mobilier, emprunts, justificatifs (coffre-fort compris) et jetons MCP. La confirmation demande
le mot de passe actuel et la ressaisie de l'adresse e-mail.

Un dossier d'indivision dont vous êtes l'unique membre actif est supprimé avec vous ; un dossier
partagé reste accessible à ses autres membres, vous en êtes seulement retiré. Le journal d'un
dossier supprimé part avec lui ; celui d'un dossier partagé garde les actions et leurs dates, sans
votre adresse e-mail ni votre nom, et vos invitations à ce dossier sont effacées. Sur le cloud, la
suppression est refusée tant qu'un abonnement payant pourrait être actif (résiliez-le d'abord) ;
un compte membre d'indivision, qui n'a jamais d'abonnement propre, n'est pas concerné par ce
refus. Le bouton n'apparaît pas pour un compte de démonstration ni pour un dossier lié.

Côté serveur, `php artisan openlmnp:account-delete {email}` fait la même suppression en ligne de
commande (voir `docs/INSTALLATION.md`).

## DPE du bien (base de l'ADEME)

Sur la fiche d'un bien, **« Retrouver le DPE »** cherche les diagnostics publiés à son adresse
dans la base de l'ADEME. S'il y en a plusieurs, ils sont tous listés et c'est vous qui choisissez ;
le numéro peut aussi être saisi à la main. La fiche indique ensuite ce que la classe permet :
décence énergétique d'un bail meublé (échéances 2025, 2028 et 2034, calendrier propre à
l'outre-mer) et obligation des meublés de tourisme, qui ne s'applique qu'à partir du
1er janvier 2034. Une alerte prévient six mois avant l'expiration du DPE.

**Vie privée.** C'est la seule de ces fonctionnalités qui transmet l'**adresse** du bien : à la
Géoplateforme de l'IGN pour la retrouver dans la Base adresse nationale, puis à l'ADEME. Seulement
au clic. Pour couper la fonctionnalité : `DPE_ENABLED=false`.

## Repère de loyer du marché (Carte des loyers)

Le simulateur affiche, bien par bien, un repère de loyer d'annonce issu de la « Carte des
loyers » de l'ANIL ([recherche sur data.gouv.fr](https://www.data.gouv.fr/datasets/?q=carte+des+loyers),
**Licence Ouverte 2.0**) : prix au m² et intervalle de prédiction à 95 %, pour la commune du
bien. Le lien affiché à l'écran pointe vers la fiche exacte du millésime réellement importé,
pas vers une page figée.

**Ce que mesure ce repère, et ce qu'il ne mesure pas.** C'est le loyer d'une **location NON
meublée, charges comprises** — pas une recette de meublé. Il sert à situer un bien par rapport
au marché de la location vide, jamais à estimer ce qu'il rapporterait en meublé de tourisme :
il n'entre dans **aucun** calcul fiscal ni dans la simulation micro-BIC / réel elle-même.

**Commune requise.** Le repère s'appuie sur la même commune que l'estimation DVF (champ
*Valeur vénale*) : sans un clic sur « Estimer (DVF) » au moins une fois sur la fiche du bien,
la commune n'est pas connue et le repère ne s'affiche pas. À Paris, Lyon et Marseille, il se
lit par arrondissement — la source ne publie pas de chiffre pour la commune globale.

**Mise à jour, entièrement automatique.** Une tâche planifiée **chaque jour**
(`openlmnp:refresh-rent-map --if-missing`) DÉCOUVRE le millésime à importer sur l'API
data.gouv.fr — plus besoin de relever les 4 URLs à la main à chaque nouvelle publication
(décembre) :

- table vide (instance tout juste installée) : import immédiat du dernier millésime connu du
  logiciel, en tentant d'abord la découverte puis, si l'API ne répond pas, un repli figé dans
  le code — une instance neuve reste fonctionnelle même si data.gouv.fr est en panne ;
- sinon, la commande tente le millésime suivant, mais **seulement à partir du 1er novembre**
  de cette année-là (heure de Paris) — le reste de l'année, aucun appel réseau n'est fait : une
  instance auto-hébergée n'a pas à interroger data.gouv.fr tous les jours pour rien ;
- une fois le jeu suivant trouvé, le format de ses 4 fichiers est vérifié **avant** tout
  remplacement ; au moindre écart, rien ne change et l'ancien millésime continue d'être servi.

Une instance qui n'a jamais réussi le moindre import, ou sans accès réseau, n'affiche
simplement pas de repère — rien ne dysfonctionne. Pour couper entièrement l'import planifié et
l'affichage :

```bash
RENT_MAP_ENABLED=false
```
## Analyse de justificatifs par IA

**Optionnel, désactivé par défaut.** Voir [INSTALLATION.md](INSTALLATION.md#intelligence-artificielle-optionnel)
pour l'activer avec une clé chez le fournisseur de votre choix (OpenRouter par défaut,
Anthropic ou Google en direct), ou avec un modèle installé chez vous.

Déposez une facture, un avis de taxe foncière, une attestation d'assurance, un tableau
d'amortissement bancaire ou un relevé de compte : OpenLMNP en extrait une **proposition de
saisie**, que vous relisez avant d'enregistrer quoi que ce soit.

**Rien n'est jamais créé sans votre relecture.** L'écran de confirmation affiche, pour
chaque champ, un indicateur de confiance et l'extrait du document qui a servi à le
remplir (« Lu : *Total TTC 143,20 €* »). Un modèle de langage se trompe ; c'est vous qui
validez.

| Document déposé | Ce qui est proposé |
|---|---|
| Facture de charge (énergie, télécom, ménage, honoraires…) | Date, montant TTC, taux de TVA, catégorie, description, récurrence |
| Facture de mobilier | Une ligne amortissable par article, durée d'amortissement selon la nature du bien |
| Facture de travaux | Date, montant, durée d'amortissement selon la nature des travaux |
| Avis de taxe foncière ou de CFE | Charge annuelle en catégorie « Taxe foncière » |
| Attestation d'assurance | Charge annuelle en catégorie « Assurance » |
| Tableau d'amortissement bancaire | L'emprunt et **toutes ses échéances réelles** |
| Relevé bancaire | Les opérations, à cocher une à une, déjà classées et catégorisées |

Points d'attention traités :

- **Doublons** : une écriture du même montant à trois jours près est signalée avant
  l'enregistrement. Sur un relevé, les lignes déjà saisies sont grisées et décochées.
- **Échéances de prêt réelles** : elles remplacent le tableau théorique et alimentent
  directement vos intérêts déductibles. L'application les protège ensuite de tout recalcul
  automatique — un recalcul reste possible, sur confirmation explicite.
- **Relevés bancaires** : l'IBAN n'est jamais conservé en entier, seuls les quatre
  derniers chiffres sont affichés. Les échéances de prêt détectées ne sont pas importées
  depuis un relevé, pour ne pas compter deux fois le capital remboursé.
- **Formats** : PDF, JPEG, PNG, WebP, 10 Mo maximum. Le type réel du fichier est vérifié,
  pas seulement son extension.
- **Deux modèles** : si le premier modèle doute, un second, plus solide, reprend le
  document — sans le relire ni le refacturer. Cette seconde lecture ne consomme pas votre
  quota : un document analysé compte pour un.
- **Confidentialité** : seuls le document et le nom du bien sont transmis. Jamais votre
  identité, votre SIREN ni vos autres données. Le journal technique ne conserve aucun
  contenu.

L'analyse est aussi disponible **depuis les formulaires** de charge, mobilier et travaux
(« Pré-remplir depuis ce document ») : elle ne remplit alors que les champs vides et vous
dit lesquels elle n'a pas touchés.

## Relevés annuels de plateforme

Le rapport annuel de revenus d'Airbnb, Booking ou Abritel se dépose dans *Importer un
justificatif* comme n'importe quelle pièce. L'analyse en tire **une recette par mois loué** :

- le montant retenu est le **brut**, la commission de la plateforme étant enregistrée à part et
  déduite de votre résultat ;
- chaque recette est datée du **dernier jour de son mois** ;
- les mois sans location ne créent aucune ligne ;
- la **taxe de séjour** collectée et reversée par la plateforme n'est répartie sur aucun mois :
  elle n'entre pas dans vos revenus bruts.

Rien n'est réparti au prorata : le relevé publie déjà, mois par mois, un revenu brut et un total
versé — leur écart est la retenue réelle de ce mois-là, régularisations comprises.

> ⚠️ **N'importez pas à la fois le relevé annuel et le CSV de la plateforme.** Ce sont les mêmes
> euros sous deux mailles différentes. L'écran vous prévient et décoche tout si l'exercice porte
> déjà des recettes, mais l'inverse n'est pas détecté.

## Simulateur micro-BIC vs régime réel

Le simulateur compare, pour un exercice, le **résultat imposable** dans les deux régimes :

- **Micro-BIC** : chiffre d'affaires × (1 − taux d'abattement)
- **Régime réel** : recettes − charges déductibles − amortissements déduits

Il affiche l'**économie réalisée** en euros et rend un **verdict** clair sur le régime
le plus avantageux. C'est l'outil qui justifie le passage — ou non — au régime réel.

Le taux et le plafond de chiffre d'affaires dépendent du **classement** du meublé (non
classé, classé, ou bail meublé longue durée) et de l'**année** des revenus — barème triennal
centralisé, jamais recopié en dur. Au-delà du plafond, le micro-BIC est affiché comme **non
disponible**, avec le motif, plutôt qu'un montant que la loi interdirait en réalité.

> Pour comprendre les règles fiscales sous-jacentes (abattements, plafonds, réforme 2026),
> voir le guide [fiscalite-lmnp-airbnb.md](fiscalite-lmnp-airbnb.md).

## Projection pluriannuelle

Un tableau projette le résultat fiscal sur **5 à 20 ans**, en tenant compte de l'extinction
progressive des amortissements (travaux, mobilier, puis composants courts). Il permet
d'anticiper l'année où le régime réel cesse d'être avantageux par rapport au micro-BIC.

## Liasse fiscale 2031 / 2033

OpenLMNP prépare les documents de la déclaration BIC au réel :

- **Télédéclaration interactive** : les lignes des formulaires Cerfa **2031-SD**,
  **2033-A / B / C / D** et **2042-C-PRO** sont affichées avec des boutons « Copier »
  pour les reporter dans l'espace impots.gouv.fr
- **Liasse fiscale PDF** : génération complète du document au format PDF (via DomPDF)
- **Contrôles de cohérence** entre formulaires : case 044 face à la ligne 490, ligne 572
  face à la ligne 254, et partie B du 2033-B face au résultat fiscal de l'exercice
  (310 + 318 − 350)
- **Détail des immobilisations** : une annexe, dans le PDF comme à l'écran, qui donne pour
  chaque actif sa ligne du 2033-C, sa valeur brute, sa dotation et son cumul — de quoi
  remonter n'importe quel montant de la liasse jusqu'à ce qui l'a produit
- **Détail du résultat** : une annexe du PDF qui refait le 2033-B pas à pas — recettes et
  commissions, chaque charge avec sa ligne et son affectation (dédiée ou partagée), la
  quote-part locative appliquée au total des charges partagées, les emprunts, le
  plafonnement des amortissements (art. 39 C) et l'imputation des déficits antérieurs

## FEC conforme DGFiP

Le **Fichier des Écritures Comptables (FEC)** peut être exporté au format légal exigé par
l'administration en cas de contrôle :

- **18 colonnes** conformes à l'article **A.47 A-1 du Livre des procédures fiscales**
- Séparateur et encodage conformes au format attendu par la DGFiP

## Écritures comptables automatiques

À partir des recettes, charges, amortissements et échéances d'emprunt, OpenLMNP génère
des **écritures comptables équilibrées** suivant un plan comptable adapté au LMNP.
Ces écritures alimentent le FEC et la liasse.

## API MCP pour assistants IA

OpenLMNP expose un serveur **MCP (Model Context Protocol)** qui permet à un assistant IA
(comme Claude) de consulter et gérer les données comptables : lister les biens, créer des
recettes et charges, calculer amortissements et résultats fiscaux, générer le FEC ou la liasse.
L'accès est authentifié et l'isolation des données entre utilisateurs est garantie.

Deux transports sont disponibles :

- **HTTP** (`/mcp`) — pour une instance accessible par le réseau. Activez `MCP_ENABLED=true`,
  puis générez un jeton depuis la page **Jetons MCP** de l'application ; l'assistant
  s'authentifie avec ce jeton (Bearer).
- **stdio** (local) — pour un assistant qui tourne sur la même machine que l'instance
  auto-hébergée, sans HTTP ni jeton :

  ```bash
  php artisan mcp:start openlmnp
  ```

  Le serveur agit au nom de l'unique compte réel de l'instance ; si elle en compte
  plusieurs, désignez-le via `OPENLMNP_MCP_USER=email@exemple.fr`. Ce transport est
  réservé à un opérateur ayant déjà accès à la machine (pas d'authentification propre).

  Exemple de déclaration côté client (Claude Desktop / Claude Code) :

  ```json
  {
    "mcpServers": {
      "openlmnp": {
        "command": "php",
        "args": ["/chemin/vers/openlmnp/artisan", "mcp:start", "openlmnp"]
      }
    }
  }
  ```

  Via Docker : `docker run --rm -i openlmnp php artisan mcp:start openlmnp`
  (tout argument passé au conteneur remplace le serveur web, après la préparation de la base).

### Démo publique (lecture seule)

Pour permettre aux annuaires MCP et aux curieux d'**essayer** le serveur sans créer de compte,
un **jeton démo partagé** peut être exposé, adossé au compte de démonstration à **données
fictives**. Tous les outils restent visibles, mais seuls les outils de **lecture/calcul**
s'exécutent ; les outils d'écriture renvoient un message invitant à créer un compte. Un
**rate-limiting par IP** protège l'instance.

```bash
# activation (env)
MCP_DEMO_ENABLED=true
MCP_DEMO_TOKEN=<valeur_stable_sans_pipe>
MCP_DEMO_RATE_LIMIT=20        # requêtes/minute/IP

# provisioning (compte démo + jeton déterministe)
php artisan openlmnp:mcp-demo-token
```

La détection se fait par **compte** (les identifiants du compte démo étant publics, tout jeton
porté par ce compte est traité en lecture seule). Connexion client : `Authorization: Bearer
<MCP_DEMO_TOKEN>` sur `/mcp`.

Pour les passerelles/annuaires qui réservent l'en-tête `Authorization` (ex. Smithery), il suffit
d'appeler `/mcp` **sans** en-tête `Authorization` : la requête est servie en démo lecture seule.
Aucun paramètre d'URL n'est nécessaire (l'ancien `?demo_token=` n'a jamais été lu, et ne faisait
que recopier le jeton dans les journaux d'accès).

## Assistant conversationnel

Un **assistant intégré** répond en français aux questions sur **l'utilisation du logiciel** :
où saisir une facture, pourquoi un exercice ne peut pas être clôturé, ce que contient le FEC,
quel CSV Airbnb importer. Il s'ouvre depuis le bouton d'aide en bas à droite, dans l'onglet
**Assistant** du panneau d'aide contextuelle.

- **Ancré sur la documentation embarquée** : l'assistant répond à partir des pages d'aide, de la
  FAQ et du guide fiscal livrés avec le logiciel. S'il ne sait pas, il le dit — il n'invente ni
  menu, ni bouton, ni fonctionnalité.
- **Aucun conseil fiscal** : les questions de choix de régime, d'interprétation de la loi ou de
  déductibilité d'une dépense sont refusées et renvoyées vers le simulateur intégré et un
  expert-comptable. Un contrôle côté serveur remplace toute réponse qui glisserait vers le
  conseil personnalisé.
- **Aucune action** : l'assistant ne crée, ne modifie et ne supprime jamais rien ; il explique
  comment le faire et donne le lien vers la bonne page.
- **Conversation éphémère** : rien n'est enregistré. Seuls des compteurs d'usage (jetons, coût,
  durée, pouce) sont journalisés, jamais le contenu des échanges.

### Accès aux données du compte (optionnel)

Désactivée par défaut, une option de *Mon profil* autorise l'assistant à **lire** les données du
compte — biens, recettes, charges, emprunts, exercices — pour répondre à des questions situées
(« qu'est-ce qu'il me manque pour clôturer 2025 ? »). La lecture passe par un sous-ensemble des
outils MCP limité à la consultation et aux calculs non persistants ; aucun outil d'écriture,
d'export ou de génération de fichier n'est accessible. La réponse indique ce qui a été consulté.

Sans cette option, **aucune donnée de la comptabilité ne quitte l'instance** : seules la question
et la page en cours accompagnent la demande.

### Activation

L'assistant suit la configuration `AI_*` décrite dans [INSTALLATION.md](INSTALLATION.md) : il est
**désactivé par défaut** et n'apparaît que si `AI_ENABLED=true` et qu'une clé (d'instance ou
personnelle) est résolue. Sans clé, l'onglet explique comment en renseigner une. Un plafond
quotidien par utilisateur est configurable (`AI_CHAT_DAILY_QUOTA`).


## Version et nouveautés

La page **Nouveautés** est ouverte à **tous les utilisateurs**, pas seulement à
l'administrateur de l'instance. Elle affiche :

- la **version installée** et sa date de publication ;
- la **dernière version publiée**, si elle est plus récente que la vôtre — sauf sur une
  instance qu'on ne peut pas mettre à jour depuis l'interface (`UPDATE_SELF_APPLY=false` :
  image Docker officielle, offre en ligne), où l'annonce n'est faite qu'à l'administrateur,
  seul à pouvoir agir ;
- les **notes des cinq dernières versions**, mises en forme, chacune marquée « votre
  version » ou « plus récente » — ce qui montre d'un coup d'œil ce qui vous manque.

La version installée est lue localement. Les notes de version viennent des *releases*
publiées sur le dépôt GitHub du projet : c'est le seul échange sortant de cette page, il ne
transmet aucune donnée de votre comptabilité, il se déclenche après l'affichage de la page
(jamais pendant son chargement) et son résultat est conservé une heure. Une instance sans
accès sortant peut le couper entièrement — voir `UPDATE_CHECK_ENABLED` dans
[INSTALLATION.md](INSTALLATION.md).

Installer une mise à jour reste une action d'administration, sur la page *Mises à jour*.

---

Voir aussi : [INSTALLATION.md](INSTALLATION.md) · [DEMO.md](DEMO.md) · [FAQ.md](FAQ.md) · [fiscalite-lmnp-airbnb.md](fiscalite-lmnp-airbnb.md)
