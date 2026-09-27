<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use EveDev\Seat\IndusPlanner\Domain\Constants;
use EveDev\Seat\IndusPlanner\Models\Structure;
use EveDev\Seat\IndusPlanner\Models\StructureRig;
use Illuminate\Support\Facades\DB;

/**
 * Imports corporation industrial structures from data already synchronised
 * by SeAT, without any ESI call:
 *  - `corporation_structures` (Station Manager / Director token): type and
 *    system of each structure;
 *  - `universe_structures`: its name;
 *  - `corporation_assets`, RigSlot* flags (Director token): its rigs.
 *
 * Only industrial structures (Raitaru, Azbel, Sotiyo, Athanor, Tatara) are
 * kept. Rigs entered by a manager are never overwritten.
 */
class StructureSync
{
    public function __construct(private SdeCatalog $sde)
    {
    }

    /** @return array{created:int, updated:int, deleted:int} */
    public function run(): array
    {
        $rows = DB::table('corporation_structures')
            ->whereIn('type_id', array_keys(Constants::STRUCTURE_TYPES))
            ->get(['corporation_id', 'structure_id', 'type_id', 'system_id']);

        $structureIds = $rows->pluck('structure_id')->all();
        $names = DB::table('universe_structures')->whereIn('structure_id', $structureIds)->pluck('name', 'structure_id');
        $corporationsWithAssets = DB::table('corporation_assets')
            ->whereIn('corporation_id', $rows->pluck('corporation_id')->unique())
            ->distinct()->pluck('corporation_id')->flip();

        $industrialRigs = $this->sde->rigs();
        $rigsByStructure = [];
        foreach (DB::table('corporation_assets')
            ->whereIn('location_id', $structureIds)
            ->where('location_flag', 'like', 'RigSlot%')
            ->orderBy('location_flag')
            ->get(['location_id', 'type_id']) as $rig) {
            if (isset($industrialRigs[(int) $rig->type_id]))
                $rigsByStructure[(int) $rig->location_id][] = (int) $rig->type_id;
        }

        $created = $updated = 0;
        DB::transaction(function () use ($rows, $names, $corporationsWithAssets, $rigsByStructure, &$created, &$updated) {
            foreach ($rows as $row) {
                $structure = Structure::firstOrNew(['structure_id' => $row->structure_id]);
                $structure->exists ? $updated++ : $created++;

                $structure->fill([
                    'source' => 'esi',
                    'corporation_id' => $row->corporation_id,
                    'name' => $names[$row->structure_id] ?? "Structure {$row->structure_id}",
                    'structure_type_id' => $row->type_id,
                    'solar_system_id' => $row->system_id,
                    'rigs_known' => isset($corporationsWithAssets[$row->corporation_id]),
                ]);
                if (! $structure->exists)
                    $structure->facility_tax_pct = config('indus-planner.default_facility_tax');
                $structure->save();

                if (! $structure->rigs_manual && $structure->rigs_known) {
                    StructureRig::where('structure_id', $structure->id)->delete();
                    foreach (array_slice($rigsByStructure[(int) $row->structure_id] ?? [], 0, 3) as $rigTypeId) {
                        StructureRig::create(['structure_id' => $structure->id, 'rig_type_id' => $rigTypeId]);
                    }
                }
            }
        });

        // Structures gone (destroyed, unanchored, corporation removed from SeAT).
        $deleted = Structure::where('source', 'esi')->whereNotIn('structure_id', $structureIds)->delete();

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted];
    }
}
