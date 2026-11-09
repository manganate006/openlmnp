<?php

namespace App\Enums;

enum DossierMemberRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Créateur',
            self::Member => 'Indivisaire',
        };
    }
}
