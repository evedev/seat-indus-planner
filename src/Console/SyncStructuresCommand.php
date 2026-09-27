<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Console;

use EveDev\Seat\IndusPlanner\Services\StructureSync;
use Illuminate\Console\Command;

class SyncStructuresCommand extends Command
{
    protected $signature = 'indus-planner:sync-structures';

    protected $description = 'Imports corporation industrial structures and their rigs from SeAT data.';

    public function handle(StructureSync $sync): int
    {
        $result = $sync->run();
        $this->info(sprintf('Structures: %d created, %d updated, %d deleted.',
            $result['created'], $result['updated'], $result['deleted']));

        return self::SUCCESS;
    }
}
