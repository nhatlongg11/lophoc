<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/app/'));
Route::get('/health/ready', [HealthController::class, 'ready']);
Route::get('/reset-password/{token}', fn (string $token) => redirect('/app/?reset='.urlencode($token).'&email='.urlencode(request('email', ''))))->name('password.reset');
