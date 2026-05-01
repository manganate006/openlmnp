<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PersonalSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => '***REDACTED-EMAIL***'],
            [
                'name' => 'Jérémie Bordonaro',
                'password' => Hash::make('Mbjutgo7'),
                'siren' => '953353034',
            ]
        );

        // Skip si déjà seedé (a déjà un bien)
        if ($user->properties()->exists()) {
            return;
        }

        $property = Property::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'name' => 'Appartement Airbnb - La Roquette',
            'address' => '80 allée des Jasmins',
            'city' => 'La Roquette-sur-Siagne',
            'postal_code' => '06550',
            'type' => 'room',
            'total_area' => 126,
            'rented_area' => 35,
            'acquisition_date' => '2020-06-15',
            'acquisition_price' => 57500000,
            'notary_fees' => 4600000,
            'market_value' => 73000000,
            'market_value_date' => '2026-01-01',
            'land_percentage' => 15,
            'rental_start_date' => '2023-06-01',
            'rental_type' => 'seasonal',
            'is_primary_residence' => true,
        ]);

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

        PropertyWork::create([
            'property_id' => $property->id,
            'description' => 'Piscine 46m²',
            'amount' => 4000000,
            'work_date' => '2023-04-01',
            'duration_years' => 15,
            'is_dedicated' => false,
            'annual_depreciation' => (int) bcdiv('4000000', '15', 0),
        ]);

        PropertyWork::create([
            'property_id' => $property->id,
            'description' => 'Travaux aménagement Airbnb',
            'amount' => 3200000,
            'work_date' => '2023-04-01',
            'duration_years' => 10,
            'is_dedicated' => true,
            'annual_depreciation' => (int) bcdiv('3200000', '10', 0),
        ]);

        Furniture::create([
            'property_id' => $property->id,
            'description' => 'Mobilier et équipements 2nde main',
            'amount' => 200000,
            'purchase_date' => '2023-06-01',
            'duration_years' => 5,
            'is_dedicated' => true,
            'is_second_hand' => true,
            'annual_depreciation' => (int) bcdiv('200000', '5', 0),
        ]);

        // Équipements Airbnb (montants à renseigner par l'utilisateur)
        $equipments = [
            ['Télévision', '2023-06-01', 7, false],
            ['Jacuzzi', '2023-06-01', 10, false],
            ['Réfrigérateur', '2023-06-01', 7, false],
            ['Lave-vaisselle', '2023-06-01', 7, false],
            ['Bac à douche', '2023-06-01', 10, false],
        ];
        foreach ($equipments as [$desc, $date, $dur, $secondHand]) {
            Furniture::create([
                'property_id' => $property->id,
                'description' => $desc,
                'amount' => 100, // 1€ placeholder — à modifier par l'utilisateur
                'purchase_date' => $date,
                'duration_years' => $dur,
                'is_dedicated' => true,
                'is_second_hand' => $secondHand,
                'annual_depreciation' => (int) bcdiv('100', (string) $dur, 0),
            ]);
        }

        $charges = [
            ['property_tax', 'Taxe foncière 2026', 240000, false],
            ['energy', 'Électricité annuelle', 200000, false],
            ['energy', 'Eau annuelle', 120000, false],
            ['telecom', 'Internet annuel', 40000, false],
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

        // Emprunt résidence principale
        $loan = Loan::create([
            'property_id' => $property->id,
            'bank_name' => 'Crédit immobilier',
            'amount' => 52100000, // 521 000 €
            'annual_rate' => 1.000,
            'duration_months' => 300, // 25 ans
            'start_date' => '2020-06-01',
            'monthly_payment' => 0,
            'insurance_monthly' => 7500, // 75 €/mois (2 têtes 75% : 30€ + 45€)
            'insurance_type' => 'fixed',
            'insurance_rate' => 0,
        ]);

        app(LoanService::class)->generateSchedule($loan);

        // Revenus Airbnb réels (rapports PDF Airbnb)
        $revenues = [
            // 2023 (début activité juin)
            ['2023-06-15', 21600, 778, 'Airbnb 06/2023'],
            ['2023-07-15', 107000, 3853, 'Airbnb 07/2023'],
            ['2023-08-15', 27000, 972, 'Airbnb 08/2023'],
            ['2023-09-15', 187000, 6732, 'Airbnb 09/2023'],
            ['2023-10-15', 97000, 3492, 'Airbnb 10/2023'],
            ['2023-11-15', 96000, 3456, 'Airbnb 11/2023'],
            ['2023-12-15', 107425, 3868, 'Airbnb 12/2023'],
            // 2024
            ['2024-02-15', 88641, 3192, 'Airbnb 02/2024'],
            ['2024-03-15', 74000, 2664, 'Airbnb 03/2024'],
            ['2024-04-15', 116420, 4192, 'Airbnb 04/2024'],
            ['2024-05-15', 194870, 7016, 'Airbnb 05/2024'],
            ['2024-06-15', 184184, 6631, 'Airbnb 06/2024'],
            ['2024-07-15', 352800, 12701, 'Airbnb 07/2024'],
            ['2024-08-15', 324575, 11684, 'Airbnb 08/2024'],
            ['2024-09-15', 219601, 7905, 'Airbnb 09/2024'],
            ['2024-10-15', 180075, 6483, 'Airbnb 10/2024'],
            ['2024-12-15', 111240, 10703, 'Airbnb 12/2024'],
            // 2025
            ['2025-01-15', 139556, 3649, 'Airbnb 01/2025'],
            ['2025-03-15', 167860, 6044, 'Airbnb 03/2025'],
            ['2025-05-15', 128308, 4618, 'Airbnb 05/2025'],
            ['2025-06-15', 84295, 3035, 'Airbnb 06/2025'],
            ['2025-07-15', 164885, 5936, 'Airbnb 07/2025'],
            ['2025-08-15', 463400, 16683, 'Airbnb 08/2025'],
            ['2025-09-15', 180751, 6506, 'Airbnb 09/2025'],
            ['2025-10-15', 142247, 5120, 'Airbnb 10/2025'],
            ['2025-11-15', 108869, 3919, 'Airbnb 11/2025'],
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
}
