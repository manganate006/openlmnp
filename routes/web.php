<?php

use Illuminate\Support\Facades\Route;

// Rediriger la racine vers le dashboard Filament
Route::get('/', function () {
    return redirect('/login');
});
