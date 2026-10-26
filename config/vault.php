<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Coffre-fort des justificatifs
    |--------------------------------------------------------------------------
    |
    | Chiffrement de bout en bout des fichiers déposés par l'utilisateur, avec
    | une phrase de passe qu'il choisit lui-même et que l'instance ne connaît
    | pas. Fonctionnalité optionnelle et réversible, désactivée par défaut pour
    | chaque compte ; ce drapeau ne fait que la rendre proposable ou non sur
    | l'instance (une instance auto-hébergée peut vouloir la masquer).
    |
    */

    'enabled' => env('DOCUMENTS_VAULT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Profil de dérivation de clé (Argon2id)
    |--------------------------------------------------------------------------
    |
    | La dérivation n'a lieu qu'au déverrouillage, jamais par fichier : on peut
    | donc se permettre un profil coûteux. Mesures réelles (PHP 8.4, libsodium
    | 1.0.18) : moderate ≈ 500 ms, interactive ≈ 80 ms, test ≈ 0,1 ms.
    |
    | ⚠️ Les paramètres sont des littéraux, PAS les constantes SODIUM_* : seules
    | INTERACTIVE / MODERATE / SENSITIVE sont exposées par ext-sodium, les
    | constantes *_MIN n'existent pas et référencer l'une d'elles ferait planter
    | toute la suite de tests. 8192 octets est le plancher dur d'Argon2id ; en
    | dessous, sodium_crypto_pwhash() lève une SodiumException.
    |
    | Le profil retenu à l'activation est enregistré sur la ligne du coffre :
    | changer ce réglage n'invalide donc jamais un coffre existant.
    |
    | ⚠️ Le défaut est passé de `moderate` à `interactive` le 2026-09-06, quand la
    | dérivation a migré dans le NAVIGATEUR. Deux raisons, dans cet ordre :
    |
    | 1. `moderate` demande 256 Mo à allouer dans l'onglet, à chaque déverrouillage.
    |    Un mobile peut ne pas les avoir, et un coffre qui ne s'ouvre pas sur
    |    téléphone ne protège rien — il ne sera pas utilisé.
    | 2. L'écart est plus petit qu'il n'y paraît. Le coût pour l'attaquant suit
    |    `mémoire × passes` : 768 contre 128, soit un facteur 6, c'est-à-dire
    |    **2,6 bits**. Un seul caractère aléatoire de plus dans la phrase en
    |    rapporte 4,7. Une phrase faible tombe aux deux profils, une phrase forte
    |    résiste aux deux — le KDF déplace la frontière, il ne la franchit jamais.
    |
    | Ce qui protège réellement est l'entropie de la phrase de passe, pas ce
    | réglage. Une instance qui n'ouvre son coffre que depuis des postes fixes peut
    | revenir à `moderate` sans rien casser.
    |
    */

    'kdf' => env('DOCUMENTS_VAULT_KDF', 'interactive'),

    'kdf_profiles' => [
        'moderate' => ['ops' => 3, 'mem' => 268435456],
        'interactive' => ['ops' => 2, 'mem' => 67108864],
        'test' => ['ops' => 1, 'mem' => 8192],
    ],

    /*
    |--------------------------------------------------------------------------
    | Découpage des fichiers
    |--------------------------------------------------------------------------
    |
    | Taille des blocs de clair chiffrés par crypto_secretstream. Elle est
    | écrite dans l'en-tête de chaque fichier : la modifier ici ne rend jamais
    | illisible un fichier déjà chiffré.
    |
    */

    'chunk_size' => 65536,

    /*
    |--------------------------------------------------------------------------
    | Cookie porteur de la clé de session
    |--------------------------------------------------------------------------
    |
    | La clé de données déverrouillée voyage dans un cookie chiffré par Laravel,
    | jamais dans la session serveur : le pilote de session écrit en clair sur
    | le disque, précisément celui dont le coffre protège l'utilisateur.
    |
    | Durée de vie en minutes, alignée par défaut sur celle de la session.
    |
    */

    'cookie' => [
        'name' => 'olmnp_vault_key',
        'lifetime' => (int) env('DOCUMENTS_VAULT_TTL', env('SESSION_LIFETIME', 120)),
    ],

];
