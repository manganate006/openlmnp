# Changelog

Toutes les évolutions notables d'OpenLMNP. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/).

## [1.5.0] - 2026-09-06

> ⚠️ **Cette version change des liasses fiscales déjà générées.** La correction du tableau
> 2033-D (cases 982/983/984, détaillée plus bas) modifie ce que l'application imprime pour
> des exercices **déjà déposés**. Régénérer une liasse antérieure produira un document
> différent de celui transmis à l'administration — c'est voulu, l'ancien était faux.
> `php artisan openlmnp:repair-deficits` reconstitue le suivi sur les dossiers déjà tenus,
> en mode rapport par défaut.

### Ajouts

- **Un assistant « Reprendre un dossier existant », en cinq étapes.** Accessible depuis
  l'écran de premier lancement (« J'ai déjà une comptabilité LMNP ») et depuis les exercices
  fiscaux, il reprend un dossier tenu par un cabinet **à partir de la seule dernière liasse**,
  sans ressaisir un exercice passé : votre situation, votre bien, vos amortissements, vos
  reports, puis un contrôle. **Chaque montant demandé porte le numéro de la case Cerfa où le
  lire** — 2033-D case 870 pour les amortissements différés, 2033-A case 030 pour le cumul,
  2033-A case 028 pour les immobilisations brutes, 2033-D cases 980 à 984 pour les déficits
- L'étape des amortissements commence par un **choix de méthode**, avantages et inconvénients
  affichés : *recopier les lignes de sa liasse* (une dizaine de champs, mais les chiffres
  restent exactement ceux du comptable) ou *répartir automatiquement la base* (un clic, mais
  un plan différent du sien). La grille qui suit est l'éditeur d'amortissements existant, avec
  ses deux modes — la reprise ne les réinvente pas, elle les propose au bon moment
- **L'étape de contrôle est la fonctionnalité.** Elle met face à face ce que dit la liasse et
  ce que l'application reconstitue, ligne par ligne, et nomme les causes probables d'un écart
  par ordre de fréquence. Quand l'écart correspond aux frais d'acquisition, un bouton les
  bascule en charges et rejoue le contrôle sur place
- Rien n'est écrit dans les exercices fiscaux avant la fin du parcours : un assistant
  abandonné en route ne laisse pas derrière lui un exercice dont toute la chaîne de reports
  lirait les montants
- **Reprendre une comptabilité tenue par un cabinet, sans ressaisir les exercices passés.**
  Un exercice peut désormais porter des **soldes d'ouverture** recopiés de la liasse N-1 :
  amortissements réputés différés (2033-D case 870 ou 2033-B case 318), déficits reportables
  par millésime (2033-D cases 980-984), cumul d'amortissements déclaré (2033-A case 030) et
  provenance de la saisie. Jusqu'ici le report d'amortissements différés se lisait
  **uniquement** dans l'exercice N-1 présent en base : celui qui arrivait d'un cabinet, et
  n'avait donc aucun exercice antérieur dans l'application, perdait purement et simplement
  son report. Créer un exercice sans son prédécesseur n'est plus refusé lorsque des soldes
  d'ouverture sont saisis, et la liste des exercices porte un badge « Reprise »
- Le cumul d'amortissements d'ouverture est une donnée de **contrôle** : il sert à comparer
  ce que l'application reconstitue à ce que le cabinet a déclaré, et n'entre jamais dans un
  calcul — l'y faire entrer reviendrait à compter deux fois le même amortissement
- **Un exercice N-1 vide ne peut plus effacer un solde d'ouverture en silence.** Un exercice
  créé par erreur puis jamais alimenté portait un report de 0 € qui l'emportait sur les
  12 000 € recopiés de la liasse, sans aucun signal. Le solde saisi est désormais conservé,
  et la situation est signalée dans la liste des exercices tant que l'exercice vide subsiste
- **Les déficits reportables sont enfin suivis pour eux-mêmes.** Chaque exercice porte son
  stock de déficits antérieurs, ce qui en a été imputé sur le bénéfice, ce qui reste à
  reporter, et le détail par millésime. L'imputation part du millésime le plus ancien et
  s'arrête à dix ans : un déficit né en 2015 s'impute de 2016 à 2025, et disparaît en 2026
  (CGI art. 156, I-1° ter ; BOI-BIC-CHAMP-40-20 § 250). L'amortissement réputé différé, lui,
  se reporte sans limite de durée (art. 39 C, II-3) — ce ne sont pas les mêmes règles, et ce
  n'était pas le même compteur
- **Un contrôle de reprise, chiffre à chiffre.** Un service compare la liasse du dernier
  exercice bouclé par le cabinet à ce que l'application reconstitue : immobilisations brutes
  (2033-A case 028), amortissements cumulés (case 030), amortissements différés (2033-D case
  870), déficits restants (case 984) et résultat (2033-B cases 352/354). Verdict par ligne :
  vert jusqu'à 1 € d'écart — une liasse est arrondie à l'euro —, ambre jusqu'à 1 %, rouge
  au-delà, avec les causes probables classées par fréquence : date de mise en location, part
  du terrain, frais d'acquisition passés en charges, composant manquant, valeur vénale au
  lieu du prix d'acquisition. Certaines pistes sont *corroborées* par les données du dossier
  quand celles-ci les appuient — l'écart vaut exactement les frais de notaire, par exemple.
  L'écran qui l'affiche arrive avec l'assistant de reprise
- L'outil MCP `get_fiscal_year` expose les soldes d'ouverture et le suivi des déficits
  (lecture seule : aucun outil MCP ne les écrit)
- **Un plan d'amortissement fidèle à celui du cabinet.** Date de départ par composant (pour
  un passage au réel plusieurs années après la mise en location, ou une toiture refaite en
  cours de route), composants à nom libre hors catalogue, ligne Cerfa explicite par composant,
  dotations recopiées telles quelles et cumuls d'amortissements d'ouverture par actif. Les
  frais d'acquisition peuvent être **amortis, passés en charges ou non repris** : c'est le
  choix le plus fréquemment divergent d'un cabinet à l'autre, et il fallait pouvoir le suivre
- **Import CSV générique** (recettes, charges, mobilier, travaux) : séparateur, montants et
  dates reconnus automatiquement, correspondance des colonnes corrigeable, aperçu avant
  écriture, doublons écartés. L'import Airbnb reste l'écran à préférer pour un export Airbnb :
  lui seul reconstitue le brut depuis le net
- **Export et import du dossier complet** (`openlmnp:export-dossier`, `openlmnp:import-dossier`)
  : un JSON versionné qui fait l'aller-retour à l'identique, pour changer d'instance sans
  ressaisie
- `list_property_components` (MCP) expose la ligne Cerfa, la date de départ et le cumul
  d'ouverture de chaque composant

### Corrections

- **⚠️ Le tableau 2033-D déclarait des déficits qui n'existaient pas.** Les cases 982, 983 et
  984 suivent les **déficits reportables** ; elles étaient alimentées par le montant des
  **amortissements réputés différés**. Tout bailleur ayant de l'amortissement différé — c'est
  le cas le plus courant en LMNP — a donc déposé une liasse portant des déficits qu'il n'avait
  pas. **Cette correction change des liasses déjà générées et déjà transmises.** Les cases
  982/983/984 portent désormais les déficits, la case 860 le déficit de l'exercice et la case
  870 le total d'amortissements différés reportables ; la case 360 du 2033-B reste
  l'amortissement différé antérieur.

  L'ordre d'imputation retenu est sourcé, pas déduit : les amortissements différés se
  déduisent **du résultat de l'exercice** (BOI-BIC-AMT-20-40-10-30 § 10), le déficit antérieur
  s'impute ensuite sur un résultat **déjà déterminé** (BOI-BIC-DEF-20-10 § 70 ; CE, 10 avril
  2015, n° 369667). Conséquence : tant qu'il reste de l'amortissement différé, aucun déficit
  antérieur n'est consommé — alors que son délai de dix ans continue de courir.

  Les totaux d'exercice sont figés en base : la commande **`php artisan openlmnp:repair-deficits`**
  reconstitue le suivi sur les dossiers déjà tenus (rapport par défaut, `--fix` pour écrire).
  Elle ne réécrit que les colonnes de déficit — le résultat fiscal déclaré ne bouge pas. La
  page « Aide à la télédéclaration » avertit les utilisateurs concernés.

- **Le contrôle de reprise ne voyait pas la cause d'écart la plus fréquente.** Il ne
  rapprochait un écart que du montant **brut** des frais d'acquisition. Or sur la ligne des
  amortissements **cumulés** (2033-A case 030), l'écart ne vaut pas les frais entiers mais
  seulement ce qui en a été amorti à ce jour : la piste « frais passés en charges par le
  cabinet » n'était donc jamais mise en avant là où elle est vraie. Les deux références sont
  désormais comparées.

### Interne

- `openlmnp:recompute-depreciation` : signale (et corrige sur demande) les dotations qui ne
  reflètent plus le plan, sans jamais toucher une dotation saisie à la main ni un cumul
  d'ouverture aberrant, qu'elle se contente de nommer
## [1.4.4] - 2026-09-04

### Corrections

- **L'assistant d'import annuel n'importait jamais les recettes Airbnb.** Son étape
  « Recettes Airbnb » acceptait un fichier, ne le lisait pas, et affichait « Import
  terminé » — sans erreur, sans avertissement, et sans avoir créé la moindre recette.
  L'état d'un champ de dépôt de fichier est une structure, jamais un simple chemin : le
  test qui gardait le bloc d'import était donc toujours faux, et tout l'import sauté.
  Un bailleur pouvait croire ses recettes de l'année enregistrées alors qu'elles
  manquaient — dans une déclaration fiscale
- **La date de télédéclaration d'un exercice devient une vraie colonne.**
  `fiscal_years.transmitted_at` était déclarée dans le modèle et exposée par deux outils
  MCP, mais aucune migration ne l'avait jamais créée. Elle rendait `null` à la lecture,
  ce qui la rendait invisible. ⚠️ Plus gênant : SQLite traite un nom de colonne inconnu
  entre guillemets comme du texte, donc un filtre sur cette colonne rendait **toutes**
  les lignes au lieu d'aucune, sans jamais signaler d'erreur. Elle reste vide pour les
  exercices existants : rien ne permet de reconstituer après coup ce qui a été déposé

## [1.4.3] - 2026-09-04

### Corrections

- **Une écriture sur quatre pouvait être perdue quand plusieurs requêtes se croisaient.**
  Depuis la version 1.2.0 le serveur sert quatre requêtes de front, mais la base était
  restée réglée pour un seul écrivain : mesuré à quatre requêtes simultanées, **27 % des
  transactions échouaient sur « database is locked »**. La base passe en journal WAL, avec
  des transactions ouvertes en mode `IMMEDIATE` et une attente de verrou bornée à 5 s —
  plus aucune transaction perdue dans la même mesure, et une écriture 240 fois moins
  coûteuse sur le disque (7,30 ms → 0,03 ms)

  ⚠️ Le journal WAL seul n'aurait pas suffi, il aurait aggravé le défaut : une transaction
  ouverte en lecture qui se met à écrire reçoit un refus **sans que le délai d'attente
  soit consulté**, et le mode WAL rend ce cas plus fréquent (66 % de pertes dans la même
  mesure). C'est l'ouverture en mode `IMMEDIATE` qui résout le problème

  Les quatre réglages restent surchargeables (`DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`,
  `DB_BUSY_TIMEOUT`, `DB_TRANSACTION_MODE`) : **une base placée sur un stockage réseau**
  (NFS, SMB, partage d'un NAS) ne supporte pas le mode WAL et doit être lancée avec
  `-e DB_JOURNAL_MODE=delete -e DB_SYNCHRONOUS=FULL`

- ⚠️ **Sauvegardes — à lire si vous sauvegardez vous-même.** En mode WAL, les dernières
  écritures vivent dans un fichier `database.sqlite-wal` voisin. Copier le seul
  `database.sqlite` d'une instance en service donne désormais une base **incomplète, sans
  message d'erreur**. `INSTALLATION.md` et la FAQ décrivaient précisément cette méthode :
  ils indiquent maintenant la commande d'instantané à utiliser à la place

## [1.4.2] - 2026-09-05

### Ajouts

- **Le visiteur de la démonstration qui apprécie le produit s'entend proposer de le garder.**
  L'écran « j'aime » ne proposait que de mettre une étoile, de parrainer ou d'écrire dans les
  Discussions — soit de soutenir un logiciel qu'on n'a pas adopté. En démonstration, il ouvre
  désormais sur un bloc « Gardez tout ça », les trois gestes de soutien passant dessous, sous
  « Ou, si vous préférez l'héberger vous-même ». Taire l'auto-hébergement au moment de
  proposer l'offre payante serait malhonnête sur un produit AGPL
- La durée annoncée avant l'effacement du bac à sable vient de la configuration, jamais d'un
  texte en dur : elle reste juste sur n'importe quelle instance

### Notes d'exploitation

- Deux conditions cumulatives pour que ce bloc existe : l'audience doit être la démonstration,
  et `FEEDBACK_URL_PRO` doit être renseignée. **Cette variable est vide par défaut**, et ce
  défaut est le point important : une instance auto-hébergée n'affiche aucune sollicitation
  commerciale, sans avoir rien à désactiver

## [1.4.1] - 2026-09-04

### Corrections

- **Les justificatifs déposés avant la montée en Laravel 11 redeviennent lisibles.**
  Laravel 11 a déplacé la racine du disque `local` de `storage/app` vers
  `storage/app/private`. Les chemins en base étant relatifs à cette racine, les fichiers
  déposés avant la montée de version sont restés à l'ancien emplacement : l'application
  ne les servait plus. Rien ne cassait, rien n'alertait - les pièces disparaissaient
  simplement de l'interface. Toute instance ayant franchi cette montée de version est
  concernée, pas seulement la nôtre
- **Commande `openlmnp:migrate-document-storage`** - rapport par défaut, `--fix` pour
  déplacer. Elle n'écrase jamais un fichier déjà présent à la nouvelle racine : ce cas
  est signalé, pas exécuté. Elle énumère les dossiers à rapatrier (`documents`, `fec`,
  `tax-returns`) plutôt que de parcourir `storage/app`, qui contient la nouvelle racine
  et emporterait la clé d'instance au passage
- **Repli de lecture** dans le contrôleur de documents, pour les instances où la commande
  ne sera jamais lancée : un justificatif introuvable à la nouvelle racine est cherché à
  l'ancienne avant d'être déclaré perdu

### Documentation

- **L'aide de l'estimation DVF**, livrée avec la fonctionnalité en 1.4.0 mais oubliée dans
  ses écrans d'aide. La fiche « Biens immobiliers » décrit désormais le bouton
  « Estimer (DVF) » : ce qui est envoyé (la commune et le type de bien, rien d'autre), ce
  qui est affiché, et pourquoi rien ne s'affiche quand l'échantillon est trop mince
- **Trois renvois devenus faux ont disparu.** L'aide conseillait d'aller estimer sa valeur
  vénale sur `app.dvf.etalab.gouv.fr`, alors que l'application le fait maintenant. Les
  mentions restantes précisent l'inverse de ce qu'on pourrait croire : l'estimation DVF
  porte sur le bien **entier**, terrain compris, et ne dispense donc pas de ventiler la
  part du terrain, qui n'est pas amortissable
- **`INSTALLATION.md` documente `openlmnp:migrate-document-storage`** dans la section
  « Mise à jour », qui est le moment où l'on en a besoin. Une commande de maintenance se
  lance depuis le serveur : sa place est dans le guide d'installation, pas dans l'aide
  de l'interface

## [1.4.0] - 2026-09-04

### Ajouts

- **Estimation de la valeur vénale d'un bien, à partir des ventes réelles.** La fiche
  d'un bien propose désormais d'estimer sa valeur de marché depuis les **demandes de
  valeurs foncières** (DVF) publiées par la DGFiP : les ventes de la commune, filtrées
  par type de bien, ramenées à un prix au mètre carré dont on prend la **médiane**. La
  taille de l'échantillon et les millésimes retenus sont affichés avec le résultat -
  sans quoi une médiane calculée sur trois ventes se lirait comme une vérité
- **Une estimation trop mince n'est pas affichée du tout.** Si la commune ne fournit
  pas `DVF_MIN_SAMPLE` ventes comparables (5 par défaut), la recherche s'élargit aux
  millésimes voisins, jusqu'à trois. Si le compte n'y est toujours pas, l'écran dit
  combien de ventes il a trouvées et s'arrête là, plutôt que d'habiller un chiffre
  d'une réserve que personne ne lit
- **La valeur vénale sert la répartition par composants.** C'est son usage : la base
  amortissable se répartit sur la valeur du bien hors terrain, et cette valeur devait
  jusqu'ici être saisie de mémoire ou cherchée ailleurs
- **45ᵉ outil MCP** - `estimate_market_value`, qui expose la même estimation à un
  assistant IA, avec les mêmes garde-fous d'échantillon
- **Commande `openlmnp:refresh-dvf-years`** - les millésimes DVF disponibles sont
  relevés auprès de la source au lieu d'être figés dans la configuration : un nouveau
  millésime est pris en compte sans mise à jour de l'application

### Notes d'exploitation

- La fonction est **active par défaut** (`DVF_ENABLED=true`) et n'exige aucune clé :
  l'API DVF est publique. Elle s'éteint par `DVF_ENABLED=false`
- Aucune donnée du bien n'est transmise : seule la **commune** (code INSEE) et le type
  de bien partent à l'API. Les propriétés gagnent une colonne `insee_code`
- Les réponses sont mises en cache `DVF_CACHE_DAYS` jours et l'appel est limité par
  `DVF_RATE_LIMIT` - une instance auto-hébergée ne martèle pas un service public

## [1.3.2] - 2026-09-04

### Corrections

- **Le taux de commission Airbnb ne propose plus de valeur par défaut.** Le champ
  « Taux commission Airbnb » d'un bien était pré-rempli à 3,6 %, et son infobulle
  présentait le modèle des « frais partagés » (3 % + TVA) comme le seul existant.
  Airbnb généralise en France les « frais hôte uniquement » le 13 octobre 2026 :
  15,5 % hors taxes, soit 18,6 % TVA comprise. Ce taux sert à reconstituer le
  montant brut depuis l'export « Réservations », qui ne détaille pas la commission ;
  laissé à 3,6 % sur des réservations relevant du nouveau modèle, il minore la
  recette déclarée de quinze points - sur une déclaration fiscale, pas sur un
  tableau de bord
- **L'import dit désormais ce qu'il ne peut pas savoir.** Le modèle de frais
  applicable dépend de la date de **confirmation** de la réservation, que l'export
  « Réservations » ne contient pas : un taux unique par bien est donc faux pour une
  partie des lignes tant que des réservations antérieures à la bascule restent à
  honorer. Sans taux configuré, l'import nomme les deux valeurs possibles au lieu de
  réclamer « le taux » ; avec un taux, il rappelle cette limite. L'export
  « Historique des transactions », qui porte la commission réellement prélevée ligne
  par ligne, reste le seul à traverser la période sans approximation

> Les taux déjà enregistrés ne sont pas modifiés : 3,6 % reste le bon taux pour les
> réservations confirmées avant la bascule.

### Interne

- 3 tests sur la reconstitution du brut et les avertissements d'import, dont 2
  vérifiés en échec sur le code précédent

## [1.3.1] - 2026-09-03

### Corrections

- **La liasse signalait elle-même que son tableau d'amortissements ne bouclait pas.**
  Le formulaire 2033-C porte un contrôle de cohérence « ligne 572 = ligne 254 du 2033-B ».
  Il échouait, et imprimait un avertissement sur le document destiné à l'administration.
  Trois causes cumulées : la ligne 572 sommait les dotations **brutes**, sans prorata de
  première année ni prise en compte des plans arrivés à terme, quand la 254 somme les
  dotations effectives ; les **frais de notaire et d'agence n'avaient aucune ligne** dans
  ce tableau alors que la 254 les compte ; et le cumul des amortissements était écrasé
  d'un bien à l'autre, puis approximé par « dotation × nombre d'années ». Les deux lignes
  proviennent désormais des mêmes calculs : l'égalité est vraie par construction
- **La colonne « amortissements à la fin de l'exercice » manquait au formulaire**, ainsi
  que son total, la ligne 570. Elle était calculée puis jetée — c'est aussi pour cette
  raison que deux des trois défauts ci-dessus étaient passés inaperçus
- **Les travaux réalisés avant la mise en location n'étaient comptés nulle part** dans les
  amortissements cumulés du bilan (ligne 030) : le calcul démarrait à la date de mise en
  location du bien, et non à celle de chaque actif. Une rénovation faite en 2022 pour une
  location ouverte en 2023 disparaissait

> ⚠️ **Ces corrections déplacent des montants imprimés.** Régénérer la liasse d'un exercice
> déjà déposé produira un document différent de celui transmis à l'administration.

### Interne

- `DepreciationService::depreciationDetailForYear()` rend, actif par actif, l'assiette
  brute, la dotation de l'exercice et le cumul — alimenté par les mêmes méthodes que la
  ligne 254, ce qui rend l'écart structurellement impossible
- Le cumul est un rejeu année par année, et non plus une approximation linéaire
- 8 tests neufs, qui échouent tous les huit sur le code précédent

## [1.3.0] - 2026-09-03

### Ajouts

- **OpenLMNP vous demande votre avis, une fois.** Après un moment d'utilisation réelle,
  une invitation propose de dire ce que vous pensez du logiciel : un pouce, et si vous le
  souhaitez un mot. Elle n'apparaît qu'une seule fois, et seulement une fois qu'une action
  concrète a été faite — demander son avis à quelqu'un qui vient d'arriver ne renseigne
  sur rien. Si vous appréciez le logiciel, elle propose les trois gestes qui le font
  vivre : une étoile, un parrainage, un mot dans les Discussions
- **Rien ne quitte votre instance.** Sans `FEEDBACK_FORWARD_EMAIL`, votre retour est
  enregistré dans votre propre base et l'écran de confirmation vous propose de l'envoyer
  vous-même, par votre client mail, si vous en avez envie. Aucun envoi automatique, aucun
  appel sortant. `FEEDBACK_ENABLED=false` désactive tout, et les seuils
  (`FEEDBACK_MIN_SECONDS`, `FEEDBACK_MIN_ACTIONS`, `FEEDBACK_COOLDOWN_DAYS`…) se règlent
  sans toucher au code. La politique de confidentialité décrit ce qui est collecté

## [1.2.0] - 2026-09-03

### Ajouts

- **Les amortissements se règlent enfin au montant, et plus seulement au pourcentage.**
  L'écran *Amortissements* gagne un onglet **Montants** : base amortissable, durée et
  dotation annuelle en euros, composant par composant. Jusqu'ici seuls des curseurs en
  pourcentages entiers étaient proposés, ce qui rendait quasi impossible de basculer une
  comptabilité LMNP existante — les montants d'OpenLMNP ne pouvaient pas coïncider avec
  ceux déjà pratiqués par un comptable. Merci à @ovrtn de l'avoir signalé (#8)
- Une base saisie à la main est **verrouillée** : ni les curseurs, ni la commande de
  réparation ne la recalculent. Ventiler moins que la base amortissable devient permis, et
  signalé ; ventiler plus reste refusé

### Corrections

- **Le prorata de première année comptait un jour de trop, sur tous les amortissements.**
  Composants, travaux, mobilier et frais d'acquisition calculaient les jours restants par
  `diffInDays(...) + 1`, alors que cette fonction inclut déjà la journée en cours : toute
  première année était majorée de 1/365, soit **+0,27 %**. Un bien mis en location le
  1er janvier amortissait 100,27 % de sa dotation. ⚠️ **Les exercices déjà enregistrés
  gardent l'ancien calcul** : lancez « Recalculer la chaîne » depuis la page Exercices, ou
  `php artisan openlmnp:repair-orphan-fiscal-years --fix`
- **Supprimer un bien laissait ses montants dans les exercices déjà calculés.** Aucune clé
  étrangère ne pouvait s'en charger — un exercice agrège tous les biens d'une année — et
  rien ne le signalait. Nouvelle commande `openlmnp:repair-orphan-fiscal-years` (rapport
  par défaut, `--fix` pour agir, `--closed` en plus pour un exercice clôturé)
- **L'écran de saisie des composants demandait des centimes bruts** — `50000` pour 500 €,
  sans libellé ni symbole — alors que sa propre table affichait des euros. Il était masqué
  du menu mais toujours accessible, et la checklist du tableau de bord y envoyait. Il est
  supprimé, tout passe par l'écran *Amortissements*
- La fiche d'aide des composants n'était atteignable que depuis cet écran masqué : l'écran
  réellement utilisé n'avait donc aucune aide contextuelle
- Le montant de chaque composant s'affichait avec un symbole euro en double
- L'assistant de premier lancement annonçait des durées fausses (« Plomberie 25 ans »,
  « Revêtements ») qui ne correspondaient à aucun des composants réellement créés

### Interne

- `base_amount` devient la source de vérité d'un composant ; `percentage` est dérivé, et
  l'invariant « total = 100 % » se vérifie en centimes contre la base amortissable — c'est
  ce qui rend représentable une ventilation exacte du type 33,33 / 33,33 / 33,34
- La formule de ventilation, écrite à cinq endroits, vit désormais dans `DepreciationService`
- `saveComponents()` met à jour les composants au lieu de tout supprimer et recréer : les
  identifiants sont stables et l'appariement se fait par id, plus par nom
- Validation côté serveur de la ventilation, qui ne reposait que sur du JavaScript
- Trois méthodes mortes retirées du modèle, dont un calcul de prorata **mensuel** qui
  contredisait le calcul **journalier** réellement utilisé
- 32 tests supplémentaires, dont un jeu de valeurs d'or qui verrouille le calcul complet
  d'un bien (quote-part, prorata, travaux antérieurs, mobilier, frais, fin de plan)

## [1.1.7] - 2026-09-03

### Corrections

- **Thème sombre : un dernier reste de blanc sur la bordure du graphique d'amortissement.**
  La refonte des couleurs du 25 août avait remplacé les 173 `var(--fi-*)` des vues, mais
  l'éditeur d'amortissements lisait le sien depuis JavaScript
  (`getPropertyValue('--fi-body-bg')`), là où le garde-fou ne regardait pas : le test retire
  les blocs `<script>` avant de scanner. La bordure des segments du donut retombait donc sur
  son repli blanc en thème sombre. Elle suit désormais le jeu de jetons, comme le reste (#5)

### Interne

- `PanelStylesheetTest` couvre à présent les propriétés CSS lues depuis JavaScript, l'angle
  mort qui avait laissé passer le cas ci-dessus — vérifié en réintroduisant la régression
- `server.json`, le manifeste publié au registre MCP officiel, était resté à la version 1.1.4
  alors que deux versions étaient sorties depuis : il est resynchronisé

## [1.1.6] - 2026-09-02

### Corrections

- **L'assurance d'emprunt à taux variable était comptée cent fois trop cher.** Le taux
  d'assurance est un pourcentage annuel, au même titre que le taux d'intérêt, mais la
  division par 100 manquait au calcul du tableau d'amortissement. Sur un prêt de 242 000 €
  assuré à 2,21 %, l'assurance ressortait à près de 44 000 € par mois au lieu de 447 €, et
  gonflait d'autant les charges déductibles - jusqu'à afficher plus de 500 000 € de charges
  sur un exercice. Les intérêts, eux, étaient corrects. Seuls les emprunts en assurance
  « variable » sont concernés ; l'assurance à montant fixe ne passait pas par ce calcul

### Interne

- Nouvelle commande `php artisan openlmnp:repair-loan-insurance`. Le tableau d'amortissement
  et les totaux d'un exercice sont **stockés** : corriger le calcul ne suffit pas à réparer
  les données déjà enregistrées. La commande régénère les tableaux concernés puis recalcule
  les exercices, en laissant de côté les exercices clôturés - une déclaration déposée ne se
  réécrit pas en silence, elle est signalée pour décision. Rapport seul par défaut, `--fix`
  pour appliquer

## [1.1.5] - 2026-09-02

### Corrections

- **Les montants saisis dans l'assistant de configuration étaient enregistrés cent fois trop
  grands.** Un bien déclaré à 250 000 € partait en base à 25 000 000 €, et les composants
  d'amortissement étaient calculés sur ce montant. La conversion des euros en centimes était
  appliquée deux fois : une première par le formulaire lui-même, une seconde à
  l'enregistrement. Prix d'achat, frais de notaire, honoraires d'agence et valeur vénale
  étaient concernés ; les montants d'emprunt, qui suivent un autre chemin, ne l'étaient pas
- **Aucun composant d'amortissement n'était créé par l'assistant.** L'option « Générer les
  6 composants par défaut » s'affichait désactivée alors que l'étape annonce le contraire :
  sa valeur par défaut n'était pas appliquée au remplissage du formulaire. Sans composants,
  le bien n'était pas amorti du tout

### Interne

- Nouvelle commande `php artisan openlmnp:repair-components`. Elle resynchronise les
  composants d'amortissement restés calculés sur un ancien montant - typiquement après la
  correction manuelle d'un prix, qui laissait derrière elle des composants périmés sans que
  rien ne le signale sur la fiche du bien. Elle se contente d'un rapport par défaut, `--fix`
  applique les corrections. La base d'un composant étant modifiable à la main, seuls les
  écarts d'un facteur dix ou plus sont corrigés d'office ; les écarts plus faibles sont
  signalés et ne sont repris qu'avec `--all`

## [1.1.4] - 2026-09-02

### Améliorations

- **Le serveur HTTP ne servait qu'une requête à la fois.** Le serveur intégré de PHP est
  mono-processus par défaut : il traitait une requête, puis la suivante, et mettait le reste
  en file. Mesuré sur dix requêtes parallèles, le temps par requête allait de 0,049 à 0,324 s
  en escalier régulier - la signature d'une sérialisation. L'instance sert désormais quatre
  requêtes en parallèle (0,071 à 0,170 s sur la même mesure). La valeur se surcharge par
  `-e PHP_CLI_SERVER_WORKERS=<n>` pour une machine plus grosse
- **Journalisation réglable sans reconstruire l'image** : `LOG_CHANNEL`, `LOG_LEVEL` et
  `LOG_DAILY_DAYS` manquaient à la liste des variables que l'entrypoint propage vers `.env`,
  si bien qu'un `docker run -e LOG_LEVEL=debug` restait sans effet. Il est de nouveau possible
  de passer temporairement une instance en mode verbeux pour diagnostiquer un incident, puis
  de revenir en arrière

### Interne

- Nouvel endpoint `GET /api/admin/lifecycle-signals`, protégé par `PROVISION_TOKEN` et donc
  **absent des instances auto-hébergées** (404 sans jeton). Il ne renvoie que des agrégats
  d'usage - étape d'installation, nombre de biens, exercices clôturés, présence de la liasse
  et du FEC - jamais un montant ni une donnée fiscale, ce qu'un test verrouille

## [1.1.3] - 2026-08-25

### Améliorations

- **Image Docker allégée** : build multi-étapes sur base Alpine, images de base épinglées
  par digest - l'image passe d'environ 1 Go à environ 230 Mo (#4, merci @cocool97)
- **Mise à jour des instances Docker** : l'image officielle étant immuable (ni `rsync`, ni
  Composer, ni npm dans le runtime), la mise à jour en place est désormais explicitement
  neutralisée et la page *Mises à jour* indique la marche à suivre - `docker pull` puis
  recréation du conteneur, les données des volumes étant conservées. La détection de
  nouvelle version reste active

### Corrections

- **Thème sombre** : de nombreux textes s'affichaient en blanc sur fond blanc, donc
  illisibles (simulateur, télédéclaration, état du système, projection, aides...). Les vues
  du panel s'appuyaient sur des variables CSS `--fi-*` que Filament 5 n'expose pas : elles
  retombaient toujours sur leur repli clair, laissant les cartes blanches en thème sombre.
  Les couleurs de l'interface proviennent maintenant d'un jeu de jetons unique, décliné en
  clair et en sombre (#5, merci @cocool97)
- **Icônes des badges** : affichées en gris au lieu de leur couleur, pour la même raison
- **Format des dates** : les champs date se saisissaient au format ISO (AAAA-MM-JJ) alors
  que les tableaux affichaient JJ/MM/AAAA. Les sélecteurs de date utilisent désormais le
  calendrier français (JJ/MM/AAAA, semaine commençant le lundi) sur tous les formulaires
  (#6, merci @cocool97)
- **État du système** : le bouton « Lancer les tests » échouait sur les installations Docker
  avec `vendor/bin/pest: not found`. Pest est une dépendance de développement, absente des
  images de production : le bouton est maintenant désactivé, avec une explication, au lieu
  de présenter l'erreur comme un échec des tests (#7, merci @cocool97)

## [1.1.2] - 2026-08-18

### Corrections

- **Sécurité** : le compte de démonstration fixe (`demo@openlmnp.fr`, mot de passe
  publié dans le README pour la démo officielle) n'est plus créé que si
  `DEMO_MODE=true`. Il était auparavant seedé sur toute installation
  self-hébergée, quel que soit ce réglage (#2)
- **Docker** : le conteneur ne démarrait plus lorsque `database/`/`storage/`
  étaient montés via des dossiers hôtes (bind-mounts) plutôt que des volumes
  nommés - correctif du `Dockerfile` et resynchronisation de `storage/` par
  l'entrypoint, compatible `podman-compose` (#3, merci @cocool97)

## [1.1.1] - 2026-08-06

### Nouveautés

- **Image Docker officielle** [`manganate06/openlmnp`](https://hub.docker.com/r/manganate06/openlmnp)
  publiée automatiquement à chaque release (amd64 + arm64) - installation en une commande,
  sans build local

### Corrections

- **Sécurité Docker** : `APP_KEY` n'est plus figée dans l'image au build (une image publiée
  aurait partagé la même clé entre toutes les installations). Elle est générée au premier
  démarrage, unique par instance, persistée dans `storage/app/.app_key` et surchargeable par
  `-e APP_KEY=...`
- `version.json` resynchronisé avec la version publiée (la notification de mise à jour
  se déclenchait à tort)

## [1.1.0] - 2026-07-20

### Nouveautés

- **Mode démo** multi-utilisateurs isolé : bac à sable éphémère par visiteur avec jeu de données réaliste, purge automatique
- **Inscription self-hosted** limitée au premier compte par défaut (`ALLOW_REGISTRATION=auto`)
- **API de provisioning** et suspension de comptes (offre cloud)
- **Page publique de politique de confidentialité**
- **Google Tag Manager** en intégration optionnelle et désactivable (mesure d'audience)

### Corrections

- Résultat fiscal : consultation des exercices clôturés sans recalcul intempestif
- Docker : resynchronisation des migrations masquées par le volume `database/`, propagation correcte des variables d'environnement runtime vers `.env`
- Script d'installation community-scripts : génère `APP_KEY` sans dépendre d'`artisan` (avant `composer install`, sur une release fraîchement extraite)

### Technique

- **189 tests Pest** (513 assertions)

## [1.0.0] - 2026-07-02

Première version stable - comptabilité LMNP (Location Meublée Non Professionnelle) au régime réel
pour les loueurs Airbnb.

### Fonctionnalités

- **Biens & activité** : biens, revenus, charges, mobilier, travaux
- **Amortissements** : décomposition par composants, base amortissable, quote-part usage mixte, prorata temporis
- **Emprunts** : tableau d'amortissement, capital restant dû, intérêts déductibles
- **Résultat fiscal** : calcul réel, plafonnement des amortissements, quote-part des charges
- **Comparateur micro-BIC / réel**
- **Imports** : CSV Airbnb (EN/FR) et relevés bancaires (doublons, format européen)
- **Exports** : FEC (18 colonnes) et liasse fiscale PDF (2031/2033)
- **Simulateur & projection** pluriannuelle
- **Serveur MCP** intégré (assistants IA : Claude Desktop, Claude Code...)

### Déploiement

- Image **Docker** + `docker-compose`
- Script **LXC Proxmox** (style community-scripts) avec mot de passe admin aléatoire à l'installation

### Technique

- Laravel 13 · Filament 5 · PHP 8.3+ · SQLite · DomPDF · Maatwebsite/Excel
- **102 tests Pest** (266 assertions)
- Licence **AGPLv3**
