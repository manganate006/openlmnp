@include('errors._partial', [
    'code' => '403',
    'title' => 'Accès refusé',
    'heading' => 'Accès refusé',
    // Déclencheur le plus courant vu en bêta (2026-10-15) : un dossier archivé ou
    // une appartenance révoquée pendant qu'un onglet restait ouvert dessus — mais
    // aussi un compte suspendu. `abort_if(..., 403)` ne porte aucun message propre
    // à distinguer ces cas sans risquer d'en révéler un à qui ne devrait pas le
    // savoir : un seul texte, qui couvre le cas le plus fréquent en le nommant.
    'message' => 'Vous n\'avez plus accès à cette page. Cela arrive par exemple si ce dossier '
        . 'a été archivé, ou si votre accès y a été retiré.',
    'backUrl' => url('/'),
])
