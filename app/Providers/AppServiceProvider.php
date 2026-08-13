<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

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
        // Correction pour MySQL ancien : longueur de clé maximale 1000 octets
        // utf8mb4 nécessite des clés plus courtes (191 max)
        Schema::defaultStringLength(191);
    }
}
