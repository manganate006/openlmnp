# OpenLMNP -- Guide de conception UI (Filament 3)

Document de reference pour le design de l'interface utilisateur OpenLMNP.
Application de comptabilite LMNP en francais, destinee aux proprietaires non-comptables.

---

## 1. Structure de navigation (sidebar)

La navigation est organisee en groupes logiques qui suivent le parcours naturel
d'un proprietaire LMNP : configurer son bien, enregistrer ses mouvements financiers,
puis consulter ses resultats fiscaux.

### Panneau proprietaire (`/app`)

```
+----------------------------------------------------------+
|  OPENLMNP                                     [Avatar]   |
+----------------------------------------------------------+
|                                                          |
|  --- TABLEAU DE BORD ---                                 |
|  [icon: chart-bar]       Tableau de bord                 |
|                                                          |
|  --- MES BIENS ---                                       |
|  [icon: home-modern]     Biens immobiliers               |
|  [icon: wrench]          Travaux                         |
|  [icon: cube]            Mobilier et equipements         |
|  [icon: building-library] Amortissements                 |
|                                                          |
|  --- COMPTABILITE ---                                    |
|  [icon: banknotes]       Recettes                        |
|  [icon: receipt-percent]  Charges                        |
|  [icon: credit-card]     Emprunts                        |
|                                                          |
|  --- FISCAL ---                                          |
|  [icon: document-text]   Exercices fiscaux               |
|  [icon: calculator]      Simulateur                      |
|  [icon: document-duplicate] Liasse fiscale               |
|                                                          |
|  --- PARAMETRES ---                                      |
|  [icon: cog-6-tooth]     Parametres                      |
|  [icon: arrow-down-tray] Import / Export                  |
|  [icon: question-mark-circle] Aide                       |
|                                                          |
+----------------------------------------------------------+
```

### Detail des icones Heroicons (Filament 3 natif)

| Element de menu              | Icone Heroicons              | Groupe         |
|------------------------------|------------------------------|----------------|
| Tableau de bord              | `heroicon-o-chart-bar`       | --             |
| Biens immobiliers            | `heroicon-o-home-modern`     | Mes biens      |
| Travaux                      | `heroicon-o-wrench`          | Mes biens      |
| Mobilier et equipements      | `heroicon-o-cube`            | Mes biens      |
| Amortissements               | `heroicon-o-building-library`| Mes biens      |
| Recettes                     | `heroicon-o-banknotes`       | Comptabilite   |
| Charges                      | `heroicon-o-receipt-percent` | Comptabilite   |
| Emprunts                     | `heroicon-o-credit-card`     | Comptabilite   |
| Exercices fiscaux            | `heroicon-o-document-text`   | Fiscal         |
| Simulateur                   | `heroicon-o-calculator`      | Fiscal         |
| Liasse fiscale               | `heroicon-o-document-duplicate`| Fiscal       |
| Parametres                   | `heroicon-o-cog-6-tooth`     | Parametres     |
| Import / Export              | `heroicon-o-arrow-down-tray` | Parametres     |
| Aide                         | `heroicon-o-question-mark-circle`| Parametres |

### Panneau admin (`/admin`)

Reserve a l'administration de la plateforme SaaS :

```
  --- ADMINISTRATION ---
  [icon: users]            Utilisateurs
  [icon: shield-check]     Roles et permissions
  [icon: server]           Sante systeme
  [icon: cog]              Configuration globale
```

### Navigation contextuelle

- **Selecteur de bien** : un selecteur global en haut de la sidebar permet de filtrer
  toutes les vues par bien. Option "Tous les biens" pour la vue consolidee.
- **Selecteur d'exercice** : un badge affiche l'exercice en cours a cote du nom
  de l'application. Cliquer dessus permet de basculer entre exercices.
- **Badges de notification** : nombre d'elements en attente sur "Charges"
  (charges recurrentes a confirmer) et "Liasse fiscale" (si pas encore generee).

---

## 2. Tableau de bord (Dashboard)

### Disposition des widgets

Le dashboard est organise en rangees, responsive (2 colonnes sur desktop,
1 colonne sur mobile).

```
+---------------------------------------------------------------+
|  Exercice fiscal 2025                    [Selecteur annee v]   |
+---------------------------------------------------------------+
|                                                               |
|  ROW 1 -- KPIs principaux (4 StatsOverviewWidgets)            |
|  +-------------+ +-------------+ +-------------+ +-----------+|
|  | Recettes     | | Charges     | | Amortissem. | | Resultat  ||
|  | 18 240 EUR   | | 6 830 EUR   | | 4 120 EUR   | | 7 290 EUR ||
|  | +12% vs N-1  | | -3% vs N-1  | | = vs N-1    | | +28%      ||
|  +-------------+ +-------------+ +-------------+ +-----------+|
|                                                               |
|  ROW 2 -- Graphiques (2 colonnes)                             |
|  +-----------------------------+ +---------------------------+|
|  | Recettes vs Charges         | | Repartition des charges   ||
|  | (Graphe barres empilees     | | (Camembert/Donut)         ||
|  |  par mois, 12 mois)        | |                           ||
|  +-----------------------------+ +---------------------------+|
|                                                               |
|  ROW 3 -- Comparatif et amortissements (2 colonnes)           |
|  +-----------------------------+ +---------------------------+|
|  | Micro-BIC vs Reel           | | Timeline amortissements   ||
|  | +-------------------------+ | | (Graphe aires empilees    ||
|  | | Micro-BIC : 9 120 EUR   | | |  sur 5-10 ans, par       ||
|  | | (abattement 50%)        | | |  composant)               ||
|  | | Reel : 7 290 EUR        | | |                           ||
|  | | Economie : 1 830 EUR    | | | VNC totale : 142 300 EUR  ||
|  | +-------------------------+ | | Amort. differe : 0 EUR    ||
|  +-----------------------------+ +---------------------------+|
|                                                               |
|  ROW 4 -- Informations pratiques (2 colonnes)                 |
|  +-----------------------------+ +---------------------------+|
|  | Echeances a venir           | | Derniers mouvements       ||
|  | - Liasse : avant 18/05     | | - 15/04 Airbnb +320 EUR   ||
|  | - CFE : avant 15/12        | | - 12/04 EDF -85 EUR       ||
|  | - Option reel : avant 01/02| | - 10/04 Assurance -42 EUR ||
|  +-----------------------------+ +---------------------------+|
|                                                               |
|  ROW 5 -- Resume par bien (tableau, pleine largeur)           |
|  +-----------------------------------------------------------+|
|  | Bien          | Recettes | Charges | Amort. | Resultat    ||
|  | Apt. Lyon 3e  | 12 400   | 4 200   | 2 800  | 5 400      ||
|  | Chambre RP    | 5 840    | 2 630   | 1 320  | 1 890      ||
|  | TOTAL         | 18 240   | 6 830   | 4 120  | 7 290      ||
|  +-----------------------------------------------------------+|
|                                                               |
+---------------------------------------------------------------+
```

### KPIs detailles

| KPI                         | Source de donnees                        | Couleur indicateur       |
|-----------------------------|------------------------------------------|--------------------------|
| Recettes totales            | Somme Income.amount (exercice courant)   | Vert si > N-1            |
| Charges totales             | Somme Expense.amount (prorata applique)  | Rouge si > N-1           |
| Amortissements deduits      | Apres plafonnement                       | Neutre (bleu)            |
| Resultat fiscal             | Recettes - Charges - Amort. deduits      | Vert si < N-1            |
| Amortissements differes     | Stock cumule non deduit                  | Orange si > 0            |
| Economie regime reel        | Difference micro-BIC vs reel             | Vert si positif          |

### Widget "Micro-BIC vs Reel"

Ce widget est strategique car il justifie l'interet meme du logiciel.
Il affiche cote a cote :
- Le resultat imposable en micro-BIC (CA x taux abattement)
- Le resultat imposable au reel (calcul complet)
- L'economie realisee en euros et en pourcentage
- Un indicateur visuel clair : "Le regime reel vous fait economiser X EUR"

---

## 3. Palette de couleurs

### Principe directeur

Professionnel mais chaleureux. Les utilisateurs cibles sont des proprietaires,
pas des comptables. L'interface doit inspirer confiance sans etre froide.

### Palette principale

```
Primaire        : #4F46E5  (Indigo 600)     -- Actions, navigation active, liens
Primaire clair  : #818CF8  (Indigo 400)     -- Hover, bordures actives
Primaire sombre : #3730A3  (Indigo 800)     -- Texte sur fond clair

Secondaire      : #0D9488  (Teal 600)       -- Accents, recettes, indicateurs positifs
Secondaire clair: #5EEAD4  (Teal 300)       -- Badges, fonds legers

Fond principal  : #F8FAFC  (Slate 50)       -- Fond de page
Fond carte      : #FFFFFF  (Blanc)          -- Cartes et widgets
Fond sidebar    : #1E1B4B  (Indigo 950)     -- Sidebar sombre pour contraste

Texte principal : #1E293B  (Slate 800)      -- Corps de texte
Texte secondaire: #64748B  (Slate 500)      -- Labels, descriptions
Texte sidebar   : #E0E7FF  (Indigo 100)     -- Texte sur sidebar sombre

Succes          : #059669  (Emerald 600)    -- Validations, recettes
Attention       : #D97706  (Amber 600)      -- Alertes, echeances proches
Danger          : #DC2626  (Red 600)        -- Erreurs, suppressions
Info            : #2563EB  (Blue 600)       -- Informations, aide contextuelle
```

### Configuration Filament (AdminPanelProvider)

```php
use Filament\Support\Colors\Color;

->colors([
    'primary'   => Color::Indigo,
    'secondary' => Color::Teal,    // via un plugin ou custom
    'success'   => Color::Emerald,
    'warning'   => Color::Amber,
    'danger'    => Color::Red,
    'info'      => Color::Blue,
    'gray'      => Color::Slate,
])
->darkMode(false) // V1 : pas de dark mode, simplifier le dev
```

### Mode sombre (Phase 2)

Prevu pour plus tard. Quand implemente :
- Fond principal : Slate 900
- Fond carte : Slate 800
- Sidebar : Indigo 950 (inchange)
- Texte : Slate 100

---

## 4. Formulaires des ecrans principaux

### 4.1 Creation d'un bien immobilier

Formulaire en wizard (etapes) pour ne pas submerger l'utilisateur.
Filament Wizard natif avec 4 etapes.

```
ETAPE 1/4 : Informations generales
=====================================================

  Nom du bien *              [____________________________]
  (ex: "Appartement Lyon 3e")

  Type de bien *             [v Appartement            ]
                              | Maison
                              | Chambre (dans RP)
                              | Studio
                              | Autre

  Adresse *                  [____________________________]
  Code postal *   [_____]    Ville *  [__________________]

  -------------------------------------------------------
  SURFACES
  -------------------------------------------------------

  Surface totale (m2) *      [______]
  Surface louee (m2) *       [______]
  Quote-part calculee :      >> 27,8 % <<   (auto, affiche en temps reel)

  [ ] Ce bien fait partie de ma residence principale
      (coche qui active le calcul de quote-part)

                              [Precedent]  [Suivant >>]


ETAPE 2/4 : Acquisition et valorisation
=====================================================

  Date d'acquisition *       [__/__/____]
  Date de mise en location * [__/__/____]

  Mode de valorisation *     (o) Prix d'acquisition
                              ( ) Valeur venale (bien deja possede)

  --- Si prix d'acquisition : ---
  Prix d'achat (EUR) *       [__________]
  Frais de notaire (EUR)     [__________]
  [ ] Amortir les frais de notaire (sinon deduction immediate)

  --- Si valeur venale : ---
  Valeur venale (EUR) *      [__________]
  Date d'estimation *        [__/__/____]
  Base de l'estimation       [v Expertise immobiliere   ]
                              | Sites d'estimation en ligne
                              | Avis de valeur agence

  -------------------------------------------------------
  TERRAIN (non amortissable)
  -------------------------------------------------------

  Part du terrain (%) *      [___15___]
  (aide contextuelle : "En general 15-20%. En centre-ville dense,
   10-15%. En zone rurale, 20-30%.")

  Valeur du terrain :        >> 22 500 EUR <<  (calcule auto)
  Valeur construction :      >> 127 500 EUR << (calcule auto)

                              [<< Precedent]  [Suivant >>]


ETAPE 3/4 : Composants d'amortissement
=====================================================

  Utiliser la ventilation par defaut ? [Oui, standard]

  +-----------------------------------------------+
  | Composant             | %    | Duree  | Annuel |
  |                       |      | (ans)  | (EUR)  |
  |-----------------------|------|--------|--------|
  | Gros oeuvre           | 50 % | 50 ans | 1 275  |
  | Toiture               | 10 % | 25 ans |   510  |
  | Elect. / Electricite  | 10 % | 25 ans |   510  |
  | Etancheite            |  5 % | 15 ans |   425  |
  | Agencements interieurs| 15 % | 15 ans | 1 275  |
  | Plomberie / sanitaire | 10 % | 15 ans |   850  |
  |-----------------------|------|--------|--------|
  | TOTAL                 |100 % |        | 4 845  |
  +-----------------------------------------------+

  (Tableau editable inline. Chaque ligne est modifiable.
   Le total des pourcentages doit faire 100%.
   Validation en temps reel.)

  [+ Ajouter un composant]

  Amortissement annuel total : >> 4 845 EUR / an <<
  Amortissement avec quote-part (27,8%) : >> 1 347 EUR / an <<

                              [<< Precedent]  [Suivant >>]


ETAPE 4/4 : Recapitulatif
=====================================================

  +-----------------------------------------------+
  |  Appartement Lyon 3e                           |
  |  12 rue de la Republique, 69003 Lyon           |
  |                                                |
  |  Type : Appartement                            |
  |  Surface : 35 m2 / 126 m2 (quote-part 27,8%)  |
  |  Acquisition : 15/03/2022 - 150 000 EUR        |
  |  Terrain : 15% (22 500 EUR)                    |
  |  Construction : 127 500 EUR                    |
  |  Amortissement annuel : 4 845 EUR              |
  |  Amort. avec quote-part : 1 347 EUR            |
  +-----------------------------------------------+

  [ ] J'ai verifie que les informations sont correctes

                              [<< Precedent]  [Enregistrer]
```

### 4.2 Saisie d'une charge (Expense)

Formulaire simple, une seule page. Optimise pour la saisie rapide et repetitive.

```
NOUVELLE CHARGE
=====================================================

  Bien concerne *            [v Appartement Lyon 3e     ]
                              | Chambre residence princ.
                              | Tous les biens (repartie)

  Date *                     [__/__/____]  (defaut: aujourd'hui)

  Categorie *                [v Choisir une categorie... ]
                              | Taxe fonciere
                              | Assurance (PNO, habitation)
                              | Energie (electricite, gaz, eau)
                              | Entretien / reparations
                              | Fournitures (linge, consommables)
                              | Frais de gestion (plateforme)
                              | Honoraires (comptable, notaire)
                              | Telecommunications (internet)
                              | Frais de deplacement
                              | Autre

  Description *              [____________________________]
  (ex: "Facture EDF mars 2025")

  Montant TTC (EUR) *        [__________]

  -------------------------------------------------------
  PRORATA
  -------------------------------------------------------

  (o) Charge 100% dediee au bien
  ( ) Charge commune (appliquer la quote-part : 27,8%)
  ( ) Quote-part personnalisee : [____] %

  Montant deductible :       >> 85,00 EUR << (ou 23,63 EUR si QP)

  -------------------------------------------------------
  RECURRENCE
  -------------------------------------------------------

  Frequence                  [v Ponctuelle              ]
                              | Mensuelle
                              | Trimestrielle
                              | Annuelle

  (Si recurrent : date de fin optionnelle)

  -------------------------------------------------------
  JUSTIFICATIF
  -------------------------------------------------------

  [  Deposer un fichier ou cliquer pour parcourir  ]
  [  PDF, JPG, PNG -- max 10 Mo                    ]

                              [Annuler]  [Enregistrer]
                                         [Enregistrer et ajouter une autre]
```

### 4.3 Saisie des recettes (Income)

```
NOUVELLE RECETTE
=====================================================

  Bien concerne *            [v Appartement Lyon 3e     ]

  Source *                   (o) Saisie manuelle
                              ( ) Import CSV (Airbnb, Booking...)

  --- Saisie manuelle : ---

  Date *                     [__/__/____]
  Locataire / Voyageur       [____________________________]
  Reference reservation      [____________________________]

  Plateforme                 [v Aucune (location directe)]
                              | Airbnb
                              | Booking.com
                              | Abritel
                              | Autre

  Montant brut (EUR) *       [__________]
  Commission plateforme      [__________]  (deduite auto si %)
  Taxe de sejour             [__________]  (non imposable)
  Frais de menage            [__________]

  -------------------------------------------------------
  Montant net imposable :    >> 280,00 EUR <<
  -------------------------------------------------------

                              [Annuler]  [Enregistrer]
```

### 4.4 Ecran de gestion des emprunts (Loan)

```
NOUVEL EMPRUNT
=====================================================

  Bien concerne *            [v Appartement Lyon 3e     ]
  Intitule *                 [____________________________]
  (ex: "Pret immobilier CA")

  Montant emprunte (EUR) *   [__________]
  Taux annuel (%) *          [__________]
  Duree (mois) *             [__________]
  Date 1ere echeance *       [__/__/____]
  Type                       [v Amortissable classique   ]
                              | In fine

  -------------------------------------------------------
  ASSURANCE EMPRUNTEUR
  -------------------------------------------------------

  Cotisation mensuelle       [__________]
  [ ] Deductible des charges

  -------------------------------------------------------

  [Calculer le tableau d'amortissement]

  (Apres calcul, affichage du tableau avec possibilite
   d'importer un CSV bancaire a la place)

  +---------------------------------------------------+
  | N  | Date     | Echeance | Capital | Interets     |
  |----|----------|----------|---------|--------------|
  | 1  | 01/2023  | 850,00   | 520,00  | 330,00       |
  | 2  | 02/2023  | 850,00   | 521,50  | 328,50       |
  | .. | ...      | ...      | ...     | ...          |
  +---------------------------------------------------+

  Interets deductibles (exercice 2025) : >> 2 840 EUR <<
  Avec quote-part (27,8%) :              >> 789 EUR <<

                              [Annuler]  [Enregistrer]
```

### 4.5 Ecran de l'exercice fiscal (FiscalYear)

Cet ecran est le coeur de l'application. Il regroupe toutes les donnees
de l'annee et calcule le resultat fiscal.

```
EXERCICE FISCAL 2025                        [Brouillon v]
=====================================================

  --- RESUME ---

  +-------------+ +-------------+ +-------------+ +-----------+
  | Recettes     | | Charges     | | Amortissem. | | Resultat  |
  | 18 240 EUR   | | 6 830 EUR   | | 4 120 EUR   | | 7 290 EUR |
  +-------------+ +-------------+ +-------------+ +-----------+

  --- DETAIL PAR BIEN ---

  [Onglet: Tous] [Onglet: Apt. Lyon 3e] [Onglet: Chambre RP]

  RECETTES
  +---------------------------------------------------+
  | Date     | Description        | Montant  | Source  |
  |----------|--------------------|----------|---------|
  | 15/01    | Airbnb #AB123      | 320,00   | Airbnb  |
  | 22/01    | Airbnb #AB124      | 280,00   | Airbnb  |
  | ...      | ...                | ...      | ...     |
  |----------|--------------------|----------|---------|
  | TOTAL    |                    | 18 240   |         |
  +---------------------------------------------------+

  CHARGES DEDUCTIBLES
  +---------------------------------------------------+
  | Date     | Categorie      | Brut   | Deduit(QP)   |
  |----------|----------------|--------|--------------|
  | 15/01    | Assurance PNO  | 420    | 116,76       |
  | 01/02    | EDF            | 85     | 23,63        |
  | ...      | ...            | ...    | ...          |
  |----------|----------------|--------|--------------|
  | TOTAL    |                | 8 200  | 6 830        |
  +---------------------------------------------------+

  AMORTISSEMENTS
  +---------------------------------------------------+
  | Composant/Element   | Base   | Annuel | Cumule     |
  |---------------------|--------|--------|------------|
  | Gros oeuvre          | 63 750 | 1 275  | 3 825     |
  | Toiture              | 12 750 |   510  | 1 530     |
  | ...                  | ...    | ...    | ...       |
  |---------------------|--------|--------|------------|
  | Total brut          |        | 4 845  |            |
  | Plafonnement        |        |-725    |            |
  | Total deduit        |        | 4 120  |            |
  | Differe (reporte)   |        |   725  |            |
  +---------------------------------------------------+

  RESULTAT FISCAL
  +---------------------------------------------------+
  | Recettes                          |    18 240 EUR  |
  | - Charges deductibles             |   - 6 830 EUR  |
  | - Amortissements deduits          |   - 4 120 EUR  |
  | = Resultat fiscal                 | =  7 290 EUR   |
  |                                   |                |
  | Report amortissements differes    |      725 EUR   |
  | Stock total amort. differes       |    1 450 EUR   |
  +---------------------------------------------------+

  [Generer la liasse fiscale PDF]
  [Exporter le FEC]
  [Cloturer l'exercice]
```

---

## 5. Recommandations UX pour utilisateurs non-techniques

### 5.1 Onboarding guide

Au premier lancement, afficher un assistant en 5 etapes :

1. **Bienvenue** -- Explication en 2 phrases de ce que fait le logiciel
2. **Creer votre profil** -- Nom, SIREN (optionnel), regime fiscal actuel
3. **Ajouter votre premier bien** -- Wizard decrit en section 4.1
4. **Saisir vos premieres donnees** -- Pointer vers Recettes et Charges
5. **Decouvrir le tableau de bord** -- Tour guide des widgets

Chaque etape est accessible plus tard depuis le menu Aide.

### 5.2 Aide contextuelle systematique

Chaque champ complexe doit avoir une infobulle (Filament `->helperText()`) :

| Champ                    | Texte d'aide                                                        |
|--------------------------|---------------------------------------------------------------------|
| Quote-part               | "Ratio entre la surface louee et la surface totale. Sert a calculer la part deductible des charges communes." |
| Part du terrain          | "Le terrain n'est pas amortissable. En general 15-20% en ville, 20-30% en zone rurale." |
| Valeur venale            | "Si vous possediez deja le bien avant de le mettre en location, utilisez une estimation de sa valeur au moment de la mise en location." |
| Amortissement differe    | "L'amortissement ne peut pas creer de deficit. L'excedent est reporte sur les annees suivantes sans limite de duree." |
| Commission plateforme    | "La commission prelevee par Airbnb ou Booking. Elle est deductible de vos charges." |

### 5.3 Vocabulaire adapte

Eviter le jargon comptable autant que possible dans l'interface :

| Terme comptable          | Terme affiche dans l'UI                    |
|--------------------------|--------------------------------------------|
| Immobilisations          | Biens et equipements                       |
| Dotations aux amort.     | Amortissements de l'annee                  |
| VNC                      | Valeur restante a amortir                  |
| Prorata temporis         | Calcul au prorata (nombre de jours)        |
| Ecritures comptables     | Mouvements comptables                      |
| Balance generale         | Synthese des comptes                       |
| Charges constatees       | Charges previsionnelles                    |
| FEC                      | Fichier comptable officiel (FEC)           |

Garder les termes techniques entre parentheses pour que l'utilisateur
puisse les retrouver dans la documentation fiscale officielle.

### 5.4 Valeurs par defaut intelligentes

Reduire au maximum le nombre de champs a remplir en proposant des valeurs par defaut :

- **Composants d'amortissement** : ventilation standard pre-remplie
- **Part du terrain** : 15% par defaut
- **Categorie de charge** : suggestion basee sur le libelle (si "EDF" -> Energie)
- **Date** : aujourd'hui par defaut
- **Quote-part** : calculee automatiquement depuis les surfaces
- **Frequence** : ponctuelle par defaut

### 5.5 Feedback visuel immediat

- **Calculs en temps reel** : des que l'utilisateur modifie un montant ou un pourcentage,
  les valeurs derivees se mettent a jour instantanement (Livewire reactive)
- **Indicateurs colores** : vert pour les recettes, rouge pour les charges,
  bleu pour les amortissements, orange pour les alertes
- **Barres de progression** : pour l'avancement de la saisie de l'exercice
  (ex: "Recettes saisies : 10/12 mois")
- **Validation inline** : erreurs affichees sous le champ, pas en haut de page

### 5.6 Actions rapides

- **Bouton "Ajouter" flottant** sur les pages de listing : acces direct a la saisie
  sans chercher dans le menu
- **Dupliquer une charge** : pour les charges recurrentes saisies manuellement
- **Saisie en lot** : possibilite de saisir plusieurs lignes de recettes
  dans un meme formulaire (utile pour rattraper plusieurs mois)
- **"Enregistrer et ajouter une autre"** : bouton secondaire sur tous
  les formulaires de saisie

### 5.7 Pages d'aide integrees

Plutot qu'un wiki externe, integrer des pages d'aide directement dans Filament :

- **/aide/regime-reel** -- Explication du regime reel simplifie BIC
- **/aide/amortissement** -- Comment fonctionne l'amortissement par composant
- **/aide/quote-part** -- Comment calculer et justifier la quote-part
- **/aide/liasse-fiscale** -- A quoi servent les formulaires 2031/2033
- **/aide/declaration-revenus** -- Comment remplir la 2042-C-PRO

Chaque page d'aide est accessible depuis un lien contextuel a cote
du formulaire concerne.

### 5.8 Gestion des erreurs bienveillante

Ne jamais afficher de message technique brut. Exemples :

| Situation                        | Message affiche                                                |
|----------------------------------|----------------------------------------------------------------|
| Total composants != 100%         | "La somme des pourcentages doit etre egale a 100%. Il manque 5%." |
| Exercice sans recette            | "Aucune recette saisie pour 2025. Voulez-vous importer depuis Airbnb ?" |
| Suppression d'un bien            | "Attention : supprimer ce bien supprimera aussi toutes ses charges, recettes et amortissements. Cette action est irreversible." |
| Cloturer exercice incomplet      | "Il manque des informations pour cloturer : 3 charges recurrentes non confirmees." |

### 5.9 Responsive et mobile

L'interface doit etre utilisable sur tablette et telephone :

- La saisie d'une charge sur telephone doit etre possible (cas d'usage :
  photographier un justificatif et saisir la charge immediatement)
- Les tableaux longs utilisent le scroll horizontal sur mobile
- Le dashboard empile les widgets en une colonne
- Le wizard de creation de bien fonctionne etape par etape sur mobile

### 5.10 Securite perceptible

Pour rassurer les utilisateurs sur la protection de leurs donnees financieres :

- Afficher un petit cadenas a cote de "Vos donnees sont stockees localement"
  dans le footer (en mode self-hosted)
- Page Parametres > Securite avec les informations de chiffrement
- Bouton "Exporter toutes mes donnees" visible et accessible

---

## 6. Specifications des composants Filament

### Resources Filament a creer

| Resource                | Model          | Pages                       | Notes                                    |
|-------------------------|----------------|-----------------------------|------------------------------------------|
| PropertyResource        | Property       | List, Create (wizard), Edit | Selecteur global, relations imbriquees    |
| ExpenseResource         | Expense        | List, Create, Edit          | Filtres par categorie, bien, periode      |
| IncomeResource          | Income         | List, Create, Edit          | Import CSV en action de page              |
| LoanResource            | Loan           | List, Create, Edit, View    | Tableau d'amortissement en View           |
| WorkResource            | PropertyWork   | List, Create, Edit          | Lie a un bien                            |
| FurnitureResource       | Furniture      | List, Create, Edit          | Lie a un bien                            |
| DepreciationResource    | --             | List (read-only)            | Vue calculee, pas de CRUD direct          |
| FiscalYearResource      | FiscalYear     | List, View, Edit            | Vue detaillee = ecran section 4.5        |
| TaxReturnResource       | TaxReturn      | List, View                  | Generation PDF en action                  |

### Widgets du Dashboard

| Widget                           | Type                      | Colonnes |
|----------------------------------|---------------------------|----------|
| FiscalKpisWidget                 | StatsOverviewWidget       | full     |
| RevenueVsExpensesChartWidget     | ChartWidget (bar)         | 1/2      |
| ExpenseCategoriesChartWidget     | ChartWidget (doughnut)    | 1/2      |
| MicroBicVsReelWidget             | Widget custom (card)      | 1/2      |
| DepreciationTimelineWidget       | ChartWidget (area)        | 1/2      |
| UpcomingDeadlinesWidget          | Widget custom (list)      | 1/2      |
| RecentTransactionsWidget         | TableWidget               | 1/2      |
| PropertySummaryWidget            | TableWidget               | full     |

### Filtres globaux

Un `TenantFilter` ou equivalent Filament pour filtrer par bien immobilier.
Implemente via un selecteur en haut de la sidebar ou dans le header.

Le filtre s'applique a : Recettes, Charges, Amortissements, Emprunts.
Le dashboard montre un resume par bien quand "Tous les biens" est selectionne.

---

## 7. Typographie et espacement

### Police

Utiliser la police par defaut de Filament (Inter) qui est excellente pour
les interfaces de donnees financieres : lisible, professionnelle,
bonne distinction des chiffres.

### Hierarchie des tailles

| Usage                    | Taille       | Poids      |
|--------------------------|-------------|------------|
| Titre de page            | text-2xl    | font-bold  |
| Titre de section         | text-lg     | font-semibold |
| Label de champ           | text-sm     | font-medium |
| Valeur dans un KPI       | text-3xl    | font-bold  |
| Texte d'aide             | text-xs     | font-normal |
| Montant en EUR           | tabular-nums| font-medium |

### Formatage des montants

- Toujours utiliser le separateur de milliers : `18 240 EUR` (espace insecable)
- Toujours 2 decimales pour les centimes : `85,00 EUR`
- Signe EUR apres le montant (convention francaise)
- Montants negatifs en rouge : `-725,00 EUR`
- Classe CSS `tabular-nums` pour l'alignement des colonnes de chiffres

---

## 8. Checklist d'implementation

Ordre de developpement recommande pour la partie UI :

- [ ] Configurer le AdminPanelProvider avec la palette de couleurs
- [ ] Creer PropertyResource avec le wizard 4 etapes
- [ ] Creer ExpenseResource avec les filtres et le bouton "Enregistrer et ajouter"
- [ ] Creer IncomeResource avec l'action d'import CSV
- [ ] Creer LoanResource avec le calcul du tableau d'amortissement
- [ ] Creer la vue Amortissements (lecture seule, calculee)
- [ ] Creer FiscalYearResource avec l'ecran de synthese
- [ ] Implementer le dashboard avec les 8 widgets
- [ ] Ajouter le selecteur de bien global
- [ ] Ajouter le selecteur d'exercice dans le header
- [ ] Creer les pages d'aide integrees
- [ ] Implementer l'onboarding guide
- [ ] Tester la responsivite mobile
- [ ] Valider l'accessibilite (contraste, navigation clavier, labels ARIA)
