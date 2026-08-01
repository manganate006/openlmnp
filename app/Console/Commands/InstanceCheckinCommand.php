<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\UpdateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Check-in anonyme d'instance (télémétrie opt-out).
 *
 * Envoie au projet un identifiant aléatoire stable + la version de l'app afin de
 * compter les instances auto-hébergées. Ne transmet AUCUNE donnée comptable,
 * personnelle ou fiscale. Entièrement désactivable via TELEMETRY_ENABLED=false.
 */
class InstanceCheckinCommand extends Command
{
    protected $signature = 'app:instance-checkin';

    protected $description = 'Envoie un check-in anonyme (UUID + version) pour compter les instances. Désactivable via TELEMETRY_ENABLED=false.';

    public function handle(): int
    {
        if (! config('services.telemetry.enabled')) {
            $this->info('Télémétrie désactivée (TELEMETRY_ENABLED=false).');

            return self::SUCCESS;
        }

        $url = config('services.telemetry.url');
        if (! $url) {
            $this->info('Aucune URL de télémétrie configurée.');

            return self::SUCCESS;
        }

        try {
            Http::timeout(5)->asJson()->post($url, [
                'instance_id' => $this->instanceId(),
                'version' => (new UpdateService())->getCurrentVersion(),
                'install_type' => config('services.telemetry.install_type'),
            ]);
            $this->info('Check-in envoyé.');
        } catch (\Throwable) {
            // Silencieux : la télémétrie ne doit jamais perturber l'app.
        }

        return self::SUCCESS;
    }

    /**
     * Identifiant anonyme et stable de l'instance : UUID aléatoire généré au
     * premier appel puis persisté. Aucune donnée personnelle, non réversible.
     */
    private function instanceId(): string
    {
        $id = Setting::get('instance_id');

        if (! $id) {
            $id = (string) Str::uuid();
            Setting::set('instance_id', $id);
        }

        return $id;
    }
}
