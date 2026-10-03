<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Console;

use EveDev\Seat\IndusPlanner\Models\Market;
use EveDev\Seat\IndusPlanner\Services\MarketRefresher;
use Illuminate\Console\Command;
use RuntimeException;

class RefreshMarketsCommand extends Command
{
    protected $signature = 'indus-planner:refresh-markets {market? : id of a single market}';

    protected $description = 'Downloads the orders of the player structure markets used as price source.';

    public function handle(MarketRefresher $refresher): int
    {
        $markets = Market::query()->when($this->argument('market'), fn ($q, $id) => $q->where('id', $id))->get();
        $failed = 0;
        foreach ($markets as $market) {
            try {
                $result = $refresher->refresh($market);
                $this->info(sprintf('%s: %d orders, %d items.', $market->name, $result['orders'], $result['types']));
            } catch (RuntimeException $e) {
                $failed++;
                $this->warn(sprintf('%s: %s', $market->name, $e->getMessage()));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
