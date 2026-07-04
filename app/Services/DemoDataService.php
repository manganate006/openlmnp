<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\LoanPayment;
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
            'rental_start_date' => '2022-06-01',
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

        // Travaux (réalisés avant la mise en location de juin 2022)
        PropertyWork::create([
            'property_id' => $property->id,
            'description' => 'Piscine 40m²',
            'amount' => 3500000, // 35 000 €
            'work_date' => '2022-04-01',
            'duration_years' => 15,
            'is_dedicated' => false,
            'annual_depreciation' => (int) bcdiv('3500000', '15', 0),
        ]);

        PropertyWork::create([
            'property_id' => $property->id,
            'description' => 'Aménagement chambre et salle de bain',
            'amount' => 2800000, // 28 000 €
            'work_date' => '2022-04-01',
            'duration_years' => 10,
            'is_dedicated' => true,
            'annual_depreciation' => (int) bcdiv('2800000', '10', 0),
        ]);

        // Mobilier
        Furniture::create([
            'property_id' => $property->id,
            'description' => 'Mobilier et équipements',
            'amount' => 250000, // 2 500 €
            'purchase_date' => '2022-06-01',
            'duration_years' => 5,
            'is_dedicated' => true,
            'is_second_hand' => false,
            'annual_depreciation' => (int) bcdiv('250000', '5', 0),
        ]);

        $equipments = [
            ['Télévision 55 pouces', '2022-06-01', 7],
            ['Climatisation réversible', '2022-06-01', 10],
            ['Réfrigérateur', '2022-06-01', 7],
            ['Lave-vaisselle', '2022-06-01', 7],
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

        // Charges récurrentes chaque année depuis la mise en location (2022)
        $currentYear = (int) date('Y');
        for ($year = 2022; $year <= $currentYear; $year++) {
            // Taxe foncière en hausse d'environ 50 € par an
            $propertyTax = 200000 + ($year - 2022) * 5000;

            $charges = [
                ['property_tax', "Taxe foncière {$year}", "{$year}-10-15", $propertyTax, false],
                ['insurance', "Assurance PNO {$year}", "{$year}-01-10", 18000, false],
                ['energy', "Électricité {$year}", "{$year}-02-05", 175000, false],
                ['energy', "Eau {$year}", "{$year}-02-05", 95000, false],
                ['telecom', "Internet {$year}", "{$year}-01-05", 36000, false],
                ['accounting', "Logiciel comptabilité LMNP {$year}", "{$year}-01-05", 20000, true],
            ];
            foreach ($charges as [$cat, $desc, $date, $amt, $ded]) {
                Expense::create([
                    'property_id' => $property->id,
                    'expense_date' => $date,
                    'amount' => $amt,
                    'category' => $cat,
                    'description' => $desc,
                    'is_dedicated' => $ded,
                    'recurring_type' => 'yearly',
                ]);
            }
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

        // Revenus Airbnb saisonniers, de juin 2022 (mise en location) au dernier
        // mois échu de l'année en cours. Montants déterministes : profil
        // saisonnier de référence (année 2025) avec progression de 5 % par an.
        $monthlyPattern = [
            1 => 40000, 2 => 50000, 3 => 70000, 4 => 100000, 5 => 130000, 6 => 155000,
            7 => 200000, 8 => 210000, 9 => 110000, 10 => 80000, 11 => 55000, 12 => 65000,
        ];
        $currentMonth = (int) date('n');

        for ($year = 2022; $year <= $currentYear; $year++) {
            $growth = bcadd('1', bcmul('0.05', (string) ($year - 2025), 10), 10);

            foreach ($monthlyPattern as $month => $baseAmount) {
                if ($year === 2022 && $month < 6) {
                    continue; // mise en location au 1er juin 2022
                }
                if ($year === $currentYear && $month >= $currentMonth) {
                    break; // uniquement les mois échus de l'année en cours
                }

                $amount = (int) bcmul((string) $baseAmount, $growth, 0);
                $fee = (int) bcmul((string) $amount, '0.036', 0);

                Income::create([
                    'property_id' => $property->id,
                    'income_date' => sprintf('%d-%02d-15', $year, $month),
                    'amount' => $amount,
                    'platform_fee' => $fee,
                    'tourist_tax' => 0,
                    'source' => 'airbnb',
                    'guest_name' => sprintf('Réservation %02d/%d', $month, $year),
                ]);
            }
        }

        // Chaîne d'exercices fiscaux clôturés : 2022 → N-1, dans l'ordre,
        // pour que les reports d'amortissements différés se propagent.
        $fiscalYearService = app(FiscalYearService::class);
        for ($year = 2022; $year < $currentYear; $year++) {
            $fiscalYear = $fiscalYearService->getOrCreate($user, $year);
            $fiscalYear->update(['status' => FiscalYear::STATUS_CLOSED]);
        }
    }

    /**
     * Supprime tous les biens (et données rattachées) de l'utilisateur,
     * afin de garantir l'idempotence du seed.
     */
    protected function purgeForUser(User $user): void
    {
        // withoutGlobalScopes : la purge doit fonctionner quel que soit le
        // contexte d'authentification (seeder CLI, contrôleur /demo).
        // Les écritures comptables sont supprimées en cascade (FK).
        FiscalYear::withoutGlobalScopes()->where('user_id', $user->id)->delete();

        Property::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->get()
            ->each(function (Property $property) {
                PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->delete();
                PropertyWork::withoutGlobalScopes()->where('property_id', $property->id)->delete();
                Furniture::withoutGlobalScopes()->where('property_id', $property->id)->delete();
                Expense::withoutGlobalScopes()->where('property_id', $property->id)->delete();
                Income::withoutGlobalScopes()->where('property_id', $property->id)->delete();
                Loan::withoutGlobalScopes()
                    ->where('property_id', $property->id)
                    ->get()
                    ->each(function (Loan $loan) {
                        LoanPayment::where('loan_id', $loan->id)->delete();
                        $loan->delete();
                    });
                $property->delete();
            });
    }
}
