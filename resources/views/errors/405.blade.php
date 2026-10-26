@include('errors._partial', [
    'code' => '405',
    'title' => 'Méthode non autorisée',
    'heading' => 'Ce lien ne peut pas s\'ouvrir directement',
    // Cas vu en bêta (2026-10-15) : un GET forgé sur une route d'action volontairement
    // POST uniquement (ex. bascule de dossier) — la page vendor par défaut répondait
    // en anglais, sans aucune piste.
    'message' => 'Cette action n\'est accessible que depuis un bouton de l\'application, '
        . 'pas en ouvrant directement son adresse.',
    'backUrl' => url('/'),
])
