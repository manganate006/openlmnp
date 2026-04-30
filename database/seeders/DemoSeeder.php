<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'name' => 'Jérémie Bordonaro',
            'email' => 'demo@openlmnp.fr',
            'password' => Hash::make('demo1234'),
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
            'acquisition_price' => 57500000, // 575 000 €
            'notary_fees' => 4600000, // 46 000 €
            'market_value' => 73000000, // 730 000 € (estimation 2026)
            'market_value_date' => '2026-01-01',
            'land_percentage' => 15,
            'rental_start_date' => '2023-06-01',
            'rental_type' => 'seasonal',
            'is_primary_residence' => true,
        ]);

        // Composants d'amortissement standards
        $components = [
            ['name' => 'Gros œuvre', 'percentage' => 50, 'duration_years' => 50, 'sort_order' => 1],
            ['name' => 'Toiture', 'percentage' => 10, 'duration_years' => 25, 'sort_order' => 2],
            ['name' => 'Installations électriques', 'percentage' => 10, 'duration_years' => 25, 'sort_order' => 3],
            ['name' => 'Étanchéité', 'percentage' => 5, 'duration_years' => 15, 'sort_order' => 4],
            ['name' => 'Agencements intérieurs', 'percentage' => 15, 'duration_years' => 15, 'sort_order' => 5],
            ['name' => 'Plomberie / sanitaire', 'percentage' => 10, 'duration_years' => 15, 'sort_order' => 6],
        ];

        $depreciableBase = $property->depreciable_base;

        foreach ($components as $comp) {
            $baseAmount = bcmul($depreciableBase, bcdiv((string) $comp['percentage'], '100', 10), 0);
            $annualDepreciation = bcdiv($baseAmount, (string) $comp['duration_years'], 0);

            PropertyComponent::create([
                'property_id' => $property->id,
                'name' => $comp['name'],
                'percentage' => $comp['percentage'],
                'duration_years' => $comp['duration_years'],
                'base_amount' => (int) $baseAmount,
                'annual_depreciation' => (int) $annualDepreciation,
                'sort_order' => $comp['sort_order'],
            ]);
        }

        // Charges d'exemple (pas de recettes fictives)
        Expense::create([
            'property_id' => $property->id,
            'expense_date' => '2026-01-15',
            'amount' => 240000, // 2400 €
            'category' => 'property_tax',
            'description' => 'Taxe foncière 2026',
            'is_dedicated' => false,
            'recurring_type' => 'yearly',
        ]);

        Expense::create([
            'property_id' => $property->id,
            'expense_date' => '2026-01-01',
            'amount' => 200000, // 2000 €
            'category' => 'energy',
            'description' => 'Électricité annuelle',
            'is_dedicated' => false,
            'recurring_type' => 'yearly',
        ]);

        Expense::create([
            'property_id' => $property->id,
            'expense_date' => '2026-03-01',
            'amount' => 60000, // 600 €
            'category' => 'accounting',
            'description' => 'Logiciel comptabilité LMNP',
            'is_dedicated' => true,
            'recurring_type' => 'yearly',
        ]);
    }
}
