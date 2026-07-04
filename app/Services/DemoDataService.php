<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;

/**
 * Crée un jeu de données fictif complet pour un utilisateur donné.
 *
 * Utilisé à la fois par :
 *  - le mode démo multi-utilisateurs (sandbox éphémère par visiteur) ;
 *  - le DemoSeeder (compte fixe demo@openlmnp.fr, rétrocompat).
 *
 * L'isolation est garantie par le user_id : chaque utilisateur possède
 * sa propre copie des données (scope global BelongsToUserScope).
 */
class DemoDataService
{
    /**
     * Crée (ou recrée) le jeu de données de démonstration pour cet utilisateur.
     *
     * Idempotent : on purge d'abord les biens existants du user avant de
     * reconstruire le jeu, ce qui évite toute duplication.
     */
    public function seedForUser(User $user): void
    {
        $this->purgeForUser($user);

        $property = Property::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'name' => 'Villa Les Oliviers',
            'address' => '12 chemin des Mimosas',
            'city' => 'Mougins',
            'postal_code' => '06250',
            'type' => 'room',
            'total_area' => 120,
            'rented_area' => 35,
            'acquisition_date' => '2020-06-15',
            'acquisition_price' => 55000000, // 550 000 €
            'notary_fees' => 4400000, // 44 000 €
            'market_value' => 70000000, // 700 000 €
            'market_value_date' => '2026-01-01',
            'land_percentage' => 15,
            'rental_start_date' => '2023-06-01',
            'rental_type' => 'seasonal',
            'is_primary_residence' => true,
        ]);

        // Composants d'amortissement
        $depBase = $property->depreciable_base;
        $components = [
            ['Gros œuvre', 50, 50, 1],
            ['Toiture', 10, 25, 2],
            ['Installations électriques', 10, 25, 3],
            ['Étanchéité', 5, 15, 4],
            ['Agencements intérieurs', 15, 15, 5],
            ['Plomberie / sanitaire', 10, 15, 6],
        ];
        foreach ($components as [$name, $pct, $dur, $sort]) {
            $base = bcmul($depBase, bcdiv((string) $pct, '100', 10), 0);
            PropertyComponent::create([
                'property_id' => $property->id,
                'name' => $name,
                'percentage' => $pct,
                'duration_years' => $dur,
                'base_amount' => (int) $base,
                'annual_depreciation' => (int) bcdiv($base, (string) $dur, 0),
                'sort_order' => $sort,
            ]);
        }

        // Travaux
        PropertyWork::create([
            'property_id' => $property->id,
            'description' => 'Piscine 40m²',
            'amount' => 3500000, // 35 000 €
            'work_date' => '2023-04-01',
            'duration_years' => 15,
            'is_dedicated' => false,
            'annual_depreciation' => (int) bcdiv('3500000', '15', 0),
        ]);

        PropertyWork::create([
            'property_id' => $property->id,
            'description' => 'Aménagement chambre et salle de bain',
            'amount' => 2800000, // 28 000 €
            'work_date' => '2023-04-01',
            'duration_years' => 10,
            'is_dedicated' => true,
            'annual_depreciation' => (int) bcdiv('2800000', '10', 0),
        ]);

        // Mobilier
        Furniture::create([
            'property_id' => $property->id,
            'description' => 'Mobilier et équipements',
            'amount' => 250000, // 2 500 €
            'purchase_date' => '2023-06-01',
            'duration_years' => 5,
            'is_dedicated' => true,
            'is_second_hand' => false,
            'annual_depreciation' => (int) bcdiv('250000', '5', 0),
        ]);

        $equipments = [
            ['Télévision 55 pouces', '2023-06-01', 7],
            ['Climatisation réversible', '2023-06-01', 10],
            ['Réfrigérateur', '2023-06-01', 7],
            ['Lave-vaisselle', '2023-06-01', 7],
        ];
        foreach ($equipments as [$desc, $date, $dur]) {
            Furniture::create([
                'property_id' => $property->id,
                'description' => $desc,
                'amount' => 50000, // 500 €
                'purchase_date' => $date,
                'duration_years' => $dur,
                'is_dedicated' => true,
                'is_second_hand' => false,
                'annual_depreciation' => (int) bcdiv('50000', (string) $dur, 0),
            ]);
        }

        // Charges
        $charges = [
            ['property_tax', 'Taxe foncière 2026', 220000, false],
            ['energy', 'Électricité annuelle', 180000, false],
            ['energy', 'Eau annuelle', 100000, false],
            ['telecom', 'Internet annuel', 36000, false],
            ['accounting', 'Logiciel comptabilité LMNP', 20000, true],
        ];
        foreach ($charges as [$cat, $desc, $amt, $ded]) {
            Expense::create([
                'property_id' => $property->id,
                'expense_date' => '2026-01-01',
                'amount' => $amt,
                'category' => $cat,
                'description' => $desc,
                'is_dedicated' => $ded,
                'recurring_type' => 'yearly',
            ]);
        }

        // Emprunt
        $loan = Loan::create([
            'property_id' => $property->id,
            'bank_name' => 'Crédit immobilier',
            'amount' => 50000000, // 500 000 €
            'annual_rate' => 1.200,
            'duration_months' => 300, // 25 ans
            'start_date' => '2020-06-01',
            'monthly_payment' => 0,
            'insurance_monthly' => 7000, // 70 €/mois
            'insurance_type' => 'fixed',
            'insurance_rate' => 0,
        ]);

        app(LoanService::class)->generateSchedule($loan);

        // Revenus Airbnb (données fictives réalistes)
        $revenues = [
            // 2023 (début activité juin)
            ['2023-06-15', 25000, 900, 'Réservation 06/2023'],
            ['2023-07-15', 110000, 3960, 'Réservation 07/2023'],
            ['2023-08-15', 135000, 4860, 'Réservation 08/2023'],
            ['2023-09-15', 85000, 3060, 'Réservation 09/2023'],
            ['2023-10-15', 60000, 2160, 'Réservation 10/2023'],
            ['2023-11-15', 40000, 1440, 'Réservation 11/2023'],
            ['2023-12-15', 55000, 1980, 'Réservation 12/2023'],
            // 2024
            ['2024-01-15', 35000, 1260, 'Réservation 01/2024'],
            ['2024-02-15', 45000, 1620, 'Réservation 02/2024'],
            ['2024-03-15', 65000, 2340, 'Réservation 03/2024'],
            ['2024-04-15', 95000, 3420, 'Réservation 04/2024'],
            ['2024-05-15', 120000, 4320, 'Réservation 05/2024'],
            ['2024-06-15', 145000, 5220, 'Réservation 06/2024'],
            ['2024-07-15', 185000, 6660, 'Réservation 07/2024'],
            ['2024-08-15', 195000, 7020, 'Réservation 08/2024'],
            ['2024-09-15', 105000, 3780, 'Réservation 09/2024'],
            ['2024-10-15', 75000, 2700, 'Réservation 10/2024'],
            ['2024-11-15', 50000, 1800, 'Réservation 11/2024'],
            ['2024-12-15', 60000, 2160, 'Réservation 12/2024'],
            // 2025
            ['2025-01-15', 40000, 1440, 'Réservation 01/2025'],
            ['2025-02-15', 50000, 1800, 'Réservation 02/2025'],
            ['2025-03-15', 70000, 2520, 'Réservation 03/2025'],
            ['2025-04-15', 100000, 3600, 'Réservation 04/2025'],
            ['2025-05-15', 130000, 4680, 'Réservation 05/2025'],
            ['2025-06-15', 155000, 5580, 'Réservation 06/2025'],
            ['2025-07-15', 200000, 7200, 'Réservation 07/2025'],
            ['2025-08-15', 210000, 7560, 'Réservation 08/2025'],
            ['2025-09-15', 110000, 3960, 'Réservation 09/2025'],
            ['2025-10-15', 80000, 2880, 'Réservation 10/2025'],
            ['2025-11-15', 55000, 1980, 'Réservation 11/2025'],
            ['2025-12-15', 65000, 2340, 'Réservation 12/2025'],
        ];

        foreach ($revenues as [$date, $amount, $fee, $guest]) {
            Income::create([
                'property_id' => $property->id,
                'income_date' => $date,
                'amount' => $amount,
                'platform_fee' => $fee,
                'tourist_tax' => 0,
                'source' => 'airbnb',
                'guest_name' => $guest,
            ]);
        }
    }

    /**
     * Supprime tous les biens (et données rattachées) de l'utilisateur,
     * afin de garantir l'idempotence du seed.
     */
    protected function purgeForUser(User $user): void
    {
        Property::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->get()
            ->each(function (Property $property) {
                PropertyComponent::where('property_id', $property->id)->delete();
                PropertyWork::where('property_id', $property->id)->delete();
                Furniture::where('property_id', $property->id)->delete();
                Expense::where('property_id', $property->id)->delete();
                Income::where('property_id', $property->id)->delete();
                $property->loans()->each(function (Loan $loan) {
                    $loan->payments()->delete();
                    $loan->delete();
                });
                $property->delete();
            });
    }
}
