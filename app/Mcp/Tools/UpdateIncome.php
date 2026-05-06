<?php

namespace App\Mcp\Tools;

use App\Models\Income;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Met à jour un revenu locatif existant. Seuls les champs fournis sont modifiés (mise à jour partielle). Le montant est en euros.')]
#[IsDestructive]
class UpdateIncome extends Tool
{
    protected string $name = 'update_income';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'income_id'       => 'required|integer',
            'income_date'     => 'nullable|date_format:Y-m-d',
            'amount'          => 'nullable|numeric|min:0',
            'source'          => 'nullable|in:airbnb,booking,abritel,direct,other',
            'guest_name'      => 'nullable|string|max:255',
            'reservation_ref' => 'nullable|string|max:255',
            'checkin_date'    => 'nullable|date_format:Y-m-d',
            'checkout_date'   => 'nullable|date_format:Y-m-d',
            'platform_fee'    => 'nullable|numeric|min:0',
            'tourist_tax'     => 'nullable|numeric|min:0',
            'tva_rate'        => 'nullable|numeric|min:0|max:100',
            'notes'           => 'nullable|string',
        ]);

        $income = Income::findOrFail($validated['income_id']);

        // Verify ownership: the income's property must belong to the authenticated user
        Property::findOrFail($income->property_id);

        $updates = [];

        if (array_key_exists('income_date', $validated) && $validated['income_date'] !== null) {
            $updates['income_date'] = $validated['income_date'];
        }

        if (array_key_exists('amount', $validated) && $validated['amount'] !== null) {
            $updates['amount'] = (int) bcmul((string) $validated['amount'], '100', 0);
        }

        if (array_key_exists('source', $validated) && $validated['source'] !== null) {
            $updates['source'] = $validated['source'];
        }

        if (array_key_exists('guest_name', $validated)) {
            $updates['guest_name'] = $validated['guest_name'];
        }

        if (array_key_exists('reservation_ref', $validated)) {
            $updates['reservation_ref'] = $validated['reservation_ref'];
        }

        if (array_key_exists('checkin_date', $validated)) {
            $updates['checkin_date'] = $validated['checkin_date'];
        }

        if (array_key_exists('checkout_date', $validated)) {
            $updates['checkout_date'] = $validated['checkout_date'];
        }

        if (array_key_exists('platform_fee', $validated) && $validated['platform_fee'] !== null) {
            $updates['platform_fee'] = (int) bcmul((string) $validated['platform_fee'], '100', 0);
        }

        if (array_key_exists('tourist_tax', $validated) && $validated['tourist_tax'] !== null) {
            $updates['tourist_tax'] = (int) bcmul((string) $validated['tourist_tax'], '100', 0);
        }

        if (array_key_exists('tva_rate', $validated) && $validated['tva_rate'] !== null) {
            // Convert percentage to basis points (20% → 2000)
            $updates['tva_rate'] = (int) bcmul((string) $validated['tva_rate'], '100', 0);
        }

        if (array_key_exists('notes', $validated)) {
            $updates['notes'] = $validated['notes'];
        }

        $income->update($updates);
        $income->refresh();

        return Response::json([
            'success'           => true,
            'income_id'         => $income->id,
            'property_id'       => $income->property_id,
            'income_date'       => $income->income_date->toDateString(),
            'amount_eur'        => $income->amount_euros,
            'amount_ht_eur'     => $income->amount_ht_euros,
            'tva_collected_eur' => $income->tva_collected_euros,
            'platform_fee_eur'  => bcdiv((string) $income->platform_fee, '100', 2),
            'tourist_tax_cts'   => $income->tourist_tax,
            'source'            => $income->source,
            'guest_name'        => $income->guest_name,
            'reservation_ref'   => $income->reservation_ref,
            'checkin_date'      => $income->checkin_date?->toDateString(),
            'checkout_date'     => $income->checkout_date?->toDateString(),
            'notes'             => $income->notes,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'income_id'       => $schema->integer('Identifiant du revenu à mettre à jour')->required(),
            'income_date'     => $schema->string('Date du revenu au format Y-m-d')->nullable(),
            'amount'          => $schema->number('Montant TTC en euros')->nullable(),
            'source'          => $schema->string('Plateforme source : airbnb, booking, abritel, direct, other')->nullable(),
            'guest_name'      => $schema->string('Nom du voyageur')->nullable(),
            'reservation_ref' => $schema->string('Référence de réservation')->nullable(),
            'checkin_date'    => $schema->string('Date d\'arrivée au format Y-m-d')->nullable(),
            'checkout_date'   => $schema->string('Date de départ au format Y-m-d')->nullable(),
            'platform_fee'    => $schema->number('Commission plateforme en euros')->nullable(),
            'tourist_tax'     => $schema->number('Taxe de séjour en euros (ex: 2.50)')->nullable(),
            'tva_rate'        => $schema->number('Taux de TVA en pourcentage (ex: 20 pour 20%)')->nullable(),
            'notes'           => $schema->string('Notes libres')->nullable(),
        ];
    }
}
