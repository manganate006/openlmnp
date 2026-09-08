<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DiagnosticReportService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Le rapport de diagnostic depuis le serveur — pendant console de l'action in-app.
 *
 * ⚠️ L'écran reste le chemin principal : un client hébergé n'a pas de console, et c'est
 * précisément ce qui avait bloqué un utilisateur devant une sur-ventilation que seule
 * `openlmnp:repair-components` savait défaire. Cette commande existe pour l'auto-hébergé qui
 * préfère le serveur, et pour joindre un rapport à une issue sans ouvrir de navigateur.
 *
 * Elle ne modifie RIEN et n'envoie rien : elle écrit sur la sortie standard.
 */
class DiagnosticCommand extends Command
{
    protected $signature = 'openlmnp:diagnostic
                            {email? : Adresse du compte à décrire (le seul compte s\'il n\'y en a qu\'un)}
                            {--year= : Exercice à décrire (défaut : année en cours)}
                            {--json : Sortie JSON plutôt que le rapport en texte}';

    protected $description = 'Produit un rapport de diagnostic sur les amortissements et la liasse (aucune donnée nominative)';

    public function handle(DiagnosticReportService $service): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return self::FAILURE;
        }

        $year = (int) ($this->option('year') ?: date('Y'));
        $report = $service->build($user, $year);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        // ⚠️ Ligne par ligne, et non le rapport entier d'un seul `line()`. Deux raisons :
        // Symfony gère mal une écriture unique de plusieurs milliers de caractères, et
        // surtout un test qui pose deux `expectsOutputToContain` ne verrait que le premier —
        // Mockery n'attribue une écriture qu'à la première attente qui la satisfait.
        //
        // ⚠️ Et chaque ligne est ÉCHAPPÉE : les intitulés viennent de l'utilisateur, et un
        // composant nommé « Toiture <2015> » ferait lever à Symfony une exception de balise
        // inconnue au lieu d'afficher le rapport.
        foreach (explode("\n", $service->toText($report)) as $line) {
            $this->line(OutputFormatter::escape($line));
        }

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $email = $this->argument('email');

        if ($email !== null) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->error("Aucun compte pour {$email}.");
            }

            return $user;
        }

        // Le cas de très loin le plus fréquent en auto-hébergé : un seul compte, et
        // demander son adresse à quelqu'un qui est seul sur son instance est du zèle.
        $users = User::query()->limit(2)->get();

        if ($users->count() === 1) {
            return $users->first();
        }

        $this->error($users->isEmpty()
            ? 'Aucun compte sur cette instance.'
            : 'Plusieurs comptes : précisez une adresse e-mail en argument.');

        return null;
    }
}
