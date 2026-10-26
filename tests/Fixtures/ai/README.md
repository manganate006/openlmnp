# Réponses de modèle enregistrées

Chaque fichier `*.json` de ce dossier est le **contenu JSON renvoyé par le modèle**
(le `choices[0].message.content` d'une réponse OpenRouter), rejoué par `Http::fake()`
dans les tests. Aucun appel réseau n'est jamais émis par la suite de tests.

Ces jeux sont **inventés** : fournisseurs plausibles, montants plausibles, adresses
plausibles — mais aucune donnée réelle, aucune facture d'un vrai foyer fiscal. Ce dépôt
est public.

## Corpus réel — `real/`

Le sous-dossier `real/` est **gitignoré**. Il accueille le jeu d'évaluation de 30 à 50
justificatifs réels (avec leur vérité terrain) décrit au § 12.3 du cahier des charges,
utilisé par la commande manuelle :

```bash
php artisan ai:eval-documents
```

Cette commande demande une vraie clé d'API, coûte de l'argent à chaque exécution et
n'est **jamais** lancée en intégration continue.
