# Fonctionnalités d'OpenLMNP

OpenLMNP couvre l'ensemble du cycle comptable et fiscal d'un loueur meublé au **régime réel** :
de la description du bien jusqu'à la production de la liasse fiscale. Toutes les fonctions
décrites ici sont incluses dans le logiciel libre, sans limitation.

## Sommaire

- [Amortissement par composant](#amortissement-par-composant)
- [Multi-biens](#multi-biens)
- [Travaux et mobilier](#travaux-et-mobilier)
- [Emprunts](#emprunts)
- [Recettes et import CSV Airbnb / Booking](#recettes-et-import-csv-airbnb--booking)
- [Charges et pièces justificatives](#charges-et-pièces-justificatives)
- [Simulateur micro-BIC vs régime réel](#simulateur-micro-bic-vs-régime-réel)
- [Projection pluriannuelle](#projection-pluriannuelle)
- [Liasse fiscale 2031 / 2033](#liasse-fiscale-2031--2033)
- [FEC conforme DGFiP](#fec-conforme-dgfip)
- [Écritures comptables automatiques](#écritures-comptables-automatiques)
- [API MCP pour assistants IA](#api-mcp-pour-assistants-ia)

## Amortissement par composant

L'amortissement est le cœur de l'intérêt fiscal du régime réel. OpenLMNP décompose le bien
en **composants** (gros œuvre, toiture, installations électriques, étanchéité, agencements
intérieurs, plomberie/sanitaire…), chacun avec sa propre durée d'amortissement.

- Ventilation **standard pré-remplie** modifiable ligne par ligne (le total doit faire 100 %)
- Calcul de la **base amortissable** : valeur du bâti hors terrain (le terrain n'est jamais amortissable)
- **Quote-part** appliquée automatiquement lorsque seule une partie du bien est louée
  (ratio surface louée / surface totale)
- **Prorata temporis** : la première et la dernière année sont calculées au nombre de jours
- **Amortissement différé** : l'amortissement ne peut pas créer de déficit ; l'excédent
  est reporté sans limite de durée et déduit sur les exercices suivants

## Multi-biens

Un même utilisateur peut gérer **plusieurs biens** meublés. Chaque bien porte ses propres
composants, travaux, mobilier, recettes, charges et emprunts. Le tableau de bord propose une
vue consolidée « Tous les biens » ainsi qu'un détail par bien.

Chaque bien décrit : adresse, surfaces (totale et louée), quote-part, date d'acquisition,
prix d'achat ou valeur vénale, frais de notaire, et part du terrain.

## Travaux et mobilier

- **Travaux** : amortis sur leur propre durée, en totalité ou au prorata selon qu'ils
  concernent la partie louée ou les parties communes
- **Mobilier et équipements** : gestion du neuf et de l'occasion, avec justificatifs,
  amortis sur des durées courtes (typiquement 5 à 10 ans)

## Emprunts

- **Tableau d'amortissement généré automatiquement** à partir du montant, du taux, de la durée
  et de la date de première échéance (prêt amortissable classique ou in fine)
- Distinction **capital / intérêts** échéance par échéance
- **Intérêts déductibles** calculés pour l'exercice, avec application de la quote-part
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
- **Récurrence** (mensuelle, trimestrielle, annuelle) pour les charges répétitives
- **Pièces justificatives** : dépôt de fichiers (PDF, JPG, PNG) attachés aux charges,
  travaux et mobilier ; export groupé des justificatifs d'un exercice

## Simulateur micro-BIC vs régime réel

Le simulateur compare, pour un exercice, le **résultat imposable** dans les deux régimes :

- **Micro-BIC** : chiffre d'affaires × (1 − taux d'abattement)
- **Régime réel** : recettes − charges déductibles − amortissements déduits

Il affiche l'**économie réalisée** en euros et rend un **verdict** clair sur le régime
le plus avantageux. C'est l'outil qui justifie le passage — ou non — au régime réel.

> Pour comprendre les règles fiscales sous-jacentes (abattements, plafonds, réforme 2026),
> voir le guide [fiscalite-lmnp-airbnb.md](fiscalite-lmnp-airbnb.md).

## Projection pluriannuelle

Un tableau projette le résultat fiscal sur **5 à 20 ans**, en tenant compte de l'extinction
progressive des amortissements (travaux, mobilier, puis composants courts). Il permet
d'anticiper l'année où le régime réel cesse d'être avantageux par rapport au micro-BIC.

## Liasse fiscale 2031 / 2033

OpenLMNP prépare les documents de la déclaration BIC au réel :

- **Télédéclaration interactive** : les lignes des formulaires Cerfa **2031**,
  **2033-A / B / C / D** et **2042-C-PRO** sont affichées avec des boutons « Copier »
  pour les reporter dans l'espace impots.gouv.fr
- **Liasse fiscale PDF** : génération complète du document au format PDF (via DomPDF)

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

---

Voir aussi : [INSTALLATION.md](INSTALLATION.md) · [DEMO.md](DEMO.md) · [FAQ.md](FAQ.md) · [fiscalite-lmnp-airbnb.md](fiscalite-lmnp-airbnb.md)
