<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pqrsd:sync-correos --sync')
    ->everyFiveMinutes()
    ->timezone('America/Bogota')
    ->withoutOverlapping(15)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/correo-sync.log'));

Schedule::command('pqrsd:actualizar-cumplimiento')
    ->everyMinute()
    ->timezone('America/Bogota')
    ->withoutOverlapping(5)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/sla-cumplimiento.log'));

Schedule::command('pqrsd:gmail-renew-watch')
    ->dailyAt('03:15')
    ->timezone('America/Bogota')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/correo-sync.log'));

Schedule::command('pqrsd:ops-health')
    ->everyFiveMinutes()
    ->timezone('America/Bogota')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/ops-health.log'));

Schedule::command('pqrsd:backup --only=all')
    ->dailyAt('02:30')
    ->timezone('America/Bogota')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/respaldos.log'));
