<?php

namespace nexxai\EngineSwap;

use nexxai\EngineSwap\Commands\EngineSwapCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class EngineSwapServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('engine-swap')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_engine_swap_table')
            ->hasCommand(EngineSwapCommand::class);
    }
}
