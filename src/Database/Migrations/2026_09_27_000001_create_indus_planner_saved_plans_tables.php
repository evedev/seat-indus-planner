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
        // Production plan put aside by a user: tree, plan and choices frozen
        // when saved.
        Schema::create('indus_planner_saved_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('name');
            $table->enum('tool', ['reactions', 'production']);
            $table->unsignedInteger('root_type_id');
            $table->string('root_name');
            $table->unsignedBigInteger('quantity');
            $table->json('params');
            // Complete simulation response (tree, prices, structures, blueprints...).
            $table->longText('payload');
            // Visible tree nodes with their chosen mode, and the resulting plan.
            $table->longText('entries');
            $table->longText('jobs');
            $table->longText('purchases');
            $table->boolean('stock_known')->default(false);
            $table->json('user_modes')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });

        // Progress entered by hand; automatic progress (SeAT jobs) is
        // recomputed on every display and not stored.
        Schema::create('indus_planner_saved_plan_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('indus_planner_saved_plans')->cascadeOnDelete();
            $table->enum('kind', ['job', 'purchase']);
            $table->unsignedInteger('type_id');
            $table->unsignedBigInteger('qty_manual')->nullable();
            $table->boolean('done_manual')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'kind', 'type_id'], 'indus_planner_progress_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indus_planner_saved_plan_progress');
        Schema::dropIfExists('indus_planner_saved_plans');
    }
};
