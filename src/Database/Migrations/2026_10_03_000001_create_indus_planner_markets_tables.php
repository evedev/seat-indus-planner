<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Player structure markets offered as price source, besides Jita.
        Schema::create('indus_planner_markets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('structure_id')->unique();
            $table->string('name');
            $table->unsignedInteger('solar_system_id')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->unsignedInteger('types_count')->default(0);
            $table->string('last_error')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });

        // Best prices of each item on a market, rebuilt at every refresh.
        Schema::create('indus_planner_market_prices', function (Blueprint $table) {
            $table->foreignId('market_id')->constrained('indus_planner_markets')->cascadeOnDelete();
            $table->unsignedInteger('type_id');
            $table->decimal('buy', 20, 2)->nullable();
            $table->decimal('sell', 20, 2)->nullable();
            $table->primary(['market_id', 'type_id']);
        });

        $this->renamePriceKeys(['jita_buy' => 'buy', 'jita_sell' => 'sell']);
    }

    public function down(): void
    {
        $this->renamePriceKeys(['buy' => 'jita_buy', 'sell' => 'jita_sell']);
        Schema::dropIfExists('indus_planner_market_prices');
        Schema::dropIfExists('indus_planner_markets');
    }

    // Prices are no longer always Jita ones: the frozen payload of the saved
    // plans follows the new key names.
    private function renamePriceKeys(array $map): void
    {
        DB::table('indus_planner_saved_plans')->select(['id', 'payload'])->orderBy('id')->chunkById(50, function ($plans) use ($map) {
            foreach ($plans as $plan) {
                $payload = json_decode($plan->payload, true);
                if (! is_array($payload) || ! is_array($payload['prices'] ?? null))
                    continue;
                foreach ($payload['prices'] as $typeId => $price) {
                    foreach ($map as $from => $to) {
                        if (is_array($price) && array_key_exists($from, $price)) {
                            $price[$to] = $price[$from];
                            unset($price[$from]);
                        }
                    }
                    $payload['prices'][$typeId] = $price;
                }
                DB::table('indus_planner_saved_plans')->where('id', $plan->id)->update(['payload' => json_encode($payload)]);
            }
        });
    }
};
