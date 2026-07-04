# Foire aux questions (FAQ)

## Sommaire

- [Le logiciel](#le-logiciel)
- [Le régime fiscal LMNP](#le-régime-fiscal-lmnp)
- [Vos données](#vos-données)
- [Utilisation](#utilisation)

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

### Comment sauvegarder mes données ?

Toutes vos données tiennent dans deux répertoires : la base (`database/`) et les fichiers
(`storage/`). Une sauvegarde consiste simplement à archiver ces dossiers :

```bash
tar czf openlmnp-backup-$(date +%F).tar.gz -C /opt/openlmnp-data database storage
```

En montant ces répertoires comme volumes Docker, vos données survivent aux mises à jour.
Voir la section sauvegarde de [INSTALLATION.md](INSTALLATION.md).

### Puis-je exporter mes données ?

Oui. Les recettes, charges et la télédéclaration sont exportables en **CSV**, la liasse en
**PDF**, et la comptabilité complète en **FEC**. Vos justificatifs peuvent aussi être
exportés groupés par exercice.

### Mes données sont-elles isolées si plusieurs personnes utilisent l'instance ?

Oui. OpenLMNP est **multi-utilisateurs** : chaque utilisateur ne voit et ne modifie que
ses propres biens et écritures, grâce à un cloisonnement appliqué au niveau du modèle de données.

## Utilisation

### Puis-je gérer plusieurs biens ?

Oui, sans limite. Chaque bien a ses propres composants, travaux, mobilier, recettes, charges
et emprunts, et le tableau de bord offre une vue consolidée.

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

---

Voir aussi : [INSTALLATION.md](INSTALLATION.md) · [FONCTIONNALITES.md](FONCTIONNALITES.md) · [DEMO.md](DEMO.md) · [fiscalite-lmnp-airbnb.md](fiscalite-lmnp-airbnb.md)
