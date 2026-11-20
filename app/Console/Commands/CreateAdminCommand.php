<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\RegistrationGate;
use Illuminate\Console\Command;

/**
 * Crée le compte administrateur d'une instance neuve à partir de l'environnement,
 * pour les déploiements « en un clic » (Easypanel, Dokploy, Coolify) : sans lui,
 * l'instance est publique dès le démarrage et le premier visiteur de /register
 * en devient l'administrateur.
 *
 * Lancée par docker-entrypoint.sh à chaque démarrage, elle ne fait rien dès qu'un
 * compte réel existe : un mot de passe changé depuis l'interface n'est donc jamais
 * écrasé par la valeur du déploiement.
 *
 * Le mot de passe est lu dans l'environnement du processus, jamais en argument :
 * il n'apparaît ni dans `ps`, ni dans .env.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'openlmnp:create-admin';

    protected $description = 'Crée le compte administrateur depuis OPENLMNP_ADMIN_EMAIL et OPENLMNP_ADMIN_PASSWORD, si aucun compte n\'existe encore';

    public function handle(): int
    {
        $email = trim((string) getenv('OPENLMNP_ADMIN_EMAIL'));
        $password = (string) getenv('OPENLMNP_ADMIN_PASSWORD');
        $name = trim((string) getenv('OPENLMNP_ADMIN_NAME')) ?: 'Administrateur';

        if ($email === '' || $password === '') {
            $this->error('OPENLMNP_ADMIN_EMAIL et OPENLMNP_ADMIN_PASSWORD doivent être renseignées.');

            return self::FAILURE;
        }

        if (RegistrationGate::realAccountExists()) {
            $this->info('Un compte existe déjà : rien à faire.');

            return self::SUCCESS;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('OPENLMNP_ADMIN_EMAIL n\'est pas une adresse e-mail valide.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('OPENLMNP_ADMIN_PASSWORD doit contenir au moins 8 caractères.');

            return self::FAILURE;
        }

        // `is_admin` posé explicitement : le hook « premier compte = admin »
        // d'AppServiceProvider compte aussi les sandbox démo.
        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
        ]);

        $this->info("Administrateur {$email} créé.");

        return self::SUCCESS;
    }
}
