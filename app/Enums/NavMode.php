<?php

namespace App\Enums;

enum NavMode: string
{
    case Simple = 'simple';
    case Advanced = 'advanced';
    case Guided = 'guided';
}
