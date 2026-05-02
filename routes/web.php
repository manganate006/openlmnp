<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/d/{path}', DocumentController::class)
    ->where('path', '.*')
    ->middleware('signed')
    ->name('documents.show');
