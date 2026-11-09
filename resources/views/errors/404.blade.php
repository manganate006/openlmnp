@include('errors._partial', [
    'code' => '404',
    'title' => 'Page introuvable',
    'heading' => 'Page introuvable',
    'message' => 'Cette page n\'existe pas, ou plus. L\'adresse est peut-être erronée, '
        . 'ou ce qu\'elle désignait a été supprimé.',
    'backUrl' => url('/'),
])
