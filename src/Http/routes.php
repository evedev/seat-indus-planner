<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use EveDev\Seat\IndusPlanner\Http\Controllers\AssetController;
use EveDev\Seat\IndusPlanner\Http\Controllers\ProductionController;
use EveDev\Seat\IndusPlanner\Http\Controllers\ReactionsController;
use EveDev\Seat\IndusPlanner\Http\Controllers\SavedPlanController;
use EveDev\Seat\IndusPlanner\Http\Controllers\SetupController;
use EveDev\Seat\IndusPlanner\Http\Controllers\StructureAdminController;

// Every tool requires a SeAT session; only structure management requires
// the 'indus-planner.manage' permission.
Route::group([
    'middleware' => ['web', 'auth', 'locale'],
    'prefix' => 'indus-planner',
], function () {

    // Plugin static files (JS/CSS), served from the package.
    Route::get('/assets/{file}', [AssetController::class, 'show'])
        ->where('file', '[A-Za-z0-9._-]+')
        ->name('indus-planner.asset');

    // Industry setup: structure and asset source selection.
    Route::get('/setup', [SetupController::class, 'index'])->name('indus-planner.setup');
    Route::post('/setup/structures', [SetupController::class, 'saveStructures'])->name('indus-planner.setup.structures');
    Route::post('/setup/sources', [SetupController::class, 'saveSources'])->name('indus-planner.setup.sources');
    Route::get('/api/systems', [SetupController::class, 'systems'])->name('indus-planner.api.systems');
    Route::post('/api/structure-preview', [SetupController::class, 'preview'])->name('indus-planner.api.preview');

    Route::group(['middleware' => 'can:indus-planner.manage'], function () {
        Route::get('/structures/create', [StructureAdminController::class, 'create'])->name('indus-planner.structures.create');
        Route::post('/structures', [StructureAdminController::class, 'store'])->name('indus-planner.structures.store');
        Route::get('/structures/{structure}/edit', [StructureAdminController::class, 'edit'])->name('indus-planner.structures.edit');
        Route::put('/structures/{structure}', [StructureAdminController::class, 'update'])->name('indus-planner.structures.update');
        Route::delete('/structures/{structure}', [StructureAdminController::class, 'destroy'])->name('indus-planner.structures.destroy');
        Route::post('/structures/sync', [StructureAdminController::class, 'sync'])->name('indus-planner.structures.sync');
    });

    // Reactions
    Route::get('/reactions', [ReactionsController::class, 'index'])->name('indus-planner.reactions');
    Route::get('/api/reactions/list', [ReactionsController::class, 'list'])->name('indus-planner.api.reactions.list');
    Route::post('/api/reactions/compute', [ReactionsController::class, 'compute'])->name('indus-planner.api.reactions.compute');

    // Production
    Route::get('/production', [ProductionController::class, 'index'])->name('indus-planner.production');
    Route::get('/api/production/search', [ProductionController::class, 'search'])->name('indus-planner.api.production.search');
    Route::get('/api/production/blueprint', [ProductionController::class, 'blueprint'])->name('indus-planner.api.production.blueprint');
    Route::post('/api/production/compute', [ProductionController::class, 'compute'])->name('indus-planner.api.production.compute');

    // Production plan (jobs and purchases), shared by both tools.
    Route::post('/api/plan', [ProductionController::class, 'plan'])->name('indus-planner.api.plan');

    // Saved plans (private to their owner).
    Route::get('/plans', [SavedPlanController::class, 'index'])->name('indus-planner.plans');
    Route::post('/plans', [SavedPlanController::class, 'store'])->name('indus-planner.plans.store');
    Route::get('/plans/{plan}', [SavedPlanController::class, 'show'])->whereNumber('plan')->name('indus-planner.plans.show');
    Route::get('/plans/{plan}/data', [SavedPlanController::class, 'data'])->whereNumber('plan')->name('indus-planner.plans.data');
    Route::post('/plans/{plan}/progress', [SavedPlanController::class, 'progress'])->whereNumber('plan')->name('indus-planner.plans.progress');
    Route::post('/plans/{plan}/refresh', [SavedPlanController::class, 'refresh'])->whereNumber('plan')->name('indus-planner.plans.refresh');
    Route::put('/plans/{plan}', [SavedPlanController::class, 'rename'])->whereNumber('plan')->name('indus-planner.plans.rename');
    Route::delete('/plans/{plan}', [SavedPlanController::class, 'destroy'])->whereNumber('plan')->name('indus-planner.plans.destroy');
});
