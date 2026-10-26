# Changelog

Toutes les évolutions notables d'OpenLMNP. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/).

## [2.0.0-beta.1] - 2026-10-26

> **Version de test, à installer seulement si vous le choisissez.** Elle n'est pas proposée
> comme mise à jour par l'application, et l'image Docker `latest` reste en 1.6.11 : pour
> l'essayer, demandez explicitement l'image `manganate06/openlmnp:2.0.0-beta.1`.
>
> **Avant de l'installer**
> - Faites une sauvegarde complète de votre instance (base de données et dossier `storage/`).
> - Le **coffre-fort des justificatifs** chiffre les fichiers de l'utilisateur qui l'active.
>   Revenir ensuite à la 1.6 rend ses justificatifs illisibles par l'application : n'activez
>   pas le coffre sur une instance que vous pourriez devoir ramener en 1.6, ou déchiffrez-les
>   d'abord (`php artisan openlmnp:vault-migrate <email> --decrypt`). Sans coffre activé, le retour
>   en 1.6 fonctionne : la 2.0 ne fait qu'ajouter des tables et des colonnes.
> - L'**intelligence artificielle** reste désactivée (`AI_ENABLED=false`) tant que vous ne
>   l'avez pas configurée avec votre propre clé.
>
> **Revenir à la 1.6.11** (installation Docker) : relancez l'image `manganate06/openlmnp:1.6.11`
> sur les mêmes volumes, sans restaurer la base. Éprouvé aller-retour (2.0 → 1.6.11 → 2.0) :
> recettes et charges saisies d'un côté comme de l'autre sont conservées. Pendant que vous
> êtes en 1.6.11 :
> - la **part d'usage des charges** est ignorée : ces charges sont déduites à 100 %, et un
>   résultat recalculé ou une liasse produite en 1.6.11 est faux pour elles ;
> - l'**indivision**, les **dossiers liés** et l'historique de l'**IA** ne s'affichent plus ;
> - les justificatifs d'un **coffre-fort** activé sont illisibles (voir plus haut).
>
> Remonter ensuite en 2.0 fait tout réapparaître : la part d'usage et l'indivision sont
> intactes, et les résultats se recalculent justes.
>
> Le détail des changements est celui de la 2.0.0 ci-dessous. Vos retours sont les
> bienvenus dans les issues du dépôt.

## [2.0.0] - NON PUBLIÉE

> Version majeure : elle réunit le **coffre-fort des justificatifs** (d'abord prévu seul en
> 1.7.0, jamais publié) et le **module d'intelligence artificielle**. Les deux parties restent
> séparées ci-dessous : l'analyse de justificatifs ne relève pas du coffre-fort. L'IA est
> **désactivée par défaut** (`AI_ENABLED=false`) ; aucune donnée ne sort d'une instance qui ne
> l'a pas configurée. **Aucun tag définitif n'est posé** tant que le coffre-fort n'a pas été éprouvé à la
> main : c'est une fonctionnalité dont l'échec se paie en fichiers définitivement illisibles.

### Intelligence artificielle

#### Nouveautés

- **Signaler une mauvaise réponse de l'assistant, conversation jointe si vous le voulez.**
  Après un pouce vers le bas, une case décochée par défaut transmet la conversation aux
  administrateurs du service, masquée avant l'enregistrement : montants, noms et adresses
  des biens, voyageurs, banques, libellés, nom et e-mail deviennent `[montant]`, `[bien]`…,
  et aucune donnée lue par l'assistant n'est jointe. « Voir ce qui sera envoyé » affiche le
  texte exact. Lisible dans *Administration › IA*, supprimé après 90 jours.
- **Budget quotidien de l'instance** (`AI_DAILY_BUDGET_USD`, en dollars, `0` = désactivé) :
  les quotas bornent chaque compte, ce plafond borne la facture de tous les comptes réunis.
  Atteint, l'IA se met en pause jusqu'au lendemain sur la clé de l'instance ; une clé
  personnelle n'est pas concernée.
- **`php artisan ai:eval-chat`** éprouve aussi les consignes cachées dans les données
  (nom de voyageur, libellé de charge, nom de bien), sur un compte temporaire créé puis
  supprimé par la commande ; `--repeat=N` rejoue chaque cas.
- **Les relevés annuels de plateforme sont reconnus et créent vos recettes.** Déposez le
  rapport de revenus d'Airbnb, Booking ou Abritel dans *Importer un justificatif* : l'analyse
  en tire **une recette par mois loué**, au montant **brut**, la commission de la plateforme
  étant enregistrée à part et déduite de votre résultat. Rien n'est réparti au prorata — le
  relevé publie déjà, mois par mois, un revenu brut et un total versé, et leur écart est la
  retenue réelle de ce mois-là, régularisations comprises.
  Les mois sans location ne créent aucune ligne, et la taxe de séjour collectée par la
  plateforme n'est répartie sur aucun mois : elle n'entre pas dans vos revenus bruts.
  ⚠️ **N'importez pas à la fois le relevé annuel et le CSV** : ce sont les mêmes euros sous
  deux mailles différentes. L'écran vous prévient et décoche tout si l'exercice porte déjà des
  recettes

- **Choix du fournisseur d'IA** (`AI_PROVIDER`) : OpenRouter par défaut, mais aussi
  Anthropic et Google Gemini **en protocole natif** — ils reçoivent alors vos PDF tels
  quels — OpenAI, ou tout serveur compatible OpenAI installé chez vous. Les identifiants de modèles ne sont pas portables d'un fournisseur à l'autre :
  laissez `AI_MODEL_*` vide pour prendre ceux du fournisseur configuré. Un
  `AI_PROVIDER` inconnu fait échouer les fonctions d'IA avec un message explicite,
  sans retomber en silence sur OpenRouter
- **« Se connecter avec OpenRouter »** dans *Mon profil* : plus besoin de créer une
  clé et de la copier-coller. Le bouton vous redirige, vous autorisez, et l'instance
  reçoit une clé qui vous appartient — l'inscription est proposée si vous n'avez pas
  encore de compte. Aucune inscription d'application n'est requise, et votre instance
  n'a pas besoin d'être joignable depuis Internet. Désactivable par
  `AI_OAUTH_ENABLED=false` (⚠️ incompatible avec `SESSION_SAME_SITE=strict`)
- **`php artisan ai:check-provider`** : éprouve un fournisseur par de vrais appels — clé,
  conversation en flux, JSON contraint, pièce jointe — et rend un code d'échec si l'un des
  contrôles ne passe pas. Un catalogue peut annoncer une capacité qu'il n'honore pas ; c'est
  le seul moyen de le savoir avant de confier ses justificatifs à un service
- **Mistral AI** rejoint les fournisseurs : le seul de la liste dont les justificatifs
  restent en Europe (`AI_PROVIDER=mistral`)
- Le choix du modèle, dans *Mon profil*, devient une **liste de modèles retenus** au lieu
  d'un identifiant à saisir à la main. Chacun porte le **drapeau du pays de son éditeur**,
  et la ligne « Fournisseur » celui du service qui achemine la requête — les deux sont
  distincts, et un modèle européen appelé via un intermédiaire américain transite malgré
  tout par cet intermédiaire. Un modèle déjà choisi hors liste est conservé
- Le message **« plus de crédit disponible »** propose désormais le lien de recharge,
  dans l'import de justificatifs comme dans l'assistant, quand la clé est la vôtre
- `AI_MODEL_PRICES` permet de renseigner les tarifs des modèles chez un fournisseur
  qui ne renvoie pas le coût réel de l'appel — sans quoi aucun coût n'est journalisé,
  ce qui reste préférable à un chiffre inventé
- **Analyse de justificatifs par IA** (optionnelle, `AI_ENABLED=false` par défaut) :
  déposez une facture, un avis de taxe foncière, une attestation d'assurance, un
  tableau d'amortissement bancaire ou un relevé de compte, et OpenLMNP en propose
  la saisie. **Rien n'est jamais créé sans votre relecture** : un écran de
  confirmation affiche chaque champ avec un indicateur de confiance et l'extrait du
  document qui l'a rempli (« Lu : *Total TTC 143,20 €* »). Nouvelle page
  *Au quotidien → Importer un justificatif*
- Les factures de **mobilier** se découpent en une ligne amortissable par article,
  avec la durée d'amortissement déduite de la nature du bien ; les **travaux**
  reçoivent 5, 10 ou 15 ans selon leur nature
- Les **tableaux d'amortissement** de votre banque remplacent le tableau théorique :
  vos intérêts déductibles deviennent les intérêts réels (différé, assurance
  dégressive, modulation). Ces échéances sont protégées de tout recalcul automatique
- Les **relevés bancaires** proposent leurs opérations une à une, déjà classées et
  catégorisées ; les lignes déjà saisies sont détectées et décochées, et l'IBAN
  n'est jamais conservé en entier
- **Alerte doublon** : une écriture du même montant à trois jours près est signalée
  avant l'enregistrement
- Bouton **« Pré-remplir depuis ce document »** dans les formulaires de charge,
  mobilier et travaux : il ne remplit que les champs vides et indique lesquels il
  n'a pas touchés
- **API MCP** : nouvel outil `analyze_document` (45 outils au total), non destructif —
  il propose une saisie sans rien créer
- Le bouton **« Réanalyser avec le modèle renforcé »** n'apparaît que lorsqu'il peut
  servir : sous un seuil de confiance (`AI_REANALYSIS_THRESHOLD`, 85 % par défaut) et
  tant que le modèle de repli n'a pas déjà été sollicité
- Nouvelle page **Administration → Intelligence artificielle** : volumes, coût réel,
  coût moyen par document, erreurs, refus de quota et consommation par compte
- Les **PDF** sont pris en charge sur un serveur local : la couche texte est extraite
  et envoyée au modèle (`AI_PDF_TEXT_FALLBACK`). Un PDF scanné, qui n'en contient
  pas, doit toujours être déposé en image — l'application le dit explicitement
- Les photos **HEIC** (iPhone) sont acceptées : elles sont converties en JPEG **dans le
  navigateur** avant l'envoi (bibliothèque chargée uniquement à ce moment-là) — rien
  n'est décodé côté serveur
- **Lecture IA de la liasse dans l'assistant de reprise** : à l'étape 3 de
  *Reprendre un dossier existant*, une troisième méthode propose de déposer votre
  dernière liasse (PDF ou photos). Le tableau **2033-C** est lu ligne à ligne, les
  cases de report (870, 030, 028, 014, déficits 980-984) pré-remplissent l'étape 4,
  et la somme des dotations lues est confrontée à la **ligne 572** pour dire si le
  tableau a été lu en entier. **Rien n'est recopié sans votre relecture** : les
  lignes s'affichent face à votre document avec un indice de confiance chacune,
  une case absente de la liasse reste vide — jamais remplacée par un zéro — et la
  ligne des frais d'acquisition n'est pas recopiée, l'application les amortissant
  déjà à partir des frais saisis à l'étape 2. Les soldes repris de cette façon
  portent la provenance « lecture assistée » dans l'exercice, visible au contrôle
- La page *Administration → Intelligence artificielle* affiche le **service amont**
  ayant réellement servi chaque appel (colonne « Servi par ») quand la passerelle le
  communique — un même modèle peut changer de comportement selon l'hébergeur qui le
  sert, autant le savoir
- **Assistant conversationnel intégré** (onglet « Assistant » du panneau d'aide,
  désactivé par défaut avec le reste de l'IA) : répond en français aux questions sur
  l'utilisation du logiciel, en s'appuyant sur la documentation embarquée (aide
  contextuelle, FAQ, guide fiscal, outils MCP) indexée par la commande
  `ai:build-help-index`. Réponse affichée au fil de l'eau, Markdown rendu côté serveur
  avec liens limités aux pages du logiciel, suggestions par page, pouces 👍/👎.
  Refuse tout conseil fiscal ou juridique, toute action sur les données et tout
  hors-sujet ; un contrôle de sortie remplace les réponses qui s'en écartent.
  Option `ai_data_access` dans *Mon profil* (désactivée par défaut) : l'assistant peut
  alors consulter les données du compte **en lecture seule** via 16 outils MCP exécutés
  en interne, et indique ce qu'il a consulté. Sans elle, aucune donnée comptable n'est
  transmise. Quota `AI_CHAT_DAILY_QUOTA` par utilisateur, conversation jamais
  enregistrée, compteurs d'usage dans *Administration → Intelligence artificielle*.
  Validé sur 40 questions réelles (`php artisan ai:eval-chat`) : aucune question hors
  périmètre acceptée.
- **Socle d'intelligence artificielle** (désactivé par défaut, `AI_ENABLED=false`) :
  configuration `config/ai.php`, client unique vers toute API compatible OpenAI
  (OpenRouter par défaut, ou un modèle local via `AI_BASE_URL`), résolution de clé
  (clé d'instance ou clé par utilisateur), plafonds d'usage par utilisateur et
  journal d'appels. Ce socle ne fournit encore aucune fonctionnalité visible : il
  prépare l'analyse de justificatifs et l'assistant intégré
- **Mon profil** : section « Intelligence artificielle » — saisie d'une clé
  OpenRouter (chiffrée en base, jamais réaffichée), bouton de vérification, et
  option d'autorisation d'accès aux données pour le futur assistant
- Page **Politique de confidentialité** complétée d'un paragraphe détaillant ce qui
  est transmis à un service d'IA, ce qui ne l'est jamais, et comment tout désactiver

#### Modifications

- **L'écran d'import prévient les porteurs d'un coffre-fort** que l'analyse envoie le document
  **en clair** au service d'intelligence artificielle : le coffre chiffre ce qui est
  *enregistré*, il ne protège pas ce qu'on choisit de faire analyser
- La politique de confidentialité décrit le coffre-fort, ce qu'il ne couvre pas, et ce qu'il
  advient d'un document soumis à l'analyse

- La page **Politique de confidentialité** ne dit plus à l'indicatif ce que fait un
  tiers : la consigne de non-conservation envoyée à OpenRouter est une préférence
  transmise à un intermédiaire, pas un engagement que nous pourrions tenir à sa
  place — et les autres fournisseurs ne la reçoivent même pas
- Un **serveur compatible OpenAI installé chez vous** ne reçoit plus les champs
  propriétaires d'OpenRouter (politique de conservation, plugin de lecture de PDF,
  en-têtes d'attribution), qu'il ignorait de toute façon. Aucun changement pour une
  installation OpenRouter, dont la requête reste identique au champ près
- L'écran **Administration → Intelligence artificielle** affiche les réglages
  *effectifs* (fournisseur, adresse, identifiants de modèles réellement utilisés) et
  non plus les variables brutes, qui apparaîtraient vides là où l'application appelle
  bel et bien quelque chose
- Les libellés de *Mon profil* nomment le fournisseur configuré au lieu d'écrire
  « OpenRouter » en dur

#### Corrections

- **Un relevé bancaire ne pouvait pas être confirmé depuis l'écran d'import.** La relecture
  affichait les champs d'une facture de charge — dont quatre obligatoires qu'aucune valeur de
  relevé ne remplit — et la validation échouait systématiquement au moment d'enregistrer
- **La TVA collectée était perdue sur les recettes issues d'un relevé bancaire.** Le taux était
  forcé à zéro quel que soit le régime du bien : sur un bien assujetti, la TVA collectée
  ressortait nulle dans la déclaration, sans aucun message

- **Le réglage « 100 % dédié au bien loué » était écrasé quand on changeait le type de
  document.** Un montant rectifié, une date corrigée ou cet interrupteur basculé
  disparaissaient en silence, remplacés par la proposition du modèle — précisément les
  gestes qu'on demande de faire à la relecture. Les corrections manuelles survivent
  désormais, et seuls les champs que le nouveau type ne connaît pas sont abandonnés
- **Sur une facture de mobilier à plusieurs articles, les lignes créées étaient marquées
  « partagées » par défaut** au lieu de « dédiées », contrairement à ce que fait la même
  fonctionnalité sur une facture à article unique. La quote-part de surface s'appliquait
  donc à tort, rabotant la dotation d'amortissement pour toute la durée du plan
- **Sur un relevé bancaire, le caractère dédié de chaque ligne n'était pas modifiable.**
  La valeur était déduite du bien et appliquée sans que rien ne l'affiche : c'était le
  seul chemin d'analyse où la proposition ne pouvait pas être corrigée

- **Un justificatif rangé par l'analyse IA n'était pas chiffré.** Le fichier retenu à l'issue
  d'une analyse était recopié tel quel sous vos justificatifs, sans passer par le coffre-fort :
  une pièce **en clair** posée au milieu de pièces scellées, sur un compte dont le propriétaire
  croit légitimement tout avoir chiffré. C'est le seul fichier qu'il n'a pas déposé lui-même,
  donc celui qu'il ne pensera jamais à vérifier. Le chiffrement a désormais **un seul point
  d'écriture**, par lequel passent le dépôt manuel, l'API MCP et l'analyse
- **Activer son coffre-fort pendant une analyse en cours chiffrait le fichier en relecture.**
  Les deux répertoires de transit (analyse IA, imports CSV) sont exclus du chiffrement : leur
  contenu est en clair à dessein, le temps d'une relecture, et supprimé dès la validation
- **« Pré-remplir depuis ce document » échouait systématiquement coffre-fort actif**, avec un
  message trompeur (« Format non pris en charge »). L'action provoquait son propre échec : elle
  déclenchait l'enregistrement — donc le chiffrement — du fichier avant d'avoir pu le lire
- **Un justificatif déjà scellé soumis à l'analyse annonçait un « format non pris en charge ».**
  Le format n'y était pour rien : le serveur ne peut plus lire un fichier scellé, et c'est
  exactement ce que le coffre-fort garantit. Le refus le dit désormais, et indique quoi faire
- **Un bien créé sans surface totale amortissait silencieusement à zéro.**
  `Property::quota_share` (surface louée ÷ surface totale) rendait `'0'` quand la surface
  totale était absente ou nulle — une valeur qui multiplie la base amortissable ET quasiment
  toutes les charges au prorata, sans qu'aucun montant n'ait l'air faux : un zéro qui ressemble
  exactement à un résultat fiscal exact. L'outil MCP `create_property` retombait sur cette
  ombre dès que l'appelant (typiquement un assistant IA) omettait `total_area`/`rented_area`.
  La surface totale est désormais refusée à la création comme à la mise à jour (formulaire,
  assistant de premier lancement, outils MCP `create_property`/`update_property`) si elle est
  absente, nulle ou inférieure à la surface louée, et l'accesseur `quota_share` REFUSE
  maintenant de calculer plutôt que de rendre ce zéro en silence. Le formulaire du bien et
  l'assistant de premier lancement acceptent aussi de saisir directement la **quote-part**
  plutôt que la surface louée en m² — l'autre valeur se calcule et s'affiche en direct
- **La lecture IA d'une liasse recopiait le terrain comme composant amortissable.** Le
  tableau 2033-C porte une ligne « Terrains » (case 420) qui n'a jamais de durée ni de
  dotation imprimées, un terrain ne s'amortissant JAMAIS. Faute de durée, l'assistant lui en
  complétait une par défaut (10 ans) et le terrain se mettait à générer une dotation fictive —
  constaté lors d'un test de reprise (ligne « Terrains », case 420 du 2033-C).
  La ligne est désormais refusée à l'import, avec un message qui l'explique, et
  `openlmnp:repair-land-components` (rapport par défaut, `--fix` pour agir) supprime les
  composants déjà créés sur les comptes existants et recalcule les exercices non clôturés.
  À cette occasion, la convention qui régit une base amortissable IMPORTÉE d'une liasse pour
  un bien partiellement loué est désormais écrite en docblock (`Property`,
  `RepriseDossier::applyLiasse()`) : le 2033-C d'un tel bien porte déjà la part
  professionnelle de l'immobilisation, jamais la valeur du bien entier — une base `manual`
  n'est donc jamais remultipliée par la quote-part de surface louée. Un test verrouille
  désormais cette convention avec un dossier de test (base 152 340 €, dotation 5 790 €,
  quote-part 26,09 %) : la dotation lue reste bien 5 790 €, jamais divisée
  par la quote-part une seconde fois (~1 511 € sinon — l'écart redouté)
- **Import Airbnb, format « Historique des transactions » : les recettes étaient
  comptées deux fois, et la commission perdue.** Deux défauts distincts, une seule
  cause : la colonne `Type` du fichier (`Payout`, `Réservation`, `Régularisation`,
  `Versement de résolution`) n'était filtrée nulle part. Chaque ligne `Payout` (le
  virement bancaire qui RÈGLE une ou plusieurs `Réservation` déjà détaillées) était
  réimportée comme une recette à part entière, doublant le chiffre d'affaires déclaré
  (≈ 84 000 € au lieu de ≈ 42 000 € sur un compte réel de test). Les lignes `Payout`
  sont désormais explicitement écartées — visibles dans l'aperçu, avec leur raison —
  et jamais silencieusement ignorées ni comptées. Une ligne `Versement de résolution`
  (remboursement AirCover) porte le code de confirmation de la réservation d'origine :
  la détection de doublons, qui ne comparait QUE ce code, la prenait à tort pour une
  réimportation et la perdait silencieusement — elle compare désormais aussi le
  montant et la date, ce qui préserve au passage les réservations légitimement scindées
  en acompte puis solde sous le même code. Enfin, la colonne de commission réelle de cet
  export s'appelle « Frais de service » (sans « hôte ») : absente de la liste des
  libellés reconnus, elle faisait annoncer à tort le format « Réservations » (sans
  détail de commission) sur un fichier qui la contient pourtant — la commission
  n'atterrissait dans aucune écriture
- **Un CSV anglo-saxon (Airbnb ou import générique) pouvait lire des dates à l'envers,
  parfois de deux ans.** La garde qui devait rejeter un format de date invalide testait
  le résultat APRÈS que Carbon ait silencieusement « reporté » un mois hors bornes
  (`30/04/2026` lu jour-mois-année devenait juin 2028, une valeur en apparence valide) :
  elle ne pouvait matériellement rien attraper. Elle est remplacée par un aller-retour
  strict, et une date du fichier qui ne peut être lue QUE mois-jour-année fait désormais
  basculer TOUT le fichier dans ce sens — y compris les dates ambiguës des douze premiers
  jours du mois, qu'aucun aller-retour ne peut départager isolément. Un séjour dont la
  date de fin précède la date de début est maintenant refusé plutôt qu'importé tel quel
- **Un justificatif chiffré était impossible à consulter, coffre-fort actif.** Le clic sur
  « ouvrir » ou « télécharger » échouait silencieusement (l'URL appelée valait
  `/expenses/1/[object Object]`) : rien ne relayait ce clic vers le déchiffrement navigateur,
  et Filament retombait sur un lien qui n'avait jamais reçu d'adresse valable. Corrigé à deux
  endroits : le lien produit pour un fichier chiffré porte désormais son nom et pointe vers la
  route de service, et cette route accepte le paramètre `raw` ajouté après coup par le
  déchiffrement navigateur (il invalidait la signature de l'URL, donc l'aurait de toute façon
  fait échouer même une fois le lien réparé)

- **Justificatifs** : l'aperçu d'un document affichait « Cette page a été bloquée par
  Chrome ». La politique de sécurité envoyée avec le fichier imposait un bac à sable
  complet, qui empêchait le navigateur d'ouvrir sa visionneuse PDF. Le fichier est
  désormais servi avec un type imposé d'après son extension — un document piégé ne peut
  donc pas s'exécuter — et la politique n'a plus besoin d'être aussi radicale. Les images
  s'affichent directement, sans cadre
- **Pièces jointes par l'API MCP** : le contenu réel du fichier est vérifié, et plus
  seulement son extension
- **Docker** : `docker-entrypoint.sh` ne propageait pas `TELEMETRY_ENABLED` ni
  `TELEMETRY_INSTALL_TYPE` vers `.env` — un `-e TELEMETRY_ENABLED=false` au
  `docker run` restait donc sans effet. Un test échoue désormais si une variable
  documentée manque à cette liste

### Coffre-fort des justificatifs et autres évolutions

#### Ajouts

- **Dossier en indivision et annexe 2031 bis.** Un bien détenu à plusieurs sans société
  dépose une liasse au nom de l'indivision, dont le résultat se répartit entre indivisaires.
  Dans *Mon profil*, rubrique **Indivision**, déclarez le dossier comme tel et indiquez chaque
  indivisaire avec sa quote-part (total de 100 %). La liasse PDF et l'écran *Télédéclaration*
  ajoutent alors la **2031 bis-SD, cadre E**, et donnent à chacun le montant à reporter sur sa
  propre 2042-C-PRO. La somme des parts égale exactement le résultat, au centime près, et un
  contrôle signale une répartition incomplète — et, une fois celle-ci complète, un second
  contrôle (sans bloquer) rappelle les indivisaires dont l'adresse ou la date de naissance
  manquent encore, demandées sur la 2031 bis. Saisissez le bien, les emprunts et les charges
  pour leur montant **total** : la quote-part ne s'applique qu'au résultat. Le micro-BIC d'une
  indivision suit les mêmes règles que celui d'un dossier individuel, dans le tableau de bord, le
  simulateur, la projection, l'assistant d'exercice et les outils MCP (une indivision gérée en
  commun peut, en droit, former une société de fait exclue du micro-BIC — Conseil d'État,
  14/11/2025, n° 495516 — mais le logiciel ne tranche pas cette question de fait : faites
  valider votre situation par un expert-comptable). `generate_tax_return` annonce la 2031 bis-SD
  pour ce dossier, et `get_fiscal_year` expose la répartition
- **Indivision : présentation resserrée du profil et de la télédéclaration.** La fiche de
  chaque indivisaire se replie et affiche en tête son nom, sa quote-part et un badge qui dit ce
  qui manque pour la 2031 bis ; le total des quotes-parts se double d'une jauge pendant la
  saisie. Le cadre E de la 2031 bis-SD (écran et PDF) reprend les colonnes du formulaire papier
  et se termine par une ligne de total ; la 2042-C-PRO devient une carte par indivisaire (case,
  montant, bouton Copier). Le contrôle qui rappelle une adresse ou une naissance manquante nomme
  désormais chaque indivisaire concerné
- **Une carte « Nouveauté » sur l'écran Quoi de neuf**, fermable et mémorisée par version :
  elle revient à la prochaine release qui a quelque chose à annoncer, et adapte son contenu
  selon que les dossiers liés sont actifs sur l'instance
- **La vérification Sirene donne la forme juridique.** Le bouton « Vérifier dans Sirene » affiche
  désormais la forme juridique du SIREN, et prévient quand elle ne correspond pas au dossier : un
  dossier en indivision qui porte le SIREN personnel d'un indivisaire, ou un SIREN d'indivision
  sans la rubrique « Indivision » activée
- **L'adresse d'un bien se complète toute seule.** Sur les trois écrans qui en demandent une
  — fiche d'un bien, premier lancement, reprise de dossier — commencez à taper : les
  propositions de la [Base Adresse Nationale](https://adresse.data.gouv.fr) apparaissent, et
  en choisir une remplit d'un coup l'**adresse**, le **code postal** et la **commune**. Fini
  le code postal qui ne correspond pas à la ville saisie juste à côté.
  **La saisie manuelle reste entière** : aucune validation ne dépend de ce service, et un
  service injoignable, lent ou en erreur se traduit par une absence de propositions — jamais
  par un formulaire qu'on ne peut plus valider.
  ⚠️ C'est le **second appel sortant** du logiciel, après l'estimation DVF, et le plus
  bavard : il révèle le début d'adresse en cours de frappe. Rien ne part avant trois
  caractères ni avant 300 ms sans frappe, le débit est limité par compte, et
  `ADDRESS_LOOKUP_ENABLED=false` coupe tout — le script n'est alors même pas injecté
  dans les pages. Le service interrogé est l'API Adresse sur la Géoplateforme de l'IGN
  (`data.geopf.fr/geocodage`), l'adresse que référence data.gouv.fr.

- **Une variable d'environnement contenant un espace empêchait l'instance de démarrer.**
  L'entrypoint Docker recopiait les valeurs passées en `-e` vers `.env` **sans guillemets** :
  un `APP_NAME` ou un `MAIL_FROM_NAME` avec un espace — « OpenLMNP Support » suffit — cassait
  le parsing du fichier, et le conteneur repartait en boucle sans qu'aucun message ne
  l'explique côté déploiement

- **Inscription sur invitation** (`ALLOW_REGISTRATION=invite`). Personne ne peut créer de
  compte sans un lien nominatif, émis depuis *Administration → Invitations* ou par
  `php artisan openlmnp:invite`. Le lien est **à usage unique**, il expire
  (`INVITATION_TTL_DAYS`, 14 jours par défaut), il se relance et se révoque
  individuellement, et l'adresse est verrouillée sur le formulaire — une invitation émise
  pour quelqu'un ne peut pas servir à créer le compte d'un autre.
  Seule une empreinte du jeton est enregistrée : le vol d'une sauvegarde ne livre aucun
  lien utilisable. La contrepartie est que le lien ne s'affiche qu'une fois — il est donc
  **toujours imprimé**, y compris quand l'envoi du courriel échoue, pour qu'une instance
  sans SMTP reste utilisable

- **En démonstration, l'écran qui suit « J'aime bien » ne parle plus de GitHub.** Il proposait
  de mettre une étoile, de faire un don ou d'ouvrir une discussion : trois gestes qui ne veulent
  rien dire pour quelqu'un qui découvre le logiciel et ne l'a pas encore adopté. À leur place,
  une proposition qui correspond à ce moment-là : **garder son travail 7 jours de plus**, sans
  carte bancaire. **Une instance auto-hébergée garde l'écran d'origine** — les gestes GitHub y
  ont tout leur sens, et le basculement est verrouillé dans les deux sens.
- **« Qu'est-ce qui vous manque pour franchir le pas ? »** — six cases à cocher sous le
  formulaire de retour, en démonstration : reprendre une comptabilité déjà commencée ailleurs,
  le coût de l'abonnement, l'absence d'un professionnel qui engage sa responsabilité, le manque
  d'avis, l'impossibilité de télétransmettre, une fonctionnalité manquante. Chaque case est
  enregistrée **dès le clic** : cocher puis refermer sans rien envoyer suffit à nous dire
  quelque chose. Seuls les identifiants sont conservés, jamais les libellés — et parce que
  c'est une collecte et non une préférence d'affichage, **la page de confidentialité le dit
  désormais explicitement**.
- Le formulaire de prolongation d'un bac à sable propose une **case facultative** pour recevoir
  les rappels d'échéances fiscales. Elle est distincte du consentement au-dessus, qui ne sert
  qu'à renvoyer le lien de reprise, et elle ne vaut pas inscription : un e-mail de confirmation
  part d'abord, et rien ne suit tant qu'il n'a pas été cliqué. `DEMO_LEAD_URL` est **vide par
  défaut** : sans elle la case ne s'affiche pas et rien ne sort de l'instance.
- **Un coffre-fort pour vos justificatifs.** Le contenu des fichiers que vous déposez —
  justificatifs comptables et photos de biens — est chiffré avec une clé dérivée d'une phrase
  de passe que vous seul connaissez. Une copie du disque, d'une sauvegarde ou de la réplique
  de secours ne contient alors rien de lisible. La fonctionnalité est **optionnelle et
  réversible** : tant que vous ne l'activez pas, rien ne change ; si vous la désactivez, tout
  redevient lisible à l'octet près.

  ⚠️ **Perdre la phrase de passe, c'est perdre les fichiers.** Elle n'est enregistrée nulle
  part, ni en clair ni sous forme dérivée : ni l'hébergeur de l'instance ni personne d'autre
  ne peut restituer vos justificatifs, et il n'existe aucune procédure de récupération. Le
  mot de passe de connexion et la phrase de passe sont **deux choses distinctes** — une
  réinitialisation de mot de passe vous rend votre compte, pas vos fichiers.

  La clé ne vit que le temps de la visite. Coffre verrouillé, plusieurs symptômes ressemblent
  à des pannes sans en être : photo de bien absente, champs de dépôt grisés, export groupé
  refusé pour ne pas produire une archive silencieusement amputée. L'aide contextuelle de la
  page les énumère, avec ce que le coffre **ne** protège pas — les montants et libellés
  restent en clair en base, et le nom d'un fichier en raconte souvent l'essentiel.

- Changer de phrase de passe est **instantané**, quel que soit le nombre de justificatifs :
  aucun fichier n'est réécrit, seule la clé est rescellée.

- **Logement indépendant dans votre résidence principale.** Le booléen « fait partie de ma
  résidence principale » est remplacé par une **situation du logement** à trois valeurs :
  logement distinct, une ou plusieurs pièces de la résidence principale, ou **logement
  indépendant** dans la résidence principale ou sur la même propriété (studio avec entrée
  propre, rez-de-chaussée aménagé, dépendance). Ce dernier cas est, pour la loi, un meublé
  **distinct** de la résidence principale : pas de limite de 120 jours, pas d'art. 35 bis (ses
  exonérations ne couvrent qu'une partie de la résidence principale elle-même), CFE due en
  principe, taxe d'habitation sur les résidences secondaires (THRS) si le foyer l'occupe une
  partie de l'année. L'ancien champ est conservé et resynchronisé automatiquement pour les
  intégrations tierces (outils MCP compris), mais plus aucun calcul ne s'y fie : la quote-part
  de surface s'applique désormais **uniformément**, quelle que soit la situation (elle ne
  regardait que `is_primary_residence` jusqu'au 2026-09-08 pour les frais de notaire et
  d'agence, incohérent avec travaux et mobilier). L'estimation de valeur vénale (DVF) compare
  alors le **bâtiment entier** et non le type du seul logement loué, la surface totale portant
  celle de la propriété entière. L'exemption de décence énergétique des meublés de tourisme
  (réservée à la résidence principale au sens strict) ne s'applique qu'aux pièces de la
  résidence principale, jamais à un logement indépendant.
- **Classement micro-BIC du bien** (`tourism_classification`) : non classé, classé, ou bail
  meublé longue durée — dérivé par défaut du type de location, modifiable. Le simulateur, le
  tableau de bord, l'assistant de clôture d'exercice et la projection pluriannuelle lisent
  désormais les **taux et plafonds officiels** (CGI art. 50-0) depuis une source unique plutôt
  que des 50 %/30 % recopiés en dur à six endroits, et **vérifient le plafond de chiffre
  d'affaires** : au-delà, le micro-BIC est affiché « non disponible » avec son motif plutôt
  qu'un montant que la loi interdirait en réalité.
- **Emprunt dédié** (`is_dedicated` sur un emprunt) : un prêt qui finance exclusivement le
  logement loué (ex : prêt travaux d'un studio indépendant, distinct de celui de la résidence
  principale) se déduit désormais à 100 %, sans quote-part — au lieu d'être proratisé comme un
  emprunt qui finance l'achat de toute la propriété.

#### Corrections

- **Un justificatif déposé coffre-fort actif ne s'ouvrait pas.** Les pièces déposées après
  l'activation se déchiffrent dans votre navigateur : la fenêtre de déverrouillage affichait
  une erreur technique, puis un faux manque de mémoire, et le document restait illisible.
  Elles s'ouvrent désormais normalement, avec le bon type (un PDF s'affiche au lieu de se
  télécharger)

- **Désactiver le coffre-fort laissait ces mêmes pièces chiffrées**, avec le message
  « fichier(s) non traités », et le coffre restait actif. La désactivation demande désormais
  votre phrase de passe, qui sert uniquement à ce déchiffrement et n'est conservée nulle
  part : tous les justificatifs redeviennent lisibles à l'identique. Même correction pour
  `openlmnp:vault-migrate --decrypt`

- **La dernière année d'un plan d'amortissement manquait.** Quand un bien, un meuble ou des
  travaux sont mis en service en cours d'année, la première annuité est réduite au prorata
  des jours, et la part non déduite se reprend l'année qui suit la fin nominale du plan. Le
  logiciel arrêtait le plan un an trop tôt : un meuble mis en service le 1er juillet sur
  10 ans n'amortissait que 95 % de sa valeur. Le plan dure désormais un exercice de plus et
  le cumul des annuités retombe exactement sur la base. Les années précédentes ne changent
  pas ; un exercice déjà clôturé qui aurait dû porter ce rattrapage n'est pas modifié

- **Deux adresses rendaient une erreur 500 à un visiteur non connecté**, au lieu de la page
  de connexion : l'autocomplétion d'adresse et le matériel de clé du coffre-fort, ouverts
  dans un navigateur. Leur garde renvoyait vers une route de connexion que le logiciel
  n'expose pas.

- **Le dépôt de la liasse, à l'étape 3 de la reprise, était introuvable.** C'était le seul
  champ de fichier « nu » du logiciel — partout ailleurs on passe par le composant de
  Filament — et le navigateur le rendait en « Choisir un fichier / Aucun fichier choisi »,
  minuscule, au milieu d'un écran qui demande par ailleurs un consentement. Il devient un
  **grand cadre en pointillés** sur toute la largeur, avec son icône, son libellé et son
  bouton, qui accepte le **glisser-déposer** et affiche le **nom du fichier retenu**.
  Le champ de fichier reste dans le parcours de **tabulation** (masqué par l'opacité, jamais
  par `display: none`) et le clic n'a besoin d'**aucun JavaScript** : le glisser-déposer est
  celui du navigateur, le script n'ajoute que le surlignage du cadre

- **L'attente de la lecture de la liasse annonçait des étapes qu'elle ne pouvait pas
  connaître.** « Document transmis au modèle », « Tableaux 2033-A à 2033-D repérés »,
  « Lignes extraites » s'allumaient sur une **minuterie de 4 secondes**, sans aucun rapport
  avec le serveur — et la quatrième ligne ne s'allumait jamais. L'attente se joue désormais
  dans une **fenêtre centrée**, qui affiche une durée indicative, des **astuces** sur la
  reprise, et dit franchement qu'elle ne connaît pas l'avancement. Elle se **masque** (bouton
  ou touche Échap) sans annuler la lecture, prévient quand c'est plus long que d'habitude, et
  le motif d'un refus s'affiche maintenant en encart au lieu d'une ligne de 11 px sous le champ

- **L'aperçu de la liasse, sur un demi-écran, était illisible.** L'écran de relecture posait
  le document à gauche et la lecture à droite, pour une comparaison œil à œil qui n'existait
  pas. Les **lignes du 2033-C** et les **cases de report** passent en deux colonnes en haut,
  et **votre liasse occupe toute la largeur** en dessous. Puisque la comparaison se fait
  désormais par le défilement, l'aperçu devient exploitable : **pagination**, **zoom**,
  ouverture dans un onglet, et une hauteur qui montre une **page entière** au lieu des 420 px
  d'avant — sans dévorer l'écran sur mobile

- **« La ventilation dépasse la base amortissable » disait quoi faire, sans offrir aucun
  moyen de le faire.** Le refus était juste — la recopie est rejetée en bloc, rien n'est
  écrit à moitié — mais il tenait dans une ligne de 11 px qui renvoyait à l'étape 2. Il ouvre
  désormais une **fenêtre avec les trois remèdes** : corriger le **prix d'acquisition** ou la
  **part du terrain** sans quitter l'écran, **décocher** les lignes lues qui ne portent pas
  sur le bâti (le total se recalcule à chaque décochage), ou revenir à l'étape 2

- **⚠️ Enregistrer un plan d'amortissement en SUPPRIMAIT les composants absents, sans un
  mot.** L'écriture est une réconciliation : tout composant qui ne figure pas dans le lot
  enregistré disparaît, avec sa base, sa durée et son cumul repris. Le remplacement est
  **voulu** — c'est ce qui permet de recopier le plan d'un cabinet par-dessus la ventilation
  par défaut — mais rien ne le disait, et l'écran trompait activement : les lignes du
  catalogue s'affichent à « 0,00 € » exactement comme un composant réel. Enregistrer avec une
  seule ligne renseignée effaçait donc les autres (constaté : 6 composants ramenés à 1). Le
  seul bandeau existant parlait de *ventilation*, jamais de *suppression*.
  Désormais : un **encart rouge nomme et compte** ce qui va disparaître **avant** le clic,
  une **confirmation** les nomme une seconde fois, la **notification** d'enregistrement les
  liste, et les **lignes de gabarit** (proposées par le catalogue, jamais enregistrées) se
  distinguent des composants réels par un repère **○**, une légende et un intitulé accessible

- **« Enregistrer mon plan » flottait seul dans un bloc à lui.** Le bouton ferme maintenant le
  bloc du mode courant — la carte du tableau en *Montants*, celle du bas de colonne en
  *Ventilation* — et il y côtoie l'avertissement de ce qu'il va supprimer

- **L'écran de correspondance des colonnes de l'import CSV ne montrait jamais le
  fichier.** Les lignes affichées étaient construites *après* application de la
  correspondance, et les intitulés réels du fichier ne servaient qu'à la deviner : on
  demandait de vérifier une correspondance sans rien donner à vérifier. L'écran affiche
  désormais, **avant** le bloc de correspondance, les **dix premières lignes brutes** du
  fichier avec ses propres intitulés de colonnes — et, sous chaque liste déroulante, un
  **exemple de valeur** tiré de la colonne choisie. Les deux tableaux se font défiler
  horizontalement, première colonne figée, au lieu d'être tronqués sans un mot

- **Les trois compteurs de l'aperçu ne pouvaient pas être vrais en même temps.** Sur un
  fichier de 121 lignes, l'écran annonçait « 60 à importer / 61 doublons / 20 lignes en
  erreur », soit 141 lignes. Deux erreurs superposées : « à importer » valait
  *total − doublons*, donc y comptait les lignes en erreur et les lignes vides ; et
  « lignes en erreur » affichait la taille d'une liste **tronquée à vingt**, c'est-à-dire
  un plafond déguisé en décompte. Les compteurs se partagent maintenant exactement le
  total — à importer, doublons, en erreur, vides ignorées — et la liste d'exemples
  indique quand elle est tronquée. La notification de fin d'import cesse elle aussi
  d'appeler « illisibles » des lignes simplement vides

- **L'import CSV proposait « Charges » même en arrivant depuis *Recettes*.** La nature des
  lignes était codée en dur ; elle suit désormais l'écran d'où l'on part — *Recettes*,
  *Charges*, *Mobilier*, *Travaux* — et reste modifiable. La méprise était silencieuse et
  coûteuse : importer des loyers en charges **inverse le signe du résultat fiscal**, et
  aucun montant n'y paraît anormal.
  Un second filet complète la présélection : quand les intitulés du fichier ressemblent
  nettement à une autre nature, l'aperçu **pose la question** — « ce fichier ressemble à
  des recettes, vous avez choisi Charges ». Il ne bloque rien et ne décide rien : la
  présélection vient de l'écran appelant, jamais d'une devinette sur le contenu

- **Le tableau des montants d'amortissement retrouve son rythme vertical.** La pastille
  « saisi », posée sous le nom du composant, n'allongeait que les lignes qui la portaient :
  le tableau alternait lignes hautes et lignes basses selon une information sans rapport
  avec la mise en page. L'information passe maintenant par la **couleur de la ligne**, qui
  ne prend aucune hauteur — accompagnée d'un repère **✎** devant le nom et d'une légende,
  parce qu'une couleur seule ne se lit ni en noir et blanc, ni par un daltonien.
  Même traitement pour « déjà portée par l'application », dans le tableau de lecture de
  liasse, qui souffrait du même défaut

- **Les dates de la reprise de dossier s'ouvrent enfin sur un calendrier.** « Depuis quand
  louez-vous ce bien ? » et « Date d'acquisition » étaient des champs de texte, avec un
  gabarit `jj/mm/aaaa` pour toute aide — alors que les dates de début de composant, deux
  étapes plus loin dans le même assistant, offraient bien le calendrier du navigateur. La
  page mélangeait deux conventions ; elle n'en a plus qu'une. Une date déjà enregistrée
  s'affiche désormais dans le champ au lieu de le laisser paraître vide, et la saisie
  française reste lue correctement sur un navigateur qui ne connaît pas le sélecteur

- **« Vos 1 exercice seront supprimés »** — le bandeau d'expiration de la démonstration
  accordait le nom mais pas le verbe, figé au pluriel dans la page. Un visiteur n'ayant créé
  qu'une seule chose, le cas le plus courant, lisait donc une phrase fautive. La phrase entière
  est désormais accordée, et la durée du bac à sable avec elle (« 1 heure » et non
  « 1 heures » sur une instance réglée ainsi).
- **« Guide de demarrage »**, affiché sans accent sur chaque tableau de bord, ainsi que cinq
  libellés de la page *Statistiques* dans le même état.
- La case de publication et le champ « Prénom » disparaissent de l'écran d'avis en
  démonstration : le code refusait déjà par construction de publier un retour venu de la
  démonstration. On demandait un consentement qu'on avait décidé de ne jamais honorer.

#### Interne

- **Piste notée, hors périmètre** : un avancement RÉEL de la lecture de liasse (« le document
  est parti », « la réponse arrive ») supposerait de diffuser l'état depuis le serveur
  pendant l'appel au modèle — `$this->stream()` de Livewire 4 — donc un traitement hors
  requête. L'instance est en `QUEUE_CONNECTION=sync`, sans worker : rien de tel n'est
  possible aujourd'hui, et c'est pourquoi la fenêtre d'attente n'affiche aucune étape.

- Les accords du français passent par un utilitaire unique. `Str::plural()` est un pluraliseur
  **anglais** — il rend « sas » pour « sa » — et zéro prend le **singulier** en français, là où
  l'anglais met un pluriel. Une règle qui se retrouvait sinon réécrite à chaque affichage d'un
  compteur.
- **⚠️ Vos documents les plus complets restaient en clair sur le serveur, sans expiration.**
  La liasse fiscale et le FEC étaient écrits dans `storage/app/private/{tax-returns,fec}` et
  y demeuraient indéfiniment — or ils portent bien plus qu'un justificatif : adresse, SIREN,
  résultat fiscal, l'intégralité des écritures. Ils ne sont plus écrits nulle part : ils sont
  **reconstruits à chaque téléchargement** depuis vos données comptables, ce qu'ils faisaient
  déjà (le fichier posé sur le disque était réécrit puis relu dans la même requête, jamais
  servi plus tard). Rien ne change à l'usage ; ce qui change, c'est ce qui reste derrière.
  `php artisan openlmnp:purge-legacy-files` supprime ceux que les versions antérieures ont
  laissés (rapport par défaut, `--fix` pour agir)

- **Les fichiers CSV que vous déposiez pour import n'étaient jamais supprimés.** Les trois
  écrans d'import les déposaient à la **racine** du disque privé, sous un nom aléatoire, sans
  aucun lien vers leur propriétaire — un export Airbnb, avec les noms de vos voyageurs, leurs
  dates de séjour et les montants versés. Ils sont désormais rangés sous
  `documents/{id}/imports`, **supprimés dès l'import terminé**, et une purge quotidienne
  ramasse les aperçus abandonnés en cours de route. Les anciens sont traités par
  `openlmnp:purge-legacy-files`

- **L'étape « Recettes Airbnb » de l'assistant d'import annuel n'importait rien.** Le fichier
  déposé n'était jamais lu : la condition qui gardait l'import portait sur une forme d'état
  que Filament ne produit pas. L'assistant se terminait sur « Import terminé » sans avoir
  créé la moindre recette, et sans le moindre message d'erreur

#### Documentation

- `FONCTIONNALITES.md` et `FAQ.md` décrivent enfin la **reprise d'une comptabilité
  existante** (assistant en cinq étapes, cases Cerfa, contrôle de reprise) — la
  fonctionnalité était livrée depuis la 1.5.0 sans sa page publique

### Données publiques

- **Vérification du SIREN dans la base Sirene.** Dans *Mon profil*, le champ SIREN contrôle
  désormais la clé du numéro à la saisie. Le bouton « Vérifier dans Sirene », aussi présent sur
  l'écran de télédéclaration, interroge l'API Recherche d'entreprises de l'État : entreprise
  active ou cessée, date de création, code APE et ce qu'il signifie. Deux codes sont normaux
  pour un loueur en meublé, 68.20A (location durable) et 55.20Z (courte durée) ; un autre code
  est signalé comme à vérifier, jamais comme une erreur. Seul le SIREN part, et seulement au
  clic. `SIRENE_ENABLED=false` retire le bouton
- **Le DPE du bien, retrouvé et expliqué depuis sa fiche.** « Retrouver le DPE » cherche les
  diagnostics publiés à l'adresse du bien dans la base de l'ADEME : s'il y en a plusieurs, ils
  sont tous listés et rien n'est choisi à votre place. Le numéro peut aussi être saisi à la
  main. La fiche indique ensuite ce que la classe permet : décence énergétique du bail meublé
  (échéances 2025, 2028 et 2034, outre-mer compris) et obligation des meublés de tourisme, en
  vigueur au 1er janvier 2034 sauf pour la résidence principale du loueur. Une alerte prévient
  six mois avant l'expiration du DPE. ⚠️ Elle transmet l'**adresse** du bien (à
  l'IGN, puis à l'ADEME), et seulement au clic — l'autocomplétion d'adresse ci-dessus est
  l'autre fonctionnalité qui sort une adresse, et elle le fait au fil de la frappe. `DPE_ENABLED=false`
  retire le bouton. Les outils MCP `get_property`, `create_property` et `update_property`
  lisent et écrivent les quatre champs DPE
- **Repère de loyer du marché dans le simulateur.** Quand la commune d'un bien est connue
  (après une estimation DVF), le simulateur affiche un loyer d'annonce au m², avec son
  intervalle à 95 %, tiré de la « Carte des loyers » de l'ANIL (data.gouv.fr, Licence
  Ouverte 2.0). ⚠️ C'est le loyer d'une location **non meublée, charges comprises** : un
  repère pour situer le bien, pas une recette de meublé, et il n'entre dans aucun calcul. À
  Paris, Lyon et Marseille, la lecture se fait par arrondissement. La commande
  `openlmnp:refresh-rent-map --if-missing`, planifiée chaque jour, importe les données sur une
  instance neuve, puis **découvre seule le millésime suivant** sur l'API data.gouv.fr : elle
  ne le cherche qu'à partir du 1er novembre (aucun appel le reste de l'année) et ne remplace
  rien si le format d'un des quatre fichiers a changé. `RENT_MAP_ENABLED=false` coupe l'import
  et l'affichage. Le simulateur et l'outil MCP `get_simulation` affichent le millésime
  réellement importé, avec un lien vers sa fiche data.gouv.fr

### Dossiers liés

- **Dossier d'indivision offert, partagé entre indivisaires.** Depuis *Dossiers liés* (menu
  Paramètres), créez un dossier séparé pour une indivision : son SIREN est vérifié dans la
  base Sirene (nature juridique 2110, établissement actif) et verrouillé — lui comme le
  caractère « indivision » du dossier ne changent plus ensuite. Un sélecteur dans le menu
  utilisateur permet de l'ouvrir d'un clic (aucun mot de passe à retenir, aucune connexion
  directe possible) ; un bandeau permanent rappelle le dossier ouvert et permet d'y revenir.
  Biens, recettes, charges, justificatifs et coffre-fort restent propres à chaque dossier ; la
  clé d'intelligence artificielle, le mode de navigation et les avis restent ceux du compte
  principal, quel que soit le dossier ouvert. Nombre de dossiers illimité en auto-hébergement
  (réglable), posé par abonnement sur le cloud. Fonctionnalité derrière
  `LINKED_DOSSIERS_ENABLED`, désactivée par défaut
- **Inviter le second indivisaire.** Depuis la fiche du dossier, saisissez son adresse
  électronique : un courriel personnel lui parvient, valable 14 jours, révocable et relançable
  tant qu'il n'a pas servi. Il l'accepte en se connectant avec cette même adresse, sur un
  compte actif (ni suspendu, ni de démonstration, ni lui-même un dossier lié) — sans jamais
  consommer d'offre. Chaque indivisaire peut ensuite quitter le dossier de son côté, et le
  propriétaire peut révoquer un membre. Si le créateur du dossier résilie son abonnement ou le
  quitte, l'administration passe automatiquement à un autre membre actif ; sans membre actif,
  le dossier n'est jamais supprimé, seulement inaccessible le temps qu'il le redevienne
- **Un indivisaire sans biens à lui accède gratuitement au dossier partagé.** Un héritier, un
  conjoint ou une compagne sans location meublée personnelle n'a aucune raison de payer un
  abonnement pour la seule indivision. Sur le lien d'invitation, qui n'a pas encore de compte
  peut créer un accès gratuit d'indivisaire (nom et mot de passe seulement, l'adresse étant
  celle de l'invitation et non modifiable) — même quand l'instance n'accepte aucune inscription
  libre. Ce compte n'a jamais de dossier à lui : à la connexion, il arrive directement dans son
  dossier lié, ou sur la liste s'il en a plusieurs, sans pouvoir saisir de biens personnels. Le
  dossier reste accessible tant qu'il y subsiste au moins un autre membre abonné ou en compte
  standard actif — pas de dossier gratuit perpétué sans personne pour le faire vivre — et
  l'administration ne lui revient jamais. Un tel compte qui s'abonne un jour devient un compte
  ordinaire sans perdre son accès au dossier
- **Coffre-fort : une clé par personne, même en changeant de dossier.** Basculer vers un
  dossier lié, ou en revenir, ne reverrouille plus le coffre de l'autre identité : chacune
  garde sa propre clé déverrouillée tant que sa fenêtre d'inactivité ne l'a pas expirée
- **Un jeton MCP peut agir pour un dossier lié.** Depuis *Jetons MCP* (toujours réservée au
  compte principal), un nouveau jeton se crée désormais « pour » votre dossier personnel ou
  pour un dossier dont vous êtes membre actif — un assistant IA connecté avec ce jeton
  consulte et modifie alors les données du dossier, jamais les vôtres. L'appartenance est
  **revérifiée à chaque appel** : membre révoqué, dossier archivé ou fermé, ou plus aucun
  abonné actif derrière un accès gratuit d'indivisaire coupent le jeton dès l'appel suivant,
  avec une erreur explicite — jamais un repli silencieux sur vos propres données. La limite de
  débit, la suspension de compte et le journal d'audit (désormais complété du dossier visé)
  restent ceux du compte principal, qui seul porte et révoque le jeton
- **Deux onglets, deux dossiers : plus de saisie dans le mauvais.** La bascule vers un dossier
  lié, ou le retour à votre dossier, vaut pour tout le navigateur. Un onglet resté ouvert sur
  l'ancien dossier ne peut plus rien enregistrer : à sa première action, une fenêtre en français
  (« Vous avez changé de dossier dans un autre onglet ») l'explique et propose de recharger la
  page ; rien n'est enregistré. Une page simplement restée ouverte trop longtemps affiche, de
  même, « Cette page a expiré » en français, là où apparaissait jusqu'ici un message en anglais
- **Plus de déconnexion en revenant à votre dossier avec deux onglets ouverts.** Si l'autre
  onglet envoyait une requête au moment précis de la bascule, le navigateur pouvait abandonner
  la session ouverte par la bascule et renvoyer vers la page de connexion. L'ancienne session
  est désormais marquée comme remplacée : la requête de l'autre onglet est refusée avec le
  message ci-dessus, et votre session reste ouverte
- **Interface des dossiers liés revue** (maquettes arbitrées le 2026-10-15) :
  - *Dossiers liés* affiche désormais une **jauge** du quota, nomme chaque membre avec son
    rôle, et « Archiver » passe par un menu puis une **vraie modale de confirmation**
    (il ne se trouve plus à côté d'« Ouvrir »)
  - **Créer un dossier lié** est un assistant en 3 étapes (SIREN, vérification Sirene,
    nom) : un refus (forme juridique, SIREN déjà suivi, Sirene indisponible) s'affiche
    **dans l'étape**, sans fermer la fenêtre ni perdre votre saisie, avec un lien vers le
    support quand il peut aider. Le nom du dossier est proposé à partir de Sirene,
    modifiable
  - Dans un dossier lié, un **bandeau plein**, une **carte d'identité** dans la barre
    latérale et un **rappel sur les formulaires de saisie** rendent l'interface impossible
    à confondre avec votre dossier personnel. Le bouton de retour s'appelle désormais
    « Revenir à mon dossier »
  - **L'invitation à un second indivisaire explique ce qu'elle accorde avant l'envoi**, le
    courriel (désormais aux couleurs de la marque) nomme l'expéditeur, le dossier et son
    SIREN, et **la page d'acceptation demande une confirmation explicite** — là où
    l'ouverture du lien suffisait jusque-là à rattacher le dossier. Un bouton « Refuser »
    est proposé, et le cas « ce n'est pas le bon compte » se résout en un seul clic
    (déconnexion puis retour au lien)

### Suppression de compte

- **« Supprimer mon compte »**, dans *Mon profil* : efface définitivement et
  irréversiblement vos biens, exercices fiscaux, recettes, charges, amortissements,
  travaux, mobilier, emprunts, justificatifs (coffre-fort compris, fichiers sur
  disque compris) et jetons MCP. La confirmation demande le mot de passe actuel et
  la ressaisie de l'adresse e-mail. Un dossier d'indivision dont vous êtes l'unique
  membre actif est supprimé avec vous ; un dossier partagé reste accessible aux
  autres membres, vous en êtes seulement retiré. Sur le cloud, la suppression est
  refusée tant qu'un abonnement payant pourrait être actif, avec un renvoi vers la
  résiliation (un compte membre d'indivision, gratuit par nature, n'est pas
  concerné). Masquée pour un compte de démonstration et pour un dossier lié, qui
  n'ont pas de suppression qui leur soit propre
- **`php artisan openlmnp:account-delete {email}`** : la même suppression en ligne
  de commande, pour un administrateur auto-hébergé. `--dry-run` affiche ce qui
  serait supprimé sans rien effacer, `--force` saute la confirmation interactive.
  Refuse le compte de démonstration public et le dernier administrateur de
  l'instance
- Droit à l'effacement (RGPD, art. 17) : jusqu'ici, aucune des deux voies n'existait
  et les comptes de test devaient être effacés à la main dans `tinker`, sans purger
  ni les justificatifs sur disque ni les jetons MCP
- **Le journal des dossiers liés ne garde plus l'adresse d'un compte supprimé.**
  Constaté sur la bêta : après une suppression, le journal d'un dossier supprimé
  restait en base, avec l'adresse e-mail des personnes invitées et le SIREN. Le
  journal d'un dossier supprimé part désormais avec lui, invitations à des adresses
  sans compte comprises. Dans un dossier partagé qui survit, les lignes qui
  concernent le compte supprimé gardent l'action et la date, sans son adresse, et
  ses invitations à ce dossier sont effacées. `openlmnp:demo-cleanup` applique la
  même règle
- **`php artisan openlmnp:dossier-events-purge`** retire ces reliquats d'une base
  existante (`--dry-run` pour compter sans rien modifier). Elle peut être relancée
  sans effet

#### Corrections (test de bout en bout du 2026-10-15)

- **Dossier archivé pendant que vous y êtes encore** : la page d'erreur était un « 403 »
  brut en anglais, et l'onglet resté ouvert pouvait produire une erreur 500 au lieu de vous
  ramener proprement à votre dossier. Les pages 403, 404, 405 et 419 du panel sont désormais
  en français, avec un bouton de retour
- Le **guide de démarrage** ne propose plus « Créer votre bien » à un accès gratuit
  d'indivisaire qui n'a aucun bien personnel à saisir
- La **page d'invitation** n'affiche plus « l'inscription est fermée » juste au-dessus du
  formulaire de compte gratuit d'indivisaire, qui fonctionne pourtant toujours
- Un **nouveau dossier d'indivision** arrive désormais avec **deux lignes d'indivisaire
  vides** prêtes à compléter (ou les indivisaires trouvés par Sirene, quand le registre les
  donne), au lieu d'un formulaire vide malgré les deux lignes attendues par défaut
- Une **invitation refusée** par l'invité s'affiche « Refusée », distincte d'une invitation
  **révoquée** par le propriétaire du dossier
- Le bouton **Suivant** de l'assistant de création se désactive tant que le SIREN saisi
  n'est pas conforme, au lieu de rester cliquable sur un refus déjà affiché
- Le **nom de dossier proposé par Sirene** ne se limite plus au seul mot « INDIVISION » : il
  reprend les indivisaires du registre (dirigeants personnes physiques) quand ils sont
  connus, ou reste vide sinon
- Le sélecteur **« Pour »** d'un nouveau jeton MCP est désormais présélectionné sur « Mon
  dossier personnel » au lieu de s'ouvrir vide
- Le bouton d'aide flottant ne recouvre plus, sur mobile, le rappel « Sera enregistrée
  dans » ni le bouton de validation d'un formulaire collé en bas d'écran
- La colonne **« Avantage vs réel »** de l'assistant de clôture n'affiche plus en vert un
  micro-BIC qui coûte en réalité plus cher que le régime réel
- Correction d'une erreur JavaScript (« Livewire Entangle ») au chargement de l'assistant de
  clôture, sur le bouton « Clôturer l'exercice »

### Sécurité : conteneur sans root, CSP, cookie Secure

Fin du point 20 de l'avis [GHSA-j4gm-g8m8-93x2](https://github.com/manganate006/openlmnp/security/advisories/GHSA-j4gm-g8m8-93x2).

- **Image Docker sans root** : le serveur web et les tâches planifiées tournent sous
  l'utilisateur `www-data` (UID 82). Le conteneur démarre en root le temps de remettre les
  volumes `database/` et `storage/` à cet utilisateur, puis abaisse ses privilèges :
  **aucune action à la mise à jour**, y compris avec des volumes créés par une version
  précédente. Un `docker exec openlmnp php artisan …` s'exécute lui aussi sous `www-data`.
  Si les volumes ne peuvent pas changer de propriétaire (NFS en `root_squash`…), le
  conteneur le signale et continue en root. Le serveur reste celui de PHP (`artisan serve`)
- **Content-Security-Policy** posée par l'application, compatible avec le panel : scripts,
  styles et connexions limités à l'instance, à jsDelivr et, si GTM est configuré, à Google ;
  ni plugin, ni formulaire envoyé ailleurs, ni affichage dans un cadre tiers. Réglable par
  `CSP_ENABLED`, `CSP_REPORT_ONLY`, `CSP_EXTRA_SOURCES` et `CSP_POLICY`
- **Cookie de session `Secure`** aussi sur le nom d'hôte d'un `APP_URL` en `https`, même
  quand le proxy qui termine TLS n'est pas déclaré de confiance ; une instance du réseau
  local servie en HTTP n'est pas concernée. `SESSION_SECURE_COOKIE` et `HSTS_MAX_AGE`
  passent désormais par l'image Docker

### Inscription, version et image Docker

- **Page « Version et nouveautés » ouverte à tous les comptes** : version installée, dernière
  version publiée et notes des dernières releases, jusqu'ici réservées à l'administrateur. Le
  relevé GitHub est mis en cache une heure ; `UPDATE_CHECK_ENABLED=false` le coupe pour une
  instance sans accès sortant. Une mise à jour n'est plus annoncée à qui ne peut pas
  l'appliquer, et la liasse PDF indique la version qui l'a produite au lieu d'un « v0.1 » figé
- **`/register` n'est plus une impasse quand l'inscription est fermée** : l'adresse renvoie
  vers la page de souscription déclarée par `OPENLMNP_SIGNUP_URL`, ou vers la connexion avec
  une explication. Le mode sur invitation garde sa propre page d'inscription
- **PHP 8.4.27 dans l'image Docker**, comme en 1.6.11 : correctif des failles critiques
  CVE-2026-109618 et CVE-2026-109619 (exécution de code à distance sans authentification).
  La construction de l'image échoue si PHP est antérieur à 8.4.27, et la mise à jour par le
  script Proxmox met aussi à niveau les paquets PHP
- **Avis de sécurité dans l'application**, comme en 1.6.11 : la réponse du check-in quotidien
  de télémétrie peut porter des avis de sécurité, affichés en bandeau aux administrateurs tant
  que la version installée est concernée. Rien de plus n'est envoyé ; sans télémétrie
  (`TELEMETRY_ENABLED=false`), aucun avis n'est reçu
- **L'image Docker indique le commit dont elle est construite** (`/image-commit`)
- **README et fiche du registre MCP** renvoient vers le site openlmnp.fr
- **`UPDATE_SELF_APPLY_HINT`** est désormais transmise par l'image Docker : la consigne de mise
  à jour propre à un hébergement était ignorée

### Reprendre un dossier existant : corrections relevées en rejouant une liasse réelle

- **Le contrôle ne confond plus un écart dû aux frais d'acquisition avec une convention de
  prorata.** Quand l'écart sur le cumul d'amortissements correspond exactement à ce que
  l'application amortit en frais capitalisés, l'écran le dit explicitement : ces frais sont
  amortis en 030 dans OpenLMNP, en 016 chez votre cabinet. Les causes listées en cas d'écart
  plus marqué citent désormais aussi les travaux et le mobilier
- **L'assistant signale les travaux et le mobilier déjà amortis**, que son éditeur de
  composants ne reprend pas lui-même, et renvoie vers leurs propres écrans de saisie, où une
  section « Reprise d'une comptabilité existante » existait déjà. Leur cumul d'ouverture est
  désormais borné sur le dernier exercice tenu par le cabinet, comme celui des composants
- **Un rechargement de page après « Terminer la reprise » ne perd plus la méthode choisie ni
  les reports saisis** : l'assistant se réveille depuis l'exercice déjà enregistré au lieu de
  repartir d'un formulaire vierge. « Revoir ma reprise » retrouve aussi la case 044 et le
  résultat fiscal lu sur la liasse, désormais conservés sur l'exercice, et l'étape 3 montre le
  plan recopié plutôt qu'un nouveau dépôt de liasse
- **Après une reprise, l'assistant de clôture ne réclame plus l'exercice précédent**, même
  quand la liasse ne porte ni amortissement différé ni déficit (le cas le plus courant d'un
  loueur bénéficiaire) : une reprise terminée est le point de départ de la chaîne d'exercices
- **Après une reprise, l'assistant de clôture propose l'exercice qui suit la dernière liasse**
  (2026 pour une liasse 2025 déposée par le cabinet), et non plus l'année déjà déclarée, qu'il
  fallait corriger à la main. Si des exercices ont déjà été clôturés depuis la reprise, il
  propose le premier qui ne l'est pas. Sans reprise, l'année proposée ne change pas
- **Le contrôle range ses diagnostics par ce que les chiffres appuient.** Une cause qu'un
  montant explique passe devant celles qu'une simple condition rend plausibles, et une cause
  qui ne peut pas jouer sur la ligne n'y est plus citée (ni prorata ni part du terrain sur le
  total des immobilisations brutes). Un écart sur la case 030 égal aux frais d'acquisition
  amortis, avec une case 044 identique, est marqué « expliqué » : les frais sont amortis des
  deux côtés, en 016 chez le cabinet
- **Le bouton qui passe les frais d'acquisition en charges n'apparaît plus que si la case 044
  montre que le cabinet ne les porte pas parmi ses immobilisations.** Il était proposé dès
  que l'écart sur la case 030 valait leur cumul, y compris quand le cabinet les amortit : le
  suivre aurait retiré les frais des immobilisations et supprimé leur dotation annuelle
- **La recopie d'une liasse n'est plus refusée pour quelques centimes.** Une liasse arrondit
  chaque ligne à l'euro : un dépassement de moins d'un euro par ligne est retiré de la plus
  grosse ligne, et signalé. Un vrai dépassement reste refusé, mais s'affiche désormais au
  centime près au lieu de « 0 € d'excédent »
- **Les lignes lues gardent leur rubrique du 2033-C** : « Constructions » était rangée en
  « Autres immobilisations (470 / 560) »
- **La dotation lue sur la liasse est conservée** : l'éditeur d'amortissements la recalculait
  (base divisée par la durée) et l'enregistrait ainsi au premier clic sur « Enregistrer »
- **La case 044 n'est plus préremplie avec la case 028** : elle reçoit la somme des cases 028
  et 014 lues (ou la case 044 elle-même si la lecture la fournit)
- **Le champ « Prix d'acquisition » dit ce qu'il attend** (le prix payé pour le bien,
  terrain compris, comptes 211 et 213 de la balance) sans plus citer de case Cerfa

### Retours d'utilisateurs

- **Liasse : cadre « Déficits reportables » du 2033-D laissé vide** ([#19](https://github.com/manganate006/openlmnp/issues/19),
  merci @cocool97). La case 870, intitulée « Total des déficits restant à reporter », portait
  les amortissements différés de l'art. 39 C, et la 984 comptait déjà le déficit de l'exercice.
  La notice officielle (2033-NOT-SD) veut 870 = 984 + 860, et réserve surtout ce cadre aux
  sociétés à l'impôt sur les sociétés : un déficit LMNP est repris par l'administration en
  cases 5GA à 5GJ de la 2042-C-PRO. Le cadre est donc laissé vide, et les amortissements
  différés, qui n'ont pas de case, sont suivis à part à l'écran et dans le PDF (ouverture,
  différés de l'exercice en 318, repris en 350, clôture). La reprise d'un dossier continue de
  les lire en case 870 de l'ancienne liasse, où beaucoup de cabinets les portent
- **Liasse : case 136 du bilan (2033-A) = résultat comptable** ([#20](https://github.com/manganate006/openlmnp/issues/20),
  merci @cocool97). Elle reprenait le résultat fiscal : dès qu'une part de l'amortissement
  était mise en report (art. 39 C), le bilan affichait 0 € au lieu du déficit comptable de la
  ligne 310, et le compte de l'exploitant (120), calculé par différence, était sous-évalué
  d'autant. Un nouveau contrôle vérifie « case 136 = ligne 310 »
- **Liasse : montants à l'euro, et plus de « ? » dans le PDF** ([#21](https://github.com/manganate006/openlmnp/issues/21)).
  Le 2033-B, la 2031, la 2033-D et l'écran de télédéclaration étaient au centime, le 2033-A
  et le 2033-C à l'euro. Tous les formulaires sont désormais à l'euro : chaque ligne est
  arrondie, puis chaque total est recalculé sur les lignes arrondies, si bien que 254 = 572
  aussi sur les montants imprimés. Quand l'amortissement est plafonné, l'écart d'arrondi est
  porté par la ligne 318 et le résultat fiscal reste à 0 €. Les annexes de détail restent au
  centime. Le signe moins des colonnes de montants du PDF s'imprimait « ? » (police Courier
  sans ce caractère) : ces colonnes passent en DejaVu Sans Mono
- **Coffre-fort : lien direct vers un justificatif scellé.** Un justificatif déposé coffre
  verrouillé ne s'ouvre que dans le navigateur. Ouvert par son lien direct (clic du milieu,
  nouvel onglet), il partait en téléchargement tronqué : une page explique désormais comment
  l'ouvrir. La page « coffre verrouillé » (423) a le même habillage que les autres erreurs,
  et les icônes d'un justificatif ont un libellé en français (« Ouvrir dans un nouvel
  onglet », « Télécharger ») au lieu d'une clé technique
- **L'application a son icône d'onglet** (#18) : `/favicon.ico` était le fichier vide du
  squelette Laravel et le panel n'en déclarait aucune. L'icône est celle du site (maison
  blanche sur carré émeraude), en SVG et en `.ico`, déclarée dans le panel comme sur les pages
  publiques (invitation, confidentialité, erreurs)
- **« Enregistrer » ramène à la liste** (#16) sur les charges, les recettes, les emprunts, le
  mobilier et les travaux ; pour le mobilier et les travaux, à la liste du bien concerné. La
  proposition de générer les échéances d'une charge mensuelle s'affiche sur la liste. La fiche
  d'un bien et l'exercice restent sur le formulaire, où l'on enchaîne plusieurs enregistrements
- **Part d'usage pour la location sur une charge** (#17) : un forfait téléphone, un abonnement
  internet ou un logiciel qui sert aussi à titre personnel se déduit à l'usage, par exemple
  30 %, et non plus seulement en entier ou à la quote-part surface (qui vaut 100 % pour un
  logement loué en entier). La part d'usage s'applique à chaque charge, avant la quote-part
  surface, partout où les charges sont comptées : résultat fiscal, écritures et FEC,
  déclaration de TVA, liasse PDF (où elle figure ligne par ligne), simulateur, projection,
  tableau de bord. Elle est recopiée sur les échéances générées et sur une charge dupliquée,
  exportée avec le dossier, et exposée par le serveur MCP (`business_use_percent`). Champ
  vide = 100 % : aucune charge existante ne change

## [1.6.11] - 2026-10-23

### Sécurité

Version corrective, **à installer sans attendre** sur toute instance accessible depuis Internet.

- **PHP 8.4.27** dans l'image Docker (au lieu de 8.4.24). Cette version corrige deux failles
  critiques de PHP, [CVE-2026-109618](https://www.cve.org/CVERecord?id=CVE-2026-109618) et
  [CVE-2026-109619](https://www.cve.org/CVERecord?id=CVE-2026-109619), qui permettent
  d'exécuter du code à distance sans authentification sur toute installation PHP qui répond à
  des requêtes web ([bulletin PHP du 21 octobre 2026](https://www.php.net/archive/2026.php#2026-10-21-5)).
  Le code d'OpenLMNP n'est pas en cause et ne change pas
- La construction de l'image échoue désormais si PHP est antérieur à 8.4.27
- **Script d'installation Proxmox** : la mise à jour met aussi à niveau les paquets PHP du
  conteneur
- **Avis de sécurité dans l'application** : la réponse du check-in quotidien de télémétrie
  peut désormais porter des avis de sécurité. Ils s'affichent en bandeau en haut du panel,
  pour les administrateurs seulement, tant que la version installée est concernée, et
  disparaissent après la mise à jour. Rien de plus n'est envoyé ; une instance qui a coupé
  la télémétrie (`TELEMETRY_ENABLED=false`) ne reçoit pas ces avis

**Pour mettre à jour** :

- avec l'image Docker : `docker pull manganate06/openlmnp:latest`, puis recréez le conteneur
  avec la même commande `docker run` (vos données, dans les volumes, ne sont pas touchées) ;
- sans Docker : mettez à jour le PHP de votre serveur par son gestionnaire de paquets (8.4.27,
  ou 8.3.36 sur la branche 8.3). Mettre à jour OpenLMNP seul ne suffit pas.

## [1.6.10] - 2026-10-01

### Sécurité

Suite de l'avis [GHSA-j4gm-g8m8-93x2](https://github.com/manganate006/openlmnp/security/advisories/GHSA-j4gm-g8m8-93x2) :
les points de gravité moyenne et faible du même signalement.

- **Outils MCP de consultation** : calculer un exercice, comparer micro-BIC et réel ou lancer
  une simulation n'enregistre plus rien. Ces outils créaient l'exercice et le recalculaient
  au passage, y compris l'exercice suivant quand il était clôturé. Le simulateur de
  l'application suit la même règle
- **Limite d'appels MCP** : `MCP_RATE_LIMIT` (60 par minute et par compte par défaut) est
  désormais appliquée ; elle n'était qu'affichée
- **Journal des appels MCP** : il n'enregistre plus le contenu des fichiers importés, les
  URL signées ni les valeurs longues, et se purge chaque jour au-delà de
  `MCP_AUDIT_RETENTION` jours (90 par défaut)
- **Serveur MCP local** (`php artisan mcp:start`) : sur une instance à plusieurs comptes,
  sans `OPENLMNP_MCP_USER`, il ne montre plus aucune donnée au lieu de celles de tous les
  comptes
- **Import de document par URL** : plus de plages d'adresses internes refusées, et la
  connexion se fait à l'adresse vérifiée. Le contenu doit correspondre à son extension
  (un fichier HTML nommé `.pdf` est refusé)
- **Export MCP des justificatifs** : le lien de téléchargement fonctionne enfin (une heure),
  et les archives sont supprimées au bout de 24 heures
- **HTTPS** : en-tête HSTS et cookie de session `Secure` sur les requêtes servies en HTTPS
- Récapitulatifs des assistants échappés, code INSEE validé, libellés du FEC sans tabulation
  ni saut de ligne, formules CSV précédées d'espaces neutralisées, chemin des fichiers d'un
  exercice retiré de `get_fiscal_year`, réponse 401 au lieu d'une erreur 500 sur `/mcp`
- Dépendances : Laravel 13.34, league/commonmark 2.10.3, league/flysystem 3.36
  (`composer audit` sans avis)

## [1.6.9] - 2026-10-01

### Sécurité

Version corrective, **à installer sans attendre** sur toute instance qui a plusieurs comptes
ou qui est ouverte sur Internet. Le détail est dans l'avis
[GHSA-j4gm-g8m8-93x2](https://github.com/manganate006/openlmnp/security/advisories/GHSA-j4gm-g8m8-93x2).
Merci à [@ptitzgeg-on-git](https://github.com/ptitzgeg-on-git), qui a signalé ces failles en
privé avec un correctif et ses tests.

- **Justificatifs, photos et imports** : un compte ne peut plus lire, effacer ni importer un
  fichier rangé dans le dossier d'un autre compte
- **Liasse et FEC** : chaque compte a désormais son propre dossier de génération
- **Compte démo public** : son nom, son e-mail, son mot de passe, l'accès MCP et ses jetons ne sont
  plus modifiables depuis l'interface
- **Recueil d'avis** : un compte ne peut plus modifier le retour d'un autre
- **Exports CSV** : un libellé qui commence par `=`, `+`, `-` ou `@` n'est plus interprété
  comme une formule par Excel ou LibreOffice
- **Mises à jour** : le dépôt par défaut est désormais `manganate006/openlmnp`. L'ancien
  défaut visait un compte GitHub qui n'appartenait pas au projet. **Installation sans Docker
  (script LXC ou `git clone`)** : vérifiez que la page « Mises à jour » affiche bien ce dépôt
- **En-tête Host** : quand `APP_URL` porte un nom de domaine, les requêtes adressées à un
  autre nom sont refusées (erreur 400). Si votre instance est aussi ouverte par une IP ou un
  autre nom, ajoutez-les dans `TRUSTED_HOSTS` (liste séparée par des virgules). Rien ne
  change si `APP_URL` vaut `localhost` ou une IP, ce qui est le cas par défaut avec Docker

## [1.6.8] - 2026-09-26

### Ajouts

- **Le détail du résultat, en annexe du PDF.** Après le 2033-B, une annexe permet de refaire
  chaque ligne à la main, seul ou avec un expert-comptable : pour chaque bien, les recettes
  et commissions, chaque charge avec sa date, sa catégorie, sa ligne (242 ou 244) et son
  affectation, le calcul de la quote-part sur les charges partagées, les intérêts et
  l'assurance des emprunts ; puis le plafonnement des amortissements (art. 39 C) étape par
  étape et le suivi des déficits antérieurs, millésime par millésime — de quoi vérifier les
  cases 5GA à 5GJ de la 2042-C-PRO
  ([#13](https://github.com/manganate006/openlmnp/issues/13))
- **Un contrôle de plus** sur l'écran de télédéclaration et dans le PDF : la partie B du
  2033-B (310 + 318 − 350) doit retomber sur le résultat fiscal de l'exercice
- Le **rapport de diagnostic** couvre le passage au résultat : lignes du 2033-B, totaux par
  bien, plafonnement et déficits. Toujours sans libellé de charge ni nom de banque

### Corrections

- **2033-B, ligne 360** : elle affichait le report d'amortissements différés sous
  l'intitulé « Déficits antérieurs imputés ». Elle reste désormais à zéro : en location
  meublée non professionnelle, les déficits antérieurs ne s'imputent pas sur la liasse,
  l'administration les reprend en cases 5GA à 5GJ de la 2042-C-PRO. Le bénéfice à reporter
  en 5NA est donc celui d'avant imputation (lignes 352 et 370, égales)
- **2033-B, ligne 350** : les amortissements différés des années passées, déduits cette
  année, n'apparaissaient nulle part ; la partie B ne se refaisait donc pas à la main dès
  qu'un report était consommé
- **2033-B d'un bien assujetti à la TVA** : les recettes et charges y étaient comptées TTC,
  alors que le résultat de l'exercice les prend hors taxe. Les deux sont maintenant calculés
  selon les mêmes règles, quote-part comprise, au centime près

Si vous avez déjà déposé une liasse avec des amortissements différés antérieurs, comparez
les lignes 350 et 360 avec celles de cette version. Le résultat
fiscal de l'exercice (352/354) et la 2042-C-PRO ne changent pas.

## [1.6.7] - 2026-09-15

### Ajouts

- **Noter le dépôt de votre liasse.** Générer le PDF ou recopier les cases ne dépose rien :
  seule l'administration le fait, et elle vous en rend la preuve — un certificat de dépôt pour
  une saisie en ligne, un compte rendu de traitement pour un envoi EDI. Un bouton
  **« Dépôt »**, en haut de l'écran de télédéclaration et sur chaque ligne de la page
  Exercices, enregistre sa date et son numéro. Un badge **Déposée** distingue alors, dans la
  liste, la liasse transmise de la liasse simplement imprimée, et vos assistants connectés en
  MCP peuvent la lire.
  Rien n'est deviné : sans votre saisie, l'exercice reste sans marque. Pour effacer un dépôt
  noté par erreur, videz la date — le numéro d'accusé s'en va avec elle, un accusé sans date
  ne prouvant rien

- **Un déploiement en un clic sur les PaaS auto-hébergés.** Un fichier
  `docker-compose.coolify.yml` à la racine du dépôt : sur Coolify, *New Resource* →
  *Docker Compose (Empty)*, on le colle, et la plateforme s'occupe du domaine, du
  certificat et du reverse proxy. Le même fichier convient à Dokploy, Easypanel ou
  Portainer en réglant `APP_URL` à la main. Les points de vigilance — les deux volumes à
  conserver, le schéma obligatoire dans `APP_URL` — sont dans
  [docs/INSTALLATION.md](docs/INSTALLATION.md)
- **`UPDATE_SELF_APPLY_HINT`** : la consigne affichée quand la mise à jour en place est
  désactivée. Sans elle, l'application répond « cette instance tourne sur une image Docker
  immuable, faites `docker pull` » — vrai pour l'image officielle, faux sur un serveur qui
  n'a pas Docker. Un gestionnaire de paquets qui prend les mises à jour en charge y met
  désormais la sienne. Rien ne change pour l'image Docker

### Corrections

- **`APP_NAME` et `TRUSTED_PROXIES` se règlent enfin par `-e`.** Les deux étaient
  documentés comme modifiables à l'exécution, mais l'entrypoint ne les recopiait pas vers
  `.env` : en Docker, `-e APP_NAME=…` ne faisait **rien**, sans le moindre message. Le
  second n'est pas cosmétique — derrière un reverse proxy, c'est lui qui décide si les
  en-têtes `X-Forwarded-*` sont crus, donc si l'application se sait en HTTPS

## [1.6.6] - 2026-09-10

### Ajouts

- **Un bandeau de consentement aux traceurs**, avec Consent Mode v2. La mesure d'audience
  n'est activée qu'après votre accord explicite ; refuser est aussi simple qu'accepter, et
  l'application fonctionne à l'identique dans les deux cas.
  ⚠️ **En auto-hébergement, rien ne change** : sans conteneur de mesure configuré, aucun
  traceur n'est chargé et aucune question ne vous est posée
- **`CONSENT_COOKIE_DOMAIN`** (facultatif, vide par défaut) : élargit la portée du cookie de
  consentement à un domaine, pour la partager entre plusieurs sous-domaines d'une même
  installation. Laissé vide, le cookie reste limité à l'hôte qui l'a posé

### Corrections

- **Vos amortissements déjà pratiqués ne se comptent plus deux fois.** Si vous aviez recopié
  le cumul de votre cabinet, le logiciel reconstituait par-dessus les mêmes exercices et
  ajoutait les deux : la case 030 valait le double. Un utilisateur l'a signalé avec les
  chiffres qui le prouvent — 9 496 € affichés pour 4 736 € déclarés. L'éditeur d'amortissements
  gagne une colonne **« Couvert jusqu'à »**, l'assistant de reprise la renseigne tout seul, et
  le champ passe en rouge si un cumul est saisi sans elle
- **Sur un bien partiellement loué, la quote-part s'appliquait deux fois** aux travaux et au
  mobilier non dédiés à la location : leur dotation valait le carré de la quote-part au lieu de
  la quote-part. Un bien loué à 60 % amortissait 36 % de ses travaux, et la ligne se
  contredisait elle-même — sa valeur brute, elle, n'appliquait la quote-part qu'une fois.
  ⚠️ Si vous êtes concerné, vos dotations **augmentent** : relancez « Recalculer la chaîne »
  depuis la page Exercices pour que vos exercices déjà enregistrés le reprennent
- **La part du terrain accepte les décimales dans l'assistant de reprise.** Sa cellule refusait
  17,5 % — une valeur d'acte ordinaire — et tronquait à 17 ce qui passait quand même. Sur un
  bien à 175 000 €, cela déplaçait 875 € de base amortissable
- **Un écart « proche » vous dit maintenant d'où il vient.** Sous 1 %, l'écran de contrôle de la
  reprise affichait un badge sans un mot d'explication. Il nomme désormais la cause — une
  convention de prorata, au jour chez nous, au mois chez beaucoup de cabinets — et précise que
  **c'est le chiffre de votre liasse déposée qu'il faut conserver**, pas le nôtre

## [1.6.5] - 2026-09-08

### Ajouts

- **Vos frais de notaire et d'agence ont désormais quatre traitements possibles**, à aligner
  sur ce qu'a fait votre comptable. Au trio existant — amortis, passés en charges, non repris
  — s'ajoute **« Intégrés au coût du bien »** : les frais rejoignent alors le prix, se
  répartissent terrain / bâti comme lui, et s'amortissent au rythme de chaque composant.
  C'est le traitement que décrit le plan comptable, et c'est celui que retiennent beaucoup de
  cabinets. Votre base amortissable augmente en conséquence
- **Une annexe « Détail des immobilisations »**, dans le PDF comme sur l'écran de
  télédéclaration : une ligne par actif, avec son origine, la ligne du 2033-C où il atterrit,
  sa valeur brute, sa dotation et son cumul. Un tableau agrégé ne dit pas ce qu'il contient ;
  on peut désormais remonter n'importe quel montant de la liasse jusqu'à ce qui l'a produit
- **Un bouton « Rapport de diagnostic »** en haut des pages *Amortissements* et
  *Télédéclaration*. Il rassemble en un texte tout ce qui produit vos amortissements et votre
  liasse — valeurs saisies, base amortissable et sa provenance, chaque composant avec sa base,
  sa durée et sa date de départ, travaux, mobilier, lignes de liasse obtenues et écarts. À
  copier ou télécharger, puis à joindre à une demande d'aide. Il ne contient **ni nom, ni
  adresse, ni commune, ni adresse e-mail**, et **ne part nulle part tout seul**. En ligne de
  commande : `php artisan openlmnp:diagnostic`
- **La ligne du 2033-C de vos travaux et de votre mobilier est maintenant réglable.** Elle
  était figée — travaux en agencements, mobilier en autres —, si bien qu'un cabinet qui
  classait des travaux de gros œuvre en constructions ne pouvait pas être reproduit. Sans
  effet si vous n'y touchez pas
- Le PDF contient enfin la section **2031-SD**, que l'écran affichait déjà et que le document
  annonçait sans la produire

### Corrections

- **Vos frais d'acquisition amortis ne sont plus présentés en immobilisations
  incorporelles** (cases 014/016, ligne 410/500) mais avec les corporelles (cases 028/030,
  ligne 430/520). Un frais d'acquisition capitalisé fait partie du coût de l'immobilisation
  qu'il a servi à acquérir. Le **total de vos immobilisations ne change pas** — seule leur
  présentation change. C'est l'issue #11 : un utilisateur y découvrait au bilan une ligne
  « incorporelles » de 8 900 € que rien n'expliquait, et il avait raison de s'en étonner
- **L'aide de la fiche du bien affirmait que « prix d'achat + frais de notaire » servait de
  base d'amortissement.** C'était faux, et cette phrase contredisait une autre ligne de la
  même fiche. Elle est remplacée par la formule réelle et par ce que devient chaque euro de
  frais sous les quatre traitements
- **Le libellé du réglage annonçait « Amortis (incorporés au coût du bien) »** alors que le
  logiciel en faisait une immobilisation distincte. Le libellé promettait un traitement, le
  calcul en appliquait un autre : les deux options portent désormais chacune son nom
- **Modifier une surface ne recalait plus rien.** La quote-part change la base amortissable,
  mais le recalage des composants ventilés en pourcentage surveillait un attribut calculé au
  lieu des deux colonnes de surface : il ne se déclenchait donc jamais sur ce chemin
- **Sur un bien assujetti à la TVA, la valeur brute des travaux et du mobilier était portée
  au bilan TTC** alors que leur amortissement se calcule sur le HT. Les cases 028/044 et la
  ligne 490 étaient majorées de la TVA, et la ligne ne s'amortissait jamais entièrement
- **La quote-part ne s'appliquait aux frais d'acquisition que sur une résidence principale**,
  alors qu'elle s'applique toujours à la valeur du bien. Un bien partiellement loué portait
  donc 100 % de ses frais face à une base déjà proratisée
- L'écran de contrôle de la reprise compare désormais le **total** des immobilisations brutes
  (case 044) et non la seule case 028 : deux cabinets présentent les mêmes frais sur deux
  lignes différentes sans qu'aucun ait tort, et le total ne dépend pas de cette convention
- L'outil MCP `generate_tax_return` annonçait « 2031, 2033-A à 2033-G » quand le document n'en
  contenait que quatre. L'annonce est désormais **dérivée du document**, dans les deux sens

## [1.6.4] - 2026-09-07

### Corrections

- **Prolonger sa démonstration affichait une erreur et n'envoyait jamais le lien de reprise.**
  Le bac à sable était pourtant bien prolongé de 7 jours : l'échec survenait après
  l'enregistrement, au moment de composer le message. Le rappel envoyé avant effacement
  souffrait du même défaut, à ceci près que personne ne pouvait le voir — il part d'une tâche
  planifiée, sans écran pour en rendre compte. Aucun rappel n'avait encore échoué faute de
  compte réunissant les conditions d'envoi ; le premier bac à sable prolongé l'aurait
  déclenché.
- **Un bac à sable prolongé affichait « 167:59:40 ».** Le compte à rebours était conçu pour les
  24 heures d'une démonstration ordinaire ; sur les 7 jours d'une prolongation, il rendait un
  nombre d'heures à trois chiffres que personne ne convertit de tête. Au-delà de 24 heures il
  compte désormais en jours, et garde l'horloge à la seconde sur la dernière journée, là où
  elle porte l'urgence.
- **Le lien de création de mot de passe d'un nouveau client pouvait partir à la mauvaise
  adresse.** Quelqu'un qui essayait la démonstration en laissant une première adresse, puis
  s'abonnait avec une seconde, recevait son accès à l'ancienne. C'est désormais toujours
  l'adresse de l'abonnement qui reçoit.
- La tâche planifiée qui envoie ces rappels **signale désormais ses échecs** au lieu de se
  terminer normalement en annonçant « Rappels envoyés : 0 ». Un envoi en échec n'étant jamais
  marqué comme fait, il était repris à chaque passage horaire sans que rien ne l'indique.


## [1.6.3] - 2026-09-06

### Corrections

- **Le logiciel prévient maintenant au moment où vous changez le prix du bien.** Un montant
  d'amortissement saisi en euros ne suit pas la valeur du bien — c'est sa raison d'être — mais
  la somme peut alors dépasser ce qu'il y a à amortir. Ce dépassement ne se découvrait qu'à la
  génération de la liasse, des semaines après le geste qui l'avait créé. L'avertissement paraît
  désormais à l'enregistrement du bien, chiffré, avec un bouton qui mène à l'écran des
  amortissements. Un bien entièrement ventilé en pourcentage ne produit aucun message : ses
  montants ont suivi, il n'y a rien à signaler
- **Un composant ventilé en pourcentage suit désormais la base dans tous les cas.** Il cessait
  de la suivre dès qu'un montant saisi faisait déborder le total : le recalage abandonnait
  alors *tout*, laissant les composants en pourcentage calculés sur une valeur du bien qui
  n'existait plus. L'écran affichait en conséquence deux chiffres contradictoires — un écart au
  bandeau, un autre déduit des compteurs. Les deux concordent maintenant par construction


## [1.6.2] - 2026-09-06

### Ajouts

- **L'écran des amortissements répare lui-même une ventilation devenue incohérente.** Quand
  les composants dépassent la base amortissable — parce que la valeur du bien ou la part du
  terrain a changé après la ventilation — un bandeau chiffre l'écart et propose de recaler
  d'un clic. Jusqu'ici l'écran ne signalait rien à l'ouverture **et refusait tout
  enregistrement** : l'utilisateur était enfermé, sans autre issue qu'une commande console
  que personne n'a sur une instance hébergée. Le recalage recalcule chaque composant depuis
  son pourcentage ; il prévient qu'une base saisie à la main sera réécrite, et c'est
  l'utilisateur qui décide

## [1.6.1] - 2026-09-06

### Corrections

- **Les justificatifs d'une charge n'étaient consultables nulle part.** La vignette du fichier
  n'était pas cliquable et aucun écran ne permettait d'ouvrir une pièce jointe : le seul moyen
  d'en ressortir une était l'export ZIP des exercices. Les fichiers s'ouvrent et se téléchargent
  désormais depuis la charge, comme les photos de bien le permettaient déjà (signalé par
  cocool97, issue #12)
- **L'export ZIP des justificatifs en oubliait certains, sans le dire.** Un fichier déposé avant
  le déplacement de la racine de stockage n'était pas trouvé, donc pas archivé, et rien ne le
  signalait — alors que le téléchargement à l'unité le servait correctement. Une archive
  incomplète qui se présente comme complète est le pire cas pour un document d'archivage
- **La colonne « Docs » est visible par défaut** sur les charges, le mobilier et les travaux :
  elle compte les pièces jointes de chaque ligne, et permettait déjà de repérer une charge sans
  justificatif — mais il fallait savoir aller l'activer dans le sélecteur de colonnes. Coût
  mesuré sur un écran de 361 px : la table des charges passe de 716 à 775 px, en deçà des
  797 px déjà en vigueur (suggéré par cocool97, issue #12)
- **Dupliquer une charge échouait dès que la colonne « Docs » était affichée.** L'agrégat
  `documents_count` posé par cette colonne était recopié par la duplication vers une colonne
  qui n'existe pas en base. Le défaut existait déjà pour qui activait la colonne à la main
- **Un justificatif déposé sans libellé était perdu sans le dire.** Le libellé était
  obligatoire, la section « Pièces justificatives » est repliée par défaut, et le message
  d'erreur tombait donc hors du champ de vision : l'utilisateur croyait avoir joint sa pièce
  alors que rien n'était enregistré et que le fichier restait dans le répertoire temporaire.
  Le libellé se pré-remplit désormais avec le nom du fichier déposé — il reste modifiable, et
  une saisie n'est jamais écrasée (signalé par cocool97, issue #12)
- **Une ventilation en pourcentage ne suivait plus la valeur du bien.** Baisser le prix ou
  monter la part du terrain rétrécissait la base amortissable en laissant les composants à leur
  ancien montant : la ventilation se retrouvait au-dessus de la base, et l'utilisateur ne
  l'apprenait qu'en générant sa liasse. Les composants ventilés en pourcentage sont désormais
  recalculés dès que la base change, quel que soit l'écran ou l'outil qui la modifie. Une base
  **saisie à la main** reste intouchée — c'est un choix explicite — et l'écart éventuel continue
  d'être signalé par les contrôles de cohérence (signalé par cocool97, issue #11)


## [1.6.0] - 2026-09-05

### Ajouts

- **La liasse vérifie désormais sa propre cohérence.** Deux montants que les formulaires
  calculent séparément sont rapprochés, dans le PDF comme sur l'écran de télédéclaration :
  la case **044** du 2033-A face à la ligne **490** du 2033-C (total des immobilisations
  brutes), et la ligne **572** face à la ligne **254** du 2033-B (dotation de l'exercice).
  C'est le contrôle demandé dans l'issue #10, en complément de la correction de calcul
  livrée en 1.5.0
- Le contrôle des immobilisations a **trois** issues, et pas deux. Un écart ne veut pas dire
  une erreur : il vaut exactement la part de votre base amortissable qui n'est rattachée à
  aucun composant. Ventiler partiellement est autorisé — l'écran l'affiche alors en
  **orange**, avec ce que ça implique : *cette part ne s'amortira pas*. Le **rouge** est
  réservé au cas inverse, des composants qui dépassent la base, en général parce que la
  valeur du bien ou la part du terrain ont changé après la ventilation
- **Les cases 044 et 048 du 2033-A sont enfin affichées** : elles étaient calculées et
  visibles nulle part, ni dans le PDF, ni à l'écran, ni dans l'export CSV. Qui recopiait le
  CSV dans son formulaire laissait donc deux vraies cases Cerfa vides
- L'aide de la télédéclaration explique les deux contrôles et ce que signifie un
  avertissement orange

### Corrections

- **Le PDF et l'écran pouvaient se contredire sur la même liasse.** Le contrôle 572 = 254
  était écrit à deux endroits avec deux comparaisons différentes — l'une stricte, l'autre
  non. Les deux s'accordaient en pratique, mais rien ne le garantissait. Les contrôles
  n'ont plus qu'une seule source, que les deux écrans se contentent d'afficher
- **`ack_number` était annoncé sans pouvoir exister.** Le numéro d'accusé de réception
  figurait dans le modèle des exercices et était renvoyé par deux outils MCP, alors
  qu'aucune colonne ne le portait en base : le champ ne pouvait jamais avoir de valeur. La
  colonne est créée. Elle reste vide sur les exercices existants — reconstituer après coup
  un numéro d'accusé serait l'inventer

### Interne

- Un garde-fou parcourt désormais **tous** les modèles et vérifie que chaque champ déclaré
  assignable existe bien en base. Le défaut s'était produit deux fois de suite au même
  endroit (`transmitted_at`, puis `ack_number`) sans jamais donner de symptôme : sous
  SQLite, filtrer sur une colonne inexistante ne lève aucune erreur et rend **toutes** les
  lignes. Le contrôle ne liste rien, il dérive de la déclaration du modèle : ajouter un
  champ sans sa migration le fait échouer tout seul

## [1.5.0] - 2026-09-05

> ⚠️ **Cette version change des liasses fiscales déjà générées.** La correction du tableau
> 2033-D (cases 982/983/984, détaillée plus bas) modifie ce que l'application imprime :
> régénérer une liasse produite par une version antérieure donnera un document différent —
> c'est voulu, l'ancien était faux. `php artisan openlmnp:repair-deficits` reconstitue le
> suivi sur les dossiers déjà tenus, en mode rapport par défaut.
>
> Ce que cet avertissement ne dit **pas** : qu'une liasse déjà déposée serait en cause. La
> première version publiée de ce logiciel date du 2 juillet 2026, soit après la campagne de
> dépôt du printemps 2026 — aucune liasse produite par une version publiée n'a donc pu partir
> dans une campagne ordinaire. Si vous avez déposé une déclaration rectificative à partir
> d'une liasse générée ici, en revanche, relisez-la.

### Ajouts

- **La démonstration annonce sa durée de vie, et on peut en repartir avec ses données.** Un
  bac à sable de démonstration s'efface au bout de quelques heures, mais rien ne le disait :
  ni les boutons qui y mènent, ni l'application une fois dedans. Un compteur permanent affiche
  désormais le temps restant, et des rappels s'échelonnent avant l'échéance — un bandeau
  discret d'abord, une fenêtre au dernier moment
- **On peut revenir sur sa démonstration.** La session se fermait bien avant le bac à sable :
  qui revenait trois heures plus tard tombait sur l'écran de connexion et concluait que tout
  était perdu, alors que ses données étaient intactes et simplement inaccessibles — le compte
  de démonstration n'ayant aucun mot de passe connu de personne. Un **lien de reprise** est
  désormais envoyé par e-mail et rouvre le bac à sable directement. Traitez-le comme une clé :
  il n'authentifie pas, il ouvre
- **Prolongation de la démonstration**, contre une adresse e-mail et un consentement explicite,
  avec le lien de reprise dans le même envoi. Les rappels déjà vus sont remis à zéro : la
  prolongation redonne un parcours complet, pas un décompte qui reprend où il en était
- **Ce qui a été saisi pendant la démonstration survit à l'ouverture d'un compte.** Le compte
  de démonstration *devient* le compte définitif : aucune donnée n'est déplacée, rien n'est à
  réimporter. Si l'adresse est déjà rattachée à un compte existant, ou si le bac à sable a
  expiré entre-temps, un compte neuf est créé comme avant — deux comptes ne sont jamais fusionnés
- **Le sort des données d'exemple est demandé une fois**, à la première connexion du compte
  ainsi ouvert : tout garder, ne garder que ses propres saisies (les totaux des exercices
  conservés sont recalculés), ou repartir de zéro. Chaque option annonce le nombre réel de
  biens et d'exercices qu'elle supprimerait, et **le choix ne se rejoue pas**
- L'aide contextuelle du tableau de bord décrit le décompte, la prolongation, le lien de
  reprise et le choix des données d'exemple, avec leurs deux pièges

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

- **Deux montants d'immobilisations qui ne concordaient pas entre le 2033-A et le 2033-C**
  (signalé par cocool97, issue #10). Les deux tableaux se trompaient **en sens inverse**, ce qui
  rendait l'écart illisible : le bilan simplifié n'inscrivait en case 028 que la valeur de
  référence du bien, sans les travaux ni le mobilier — que notre propre 2033-C listait pourtant ;
  et le 2033-C oubliait le **terrain**, qui ne s'amortit pas, ne sort d'aucune ligne de détail
  d'amortissement, et disparaissait donc du tableau alors qu'il reste une immobilisation. Les
  quatre cases tombent désormais exactement sur celles d'une liasse 2025 établie par un cabinet.
  **Si vous êtes en v1.4.5 ou antérieure, c'est ce qui explique l'écart que vous avez sous les
  yeux** — il disparaît en passant à cette version, sans rien ressaisir.

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
## [1.4.5] - 2026-09-04

### Corrections

- **L'estimation de la valeur vénale réclamait une commune déjà choisie.** L'assistant de
  création d'un bien permet de passer l'étape des surfaces. On arrivait alors sur
  l'estimation DVF, on choisissait sa commune — elle s'affichait — et l'encart continuait
  d'annoncer « choisissez une commune pour lancer l'estimation ». Au clic, l'application
  répondait « pas assez de ventes comparables », envoyant chercher un défaut dans les
  données publiques là où il manquait simplement une surface. Deux préconditions
  distinctes étaient confondues sous un seul message. La surface manquante est désormais
  nommée, dans l'encart comme dans la notification, et le fichier DVF n'est plus
  téléchargé puisqu'il n'y a rien à calculer

### Interne

- 2 tests sur les deux préconditions, dont 1 vérifié en échec sur le code précédent ;
  le second fige le message légitime d'échantillon trop mince
- `docs/TESTS.md` annonçait encore 234 tests : remis à 440

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

## [1.4.2] - 2026-09-04

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
