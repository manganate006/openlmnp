@include('errors._partial', [
    'code' => '423',
    'title' => 'Justificatif verrouillé',
    'heading' => 'Justificatif verrouillé',
    // Seul le coffre-fort renvoie 423 : son message dit quoi faire (déverrouiller, ou
    // ouvrir un fichier scellé depuis sa fiche). Le texte par défaut n'est qu'un repli.
    'message' => ($exception ?? null)?->getMessage()
        ?: 'Ce justificatif est chiffré et votre coffre-fort est verrouillé. Déverrouillez-le depuis la page Coffre-fort, puis rouvrez le document.',
    'backUrl' => url('/'),
])
