<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Jobs;

use EveDev\Seat\IndusPlanner\Models\Market;
use EveDev\Seat\IndusPlanner\Services\MarketRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Downloads a structure market in the background: first load right after
 * the market is added, or a refresh asked from the Industry setup.
 */
class RefreshMarket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public Market $market)
    {
    }

    public function handle(MarketRefresher $refresher): void
    {
        try {
            $refresher->refresh($this->market);
        } catch (RuntimeException $e) {
            // Already recorded on the market (last_error), shown in the setup.
        }
    }
}
