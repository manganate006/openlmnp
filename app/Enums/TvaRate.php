<?php

namespace App\Enums;

enum TvaRate: int
{
    case Exempt     = 0;       // 0 % — exonéré
    case Reduced55  = 550;     // 5,5 % — rénovation énergétique
    case Reduced10  = 1000;    // 10 % — travaux logement >2 ans, location meublée
    case Standard20 = 2000;    // 20 % — taux normal

    public function label(): string
    {
        return match ($this) {
            self::Exempt     => 'Exonéré (0 %)',
            self::Reduced55  => 'Réduit (5,5 %)',
            self::Reduced10  => 'Intermédiaire (10 %)',
            self::Standard20 => 'Normal (20 %)',
        };
    }

    /**
     * Retourne le pourcentage sous forme de chaîne bcmath (ex : '20.00').
     */
    public function percentage(): string
    {
        return bcdiv((string) $this->value, '100', 2);
    }

    /**
     * Options pour les selects Filament.
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Options sans le taux exonéré (pour les formulaires TVA-liable).
     */
    public static function liableOptions(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            if ($case !== self::Exempt) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }
}
