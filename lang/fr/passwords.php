<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lignes de langue — réinitialisation du mot de passe
    |--------------------------------------------------------------------------
    |
    | Ces lignes correspondent aux différents statuts renvoyés par le password
    | broker de Laravel. Filament les affiche comme titre de notification sur
    | les pages /password-reset/request et /password-reset/reset : sans ce
    | fichier, le titre retombe en anglais alors que le corps du message vient
    | de Filament, traduit, d'où un affichage bilingue.
    |
    */

    'reset' => 'Votre mot de passe a été réinitialisé.',
    'sent' => 'Le lien de réinitialisation vous a été envoyé par email.',
    'throttled' => 'Veuillez patienter avant de réessayer.',
    'token' => 'Ce lien de réinitialisation est invalide ou a expiré.',
    'user' => 'Aucun compte ne correspond à cette adresse email.',

];
