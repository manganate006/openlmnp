<?php

namespace App\Filament\Pages\Concerns;

use App\Services\DiagnosticReportService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * L'action « Rapport de diagnostic », partagée par les écrans où un écart se découvre.
 *
 * ⚠️ Une ACTION dans l'interface, et pas seulement une commande console — c'est la leçon de
 * `realignToBase()` : la commande `openlmnp:repair-components` existait déjà quand un
 * utilisateur du cloud s'est retrouvé bloqué, et il n'avait aucun moyen de la lancer. Une
 * commande accompagne l'action (`openlmnp:diagnostic`) pour l'auto-hébergé qui préfère le
 * serveur, mais l'écran est le chemin principal.
 *
 * Le trait suit le patron de `ShowsVersionInfo` : la logique ici, le rendu dans une vue
 * partagée, et deux hôtes qui l'incluent sans se recopier.
 */
trait ShowsDiagnosticReport
{
    public function diagnosticReportAction(): Action
    {
        return Action::make('diagnosticReport')
            ->label('Rapport de diagnostic')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('gray')
            ->modalHeading('Rapport de diagnostic')
            ->modalDescription('À joindre à un message au support, ou à une issue GitHub. '
                . 'Relisez-le avant de l\'envoyer : il ne contient ni votre nom, ni votre adresse, '
                . 'ni votre commune, mais il porte vos montants et les intitulés que vous avez saisis.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->modalWidth('5xl')
            ->modalContent(fn () => view('filament.partials.diagnostic-report', [
                'report' => $this->diagnosticReportText(),
            ]))
            ->extraModalFooterActions([
                Action::make('downloadDiagnosticReport')
                    ->label('Télécharger (.txt)')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn () => $this->downloadDiagnosticReport()),
            ]);
    }

    public function diagnosticReportText(): string
    {
        $service = app(DiagnosticReportService::class);

        return $service->toText($service->build(auth()->user(), $this->diagnosticReportYear()));
    }

    public function downloadDiagnosticReport(): StreamedResponse
    {
        $year = $this->diagnosticReportYear();

        return Response::streamDownload(
            fn () => print($this->diagnosticReportText()),
            "openlmnp-diagnostic-{$year}.txt",
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * L'exercice décrit. Les hôtes qui en portent un (la télédéclaration) le redéfinissent ;
     * les autres décrivent l'année en cours.
     */
    protected function diagnosticReportYear(): int
    {
        return (int) now()->year;
    }
}
