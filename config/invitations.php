<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Durée de validité d'une invitation, en jours
    |--------------------------------------------------------------------------
    |
    | ⚠️ Volontairement INDÉPENDANTE de `auth.passwords.users.expire` (60 minutes).
    | Ce délai-là est celui d'un lien demandé par quelqu'un qui est devant son
    | écran ; une invitation part vers une boîte qu'on relève peut-être le
    | lendemain. Les allonger ensemble allongerait AUSSI tous les liens de
    | réinitialisation de mot de passe de l'instance — c'est la raison pour
    | laquelle on ne réutilise pas `Password::createToken()` ici.
    |
    | La valeur est figée sur la ligne `invitations.expires_at` à la création :
    | changer ce réglage ne rallonge pas les invitations déjà envoyées.
    |
    */
    'ttl_days' => (int) env('INVITATION_TTL_DAYS', 14),

];
