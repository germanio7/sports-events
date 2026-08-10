<?php

use App\Http\Controllers\EventsController;
use App\Http\Controllers\PelotaController;
use Illuminate\Support\Facades\Route;

Route::get('/events', [EventsController::class, 'index'])->name('api.events');
Route::get('/stream', [EventsController::class, 'stream'])->name('api.stream');
Route::get('/sports', [EventsController::class, 'sports'])->name('api.sports');

Route::get('/pelota/agenda', [PelotaController::class, 'agenda'])->name('api.pelota.agenda');
