<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Console;

use EveDev\Seat\IndusPlanner\Services\CostIndexService;
use Illuminate\Console\Command;

class UpdateCostIndicesCommand extends Command
{
    protected $signature = 'indus-planner:cost-indices';

    protected $description = 'Updates system cost indices from ESI.';

    public function handle(CostIndexService $service): int
    {
        $count = $service->update();
        if ($count < 0) {
            $this->error('Update failed (see storage/logs/laravel.log).');

            return self::FAILURE;
        }
        $this->info("Cost indices: {$count} system(s).");

        return self::SUCCESS;
    }
}
