<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    // Aucun test ne doit sortir sur Internet (fournisseur d'IA, GitHub, télémétrie…) :
    // une requête non simulée échoue au lieu d'appeler un service réel.
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature', 'Unit');
