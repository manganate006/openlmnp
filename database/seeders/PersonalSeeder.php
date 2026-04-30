<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\Furniture;
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
        $user = User::create([
            'name' => 'Jérémie Bordonaro',
            'email' => '***REDACTED-EMAIL***',
            'password' => Hash::make('Mbjutgo7'),
        ]);

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
            'insurance_monthly' => 0,
        ]);

        app(LoanService::class)->generateSchedule($loan);
    }
}
