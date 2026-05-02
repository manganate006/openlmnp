<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        // First registered user automatically becomes admin
        User::creating(function (User $user) {
            if (DB::table('users')->count() === 0) {
                $user->is_admin = true;
            }
        });

        // Allow private disk to generate temporary signed URLs for file previews
        Storage::disk('local')->buildTemporaryUrlsUsing(
            fn (string $path, \DateTimeInterface $expiration) => URL::temporarySignedRoute(
                'documents.show',
                $expiration,
                ['path' => $path],
            ),
        );
    }
}
