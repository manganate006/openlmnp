<?php

use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\DemoResumeController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/d/{path}', DocumentController::class)
    ->where('path', '.*')
    ->middleware('signed')
    ->name('documents.show');

Route::get('/demo', DemoLoginController::class)
    ->middleware('throttle:30,1')
    ->name('demo.start');

// Reprise d'un bac à sable depuis le lien envoyé par courriel. `signed` vérifie que le lien
// vient de nous et n'a pas expiré ; le contrôleur revérifie que la cible est bien un sandbox
// encore vivant — une signature n'est pas une autorisation.
Route::get('/demo/reprendre/{user}', DemoResumeController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->name('demo.resume');

Route::view('/confidentialite', 'legal.confidentialite')
    ->name('legal.confidentialite');
