<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * System Cost Index, missing from SeAT data: read from the public ESI
 * /industry/systems/ endpoint.
 */
class CostIndexService
{
    private const URL = 'https://esi.evetech.net/latest/industry/systems/';

    /** @return int number of updated systems, -1 on failure */
    public function update(): int
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => sprintf('seat-indus-planner (contact: %s)', config('indus-planner.contact')),
            ])->acceptJson()->timeout(60)->get(self::URL);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('indus-planner: cost indices unavailable: ' . $e->getMessage());

            return -1;
        }

        if (! $response->successful()) {
            Log::channel('single')->warning('indus-planner: cost indices, HTTP ' . $response->status());

            return -1;
        }

        $now = now();
        $rows = [];
        foreach ($response->json() ?? [] as $entry) {
            if (! isset($entry['solar_system_id']))
                continue;
            $indices = [];
            foreach ($entry['cost_indices'] ?? [] as $cost) {
                if (isset($cost['activity'], $cost['cost_index']))
                    $indices[$cost['activity']] = (float) $cost['cost_index'];
            }
            $rows[] = [
                'solar_system_id' => (int) $entry['solar_system_id'],
                'manufacturing' => $indices['manufacturing'] ?? 0.0,
                'reaction' => $indices['reaction'] ?? 0.0,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('indus_planner_cost_indices')->upsert($chunk, ['solar_system_id'], ['manufacturing', 'reaction', 'updated_at']);
        }

        return count($rows);
    }
}
