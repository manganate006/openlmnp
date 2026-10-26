<?php

namespace App\Filament\Actions;

use App\Services\OpenData\SireneClient;
use App\Services\OpenData\SireneUnavailable;
use Closure;
use Filament\Actions\Action;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * « Vérifier dans Sirene » : actif ou cessé, date de création, code APE et sa signification
 * pour un loueur en meublé — jamais l'adresse, et le nom seulement si l'entreprise n'a pas
 * demandé la diffusion partielle.
 *
 * Une seule classe partagée par les deux écrans qui la proposent (profil, télédéclaration),
 * même patron que `RecordTransmissionAction` : pour ne jamais laisser deux boutons diverger.
 *
 * ⚠️ APPEL SORTANT sur clic explicite, jamais au chargement : `modalContent` n'est évalué
 * qu'au montage de l'action (à l'ouverture de la modale), pas au rendu de la page qui la
 * propose. `SIRENE_ENABLED=false` retire le bouton entièrement.
 *
 * @see \App\Services\OpenData\SireneClient
 */
class SireneCheckAction
{
    public const NAME = 'check_sirene';

    /**
     * @param  Closure(): ?string  $siren    le SIREN affiché par l'écran hôte (évaluée par Filament :
     *                                     elle peut déclarer `Get $get`, `$livewire`…)
     * @param  Closure(): bool|null  $visible  condition supplémentaire (en ET avec l'interrupteur
     *                                         de config) — utile pour ne proposer le bouton que
     *                                         si un SIREN est déjà renseigné
     * @param  Closure(): bool|null  $indivision  le dossier est-il déclaré en indivision ? Par
     *                                            défaut, la valeur enregistrée du compte ; le
     *                                            profil passe l'état du formulaire en cours
     */
    public static function make(Closure $siren, ?Closure $visible = null, ?Closure $indivision = null): Action
    {
        $indivision ??= fn () => (bool) auth()->user()?->is_indivision;

        return Action::make(self::NAME)
            ->label('Vérifier dans Sirene')
            ->icon('heroicon-o-check-badge')
            ->color('gray')
            // ⚠️ `$action->evaluate()`, jamais `$siren()` nu : l'écran hôte peut demander des
            // utilitaires Filament (`Get $get`, `$livewire`…), que seul `evaluate()` injecte.
            // L'assistant de création d'un dossier lié passe `fn (Get $get) => $get('siren')`
            // (état du formulaire de la modale, pas un champ du profil) : un appel nu y levait
            // « Too few arguments », d'où un 500 au clic (D2 du test bêta du 2026-10-15).
            ->visible(fn (Action $action) => (bool) config('opendata.sirene.enabled') && ($visible === null || $action->evaluate($visible)))
            ->modalHeading('Vérification Sirene')
            ->modalDescription('Le SIREN saisi est transmis à l\'API Recherche d\'entreprises '
                .'(DINUM, données Insee) ; rien d\'autre ne l\'est.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->modalContent(fn (Action $action) => self::render(
                (string) ($action->evaluate($siren) ?? ''),
                (bool) $action->evaluate($indivision),
            ));
    }

    private static function render(string $siren, bool $indivision = false): HtmlString
    {
        $digits = preg_replace('/\s+/', '', $siren) ?? '';

        if ($digits === '') {
            return new HtmlString('<p>Aucun SIREN enregistré : renseignez-le dans votre profil pour lancer la vérification.</p>');
        }

        if (! preg_match('/^\d{9}$/', $digits)) {
            return new HtmlString('<p>Ce SIREN doit compter 9 chiffres. Corrigez-le dans votre profil avant de vérifier.</p>');
        }

        if (! SireneClient::luhn($digits)) {
            return new HtmlString('<p>Ce SIREN ne passe pas le contrôle de validité (clé de Luhn) : un chiffre a '
                .'probablement été mal recopié. Corrigez-le dans votre profil avant de vérifier.</p>');
        }

        try {
            $company = app(SireneClient::class)->lookup($digits);
        } catch (SireneUnavailable $e) {
            return new HtmlString('<p>'.e($e->getMessage()).'</p>');
        }

        if ($company === null) {
            return new HtmlString('<p>Aucune entreprise ne porte ce SIREN dans la base Sirene. S\'il vient '
                .'d\'être attribué, il peut n\'y apparaître qu\'après quelques jours.</p>');
        }

        return self::summary(SireneClient::describe($company), $indivision);
    }

    /**
     * @param  array<string, mixed>  $company  résultat de `SireneClient::describe()`
     */
    private static function summary(array $company, bool $indivision = false): HtmlString
    {
        $lines = [];

        $lines[] = $company['active']
            ? '<p><strong>Entreprise active.</strong></p>'
            : '<p><strong>Entreprise cessée'.self::since($company['closed_on']).'.</strong></p>';

        if ($company['name'] !== null) {
            $lines[] = '<p>'.e($company['name']).'</p>';
        }

        if ($company['created_on'] !== null) {
            $lines[] = '<p>Créée'.self::since($company['created_on']).'.</p>';
        }

        $warnings = [];

        if (! $company['active']) {
            $warnings[] = 'Cette entreprise est déclarée cessée dans la base Sirene : votre situation mérite une vérification.';
        }

        if ($company['ape'] !== '') {
            $libelle = $company['rental'][0] ?? null;
            $lines[] = '<p>Code APE : <strong>'.e($company['ape']).'</strong>'
                .($libelle !== null ? ' — '.e($libelle) : '').'.</p>';

            if ($company['rental'] !== null) {
                $lines[] = '<p>'.e(ucfirst($company['rental'][1])).'.</p>';
            } else {
                // Deux codes sont normaux pour un loueur en meublé (68.20A, 55.20Z) : un
                // troisième n'est pas forcément une erreur, d'où « mérite une vérification »
                // et non une accusation.
                $warnings[] = 'Ce code APE n\'est ni 68.20A (location de logements) ni 55.20Z '
                    .'(hébergement touristique de courte durée) : votre situation mérite une vérification.';
            }
        }

        if ($company['legal_form'] !== '') {
            $lines[] = '<p>Forme juridique : <strong>'.e($company['legal_form_label'] ?? 'code '.$company['legal_form']).'</strong>'
                .($company['legal_form_label'] !== null ? ' ('.e($company['legal_form']).')' : '').'.</p>';

            // Les deux sens comptent : une liasse d'indivision déposée sous le SIREN personnel
            // d'un indivisaire, ou une indivision déclarée comme un dossier individuel.
            if ($indivision && ! $company['indivision']) {
                $warnings[] = 'Votre dossier est déclaré en indivision, mais ce SIREN n\'est pas celui d\'une '
                    .'indivision : la liasse doit porter le SIREN de l\'indivision, pas celui d\'un indivisaire.';
            } elseif (! $indivision && $company['indivision']) {
                $warnings[] = 'Ce SIREN est celui d\'une indivision : activez la rubrique « Indivision » de votre '
                    .'profil pour que la liasse répartisse le résultat entre indivisaires (2031 bis).';
            }
        }

        foreach ($warnings as $warning) {
            $lines[] = '<p><strong>'.e($warning).'</strong></p>';
        }

        $lines[] = '<p><em>Source : API Recherche d\'entreprises (DINUM, données Insee), Licence Ouverte 2.0.</em></p>';

        return new HtmlString(implode("\n", $lines));
    }

    private static function since(?string $date): string
    {
        if ($date === null) {
            return '';
        }

        try {
            return ' le '.Carbon::parse($date)->translatedFormat('d/m/Y');
        } catch (\Throwable) {
            return '';
        }
    }
}
