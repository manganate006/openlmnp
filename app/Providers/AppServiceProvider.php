<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS behind reverse proxy
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // First registered user automatically becomes admin
        User::creating(function (User $user) {
            if (DB::table('users')->count() === 0) {
                $user->is_admin = true;
            }
        });
    }
}
