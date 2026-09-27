<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Industrial structures: imported from corporation SeAT data (source
        // 'esi') or created by a manager (source 'manual').
        Schema::create('indus_planner_structures', function (Blueprint $table) {
            $table->id();
            $table->enum('source', ['esi', 'manual']);
            $table->unsignedBigInteger('structure_id')->nullable()->unique();
            $table->unsignedBigInteger('corporation_id')->nullable()->index();
            $table->string('name');
            $table->unsignedInteger('structure_type_id');
            $table->unsignedInteger('solar_system_id');
            $table->decimal('facility_tax_pct', 5, 2)->default(3.00);
            // Rigs entered by a manager: the synchronisation no longer
            // overwrites them.
            $table->boolean('rigs_manual')->default(false);
            // Rigs read from the corporation assets (Director token present).
            $table->boolean('rigs_known')->default(false);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('indus_planner_structure_rigs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained('indus_planner_structures')->cascadeOnDelete();
            $table->unsignedInteger('rig_type_id');
        });

        // Structures a user takes into account in their simulations.
        Schema::create('indus_planner_user_structures', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->foreignId('structure_id')->constrained('indus_planner_structures')->cascadeOnDelete();
            $table->primary(['user_id', 'structure_id']);
        });

        // Asset holders selected by a user: their characters and corporation
        // hangar divisions.
        Schema::create('indus_planner_asset_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->enum('owner_type', ['character', 'corporation']);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedTinyInteger('division')->nullable();
            $table->unique(['user_id', 'owner_type', 'owner_id', 'division'], 'indus_planner_asset_sources_unique');
        });

        // System cost indices, published by ESI.
        Schema::create('indus_planner_cost_indices', function (Blueprint $table) {
            $table->unsignedInteger('solar_system_id')->primary();
            $table->double('manufacturing')->default(0);
            $table->double('reaction')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indus_planner_cost_indices');
        Schema::dropIfExists('indus_planner_asset_sources');
        Schema::dropIfExists('indus_planner_user_structures');
        Schema::dropIfExists('indus_planner_structure_rigs');
        Schema::dropIfExists('indus_planner_structures');
    }
};
