<?php

namespace App\Services\OpenData;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Estime la valeur vénale d'un bien à partir des ventes réelles de sa commune (DVF).
 *
 * À quoi ça sert, concrètement : quand le bien était possédé AVANT la mise en location, la
 * base amortissable n'est pas le prix d'achat mais la valeur vénale à la date de mise en
 * location (BOI-BIC-AMT-10-30-10). Sur un bien acheté dix ans plus tôt, l'écart entre les
 * deux se compte en dizaines de milliers d'euros d'amortissement — et c'est aujourd'hui le
 * champ le plus souvent rempli au jugé de tout le formulaire.
 *
 * Ce que ça n'est PAS : une expertise. Une médiane communale ignore l'étage, l'état,
 * l'exposition et le DPE. L'écran le dit, et restitue l'échantillon (nombre de ventes,
 * millésimes) pour que le chiffre soit justifiable et pas seulement affiché.
 */
class MarketValueEstimator
{
    /** Au-delà, la médiane mélangerait des millésimes trop éloignés du marché visé. */
    private const MAX_YEARS_WIDENED = 3;

    /**
     * Types de biens de l'application → types de locaux DVF.
     *
     * Une chambre et un studio se comparent aux appartements : DVF ne connaît que « maison »
     * et « appartement » pour l'habitation.
     */
    private const TYPES = [
        'house' => 'maison',
        'apartment' => 'appartement',
        'room' => 'appartement',
        'studio' => 'appartement',
        'other' => 'appartement',
    ];

    public function __construct(
        private readonly CommuneResolver $communes,
        private readonly DvfClient $dvf,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('opendata.dvf.enabled');
    }

    /**
     * @return array<int, int>
     */
    public static function years(): array
    {
        return DvfClient::years();
    }

    /**
     * Communes candidates pour une saisie libre (nom, code postal ou code INSEE).
     *
     * @return array<int, array{code: string, nom: string, departement: string, code_postal: string}>
     *
     * @throws DvfUnavailable
     */
    public function communes(string $query): array
    {
        $this->guard();

        return $this->communes->search($query);
    }

    /**
     * Estimation pour une commune déjà identifiée.
     *
     * @return array<string, mixed>
     *
     * @throws DvfUnavailable
     */
    public function estimate(string $insee, string $propertyType, int $area, ?int $year = null): array
    {
        $this->guard();

        $type = self::TYPES[$propertyType] ?? 'appartement';
        $minimum = (int) config('opendata.dvf.min_sample');
        $samples = [];
        $used = [];

        foreach ($this->yearsByProximity($year) as $candidate) {
            $samples = array_merge($samples, $this->dvf->samples($insee, $candidate));
            $used[] = $candidate;

            $estimate = DvfEstimator::estimate($samples, $type, $area, $used);

            if ($estimate['sample_size'] >= $minimum || count($used) >= self::MAX_YEARS_WIDENED) {
                return $estimate + ['minimum' => $minimum, 'enough' => $estimate['sample_size'] >= $minimum];
            }
        }

        $estimate = DvfEstimator::estimate($samples, $type, $area, $used);

        return $estimate + ['minimum' => $minimum, 'enough' => false];
    }

    /**
     * Millésimes ordonnés par proximité avec l'année visée, le plus récent départageant.
     *
     * @return array<int, int>
     */
    private function yearsByProximity(?int $target): array
    {
        $years = DvfClient::years();

        if ($target === null || ! in_array($target, $years, true)) {
            $target = $years[0] ?? 0;
        }

        usort($years, fn (int $a, int $b) => [abs($a - $target), -$a] <=> [abs($b - $target), -$b]);

        return $years;
    }

    /**
     * Fonctionnalité coupée, ou utilisateur trop pressé.
     *
     * Le débit n'est pas une facturation : c'est ce qui évite qu'un clic répété martèle
     * data.gouv.fr depuis une instance partagée.
     *
     * @throws DvfUnavailable
     */
    private function guard(): void
    {
        if (! self::enabled()) {
            throw DvfUnavailable::disabled();
        }

        $key = 'dvf:'.(Auth::id() ?? 'guest');

        if (RateLimiter::tooManyAttempts($key, (int) config('opendata.dvf.rate_limit'))) {
            throw DvfUnavailable::throttled();
        }

        RateLimiter::hit($key, 60);
    }
}
