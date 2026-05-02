<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BadgeService;
use Illuminate\Console\Command;

class BackdateBadges extends Command
{
    protected $signature = 'badges:backdate {--user= : ID utilisateur specifique}';
    protected $description = 'Attribue retroactivement les badges merités aux utilisateurs existants';

    public function handle(): int
    {
        $service = app(BadgeService::class);
        $totalAwarded = 0;

        $query = User::query();
        if ($userId = $this->option('user')) {
            $query->where('id', $userId);
        }

        $users = $query->get();

        $this->info("Evaluation des badges pour {$users->count()} utilisateur(s)...");

        foreach ($users as $user) {
            $awarded = $service->evaluateAll($user, silent: true);
            if ($awarded > 0) {
                $this->line("  {$user->name} : {$awarded} badge(s) attribue(s)");
                $totalAwarded += $awarded;
            }
        }

        $this->info("Termine. {$totalAwarded} badge(s) attribue(s) au total.");

        return self::SUCCESS;
    }
}
