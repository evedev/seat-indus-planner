<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner;

use EveDev\Seat\IndusPlanner\Console\SyncStructuresCommand;
use EveDev\Seat\IndusPlanner\Console\UpdateCostIndicesCommand;
use EveDev\Seat\IndusPlanner\Database\Seeders\ScheduleSeeder;
use EveDev\Seat\IndusPlanner\Services\SdeCatalog;
use EveDev\Seat\IndusPlanner\Services\UserContext;
use Seat\Services\AbstractSeatPlugin;

class IndusPlannerServiceProvider extends AbstractSeatPlugin
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/indus-planner.php', 'indus-planner');
        $this->mergeConfigFrom(__DIR__ . '/Config/package.sidebar.php', 'package.sidebar');

        $this->registerPermissions(__DIR__ . '/Config/permissions.php', 'indus-planner');

        // SDE tables missing from SeAT's default set: blueprints and
        // reaction formulas. Loaded by `php artisan eve:update:sde`.
        $this->registerSdeTables([
            'industryActivity',
            'industryActivityMaterials',
            'industryActivityProducts',
        ]);

        $this->registerDatabaseSeeders(ScheduleSeeder::class);

        // One context per request: the signed-in user and what they can see.
        $this->app->scoped(UserContext::class, fn () => new UserContext(auth()->user()));
        $this->app->scoped(SdeCatalog::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'indus-planner');
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'indus-planner');
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncStructuresCommand::class,
                UpdateCostIndicesCommand::class,
            ]);
        }
    }

    public function getName(): string
    {
        return 'Indus Planner';
    }

    public function getDescription(): ?string
    {
        return 'Industrial structure setup, reaction and manufacturing planning.';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/evedev/seat-indus-planner';
    }

    public function getPackagistPackageName(): string
    {
        return 'seat-indus-planner';
    }

    public function getPackagistVendorName(): string
    {
        return 'evedev';
    }
}
