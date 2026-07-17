<?php

namespace App\Console\Commands;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * Réinitialisation de mot de passe en ligne de commande — filet de sécurité
 * pour les instances self-hosted sans SMTP configuré.
 */
class ResetPasswordCommand extends Command
{
    protected $signature = 'openlmnp:reset-password
        {email : Adresse email du compte}
        {--password= : Définit directement ce mot de passe (8 caractères minimum)}';

    protected $description = 'Génère un lien de réinitialisation de mot de passe (ou le définit directement avec --password)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Aucun compte avec l\'adresse '.$this->argument('email'));

            return self::FAILURE;
        }

        $password = $this->option('password');

        if ($password !== null) {
            if (strlen($password) < 8) {
                $this->error('Le mot de passe doit contenir au moins 8 caractères.');

                return self::FAILURE;
            }

            $user->password = Hash::make($password);
            $user->save();
            $this->info('Mot de passe mis à jour pour '.$user->email);

            return self::SUCCESS;
        }

        // URL signée bâtie sur APP_URL (l'hôte public), pas sur l'hôte CLI.
        URL::forceRootUrl(config('app.url'));
        $token = Password::createToken($user);
        $this->info('Lien de réinitialisation (valable 60 minutes) :');
        $this->line(Filament::getResetPasswordUrl($token, $user));

        return self::SUCCESS;
    }
}
