<?php

use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/d/{path}', DocumentController::class)
    ->where('path', '.*')
    ->middleware('signed')
    ->name('documents.show');

Route::get('/demo', DemoLoginController::class)
    ->middleware('throttle:30,1')
    ->name('demo.start');

Route::view('/confidentialite', 'legal.confidentialite')
    ->name('legal.confidentialite');
