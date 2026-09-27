<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use EveDev\Seat\IndusPlanner\Models\AssetSource;
use Illuminate\Support\Facades\DB;

/**
 * Stock and blueprints owned by the holders the user selected: their
 * characters (all their assets) and corporation hangar divisions (limited to
 * the ones their in-game roles allow).
 */
class AssetService
{
    private ?array $sources = null;
    private array $locationNames = [];

    public function __construct(private UserContext $context)
    {
    }

    // -----------------------------------------------------------------
    // Sources
    // -----------------------------------------------------------------

    /**
     * Effective sources: the saved ones, filtered by what the user is still
     * allowed to see.
     *
     * @return array{characters: int[], corporations: array<int, int[]>}
     */
    public function sources(): array
    {
        if ($this->sources !== null)
            return $this->sources;

        $characters = $this->context->characters();
        $allowed = $this->context->allowedDivisions();
        $out = ['characters' => [], 'corporations' => []];

        foreach (AssetSource::where('user_id', $this->context->user->id)->get() as $source) {
            if ($source->owner_type === 'character' && isset($characters[$source->owner_id])) {
                $out['characters'][] = (int) $source->owner_id;
            } elseif ($source->owner_type === 'corporation' && in_array((int) $source->division, $allowed[$source->owner_id] ?? [], true)) {
                $out['corporations'][(int) $source->owner_id][] = (int) $source->division;
            }
        }

        return $this->sources = $out;
    }

    public function hasSources(): bool
    {
        $s = $this->sources();

        return ! empty($s['characters']) || ! empty($s['corporations']);
    }

    /**
     * Saves the user's selection.
     *
     * @param  int[]  $characterIds
     * @param  array<int, int[]>  $corporationDivisions  {corporation_id: [divisions]}
     */
    public function save(array $characterIds, array $corporationDivisions): void
    {
        $characters = $this->context->characters();
        $allowed = $this->context->allowedDivisions();
        $userId = $this->context->user->id;

        $rows = [];
        foreach (array_unique(array_map('intval', $characterIds)) as $id) {
            if (isset($characters[$id]))
                $rows[] = ['user_id' => $userId, 'owner_type' => 'character', 'owner_id' => $id, 'division' => null];
        }
        foreach ($corporationDivisions as $corporationId => $divisions) {
            foreach (array_unique(array_map('intval', (array) $divisions)) as $division) {
                if (in_array($division, $allowed[(int) $corporationId] ?? [], true))
                    $rows[] = ['user_id' => $userId, 'owner_type' => 'corporation', 'owner_id' => (int) $corporationId, 'division' => $division];
            }
        }

        DB::transaction(function () use ($userId, $rows) {
            AssetSource::where('user_id', $userId)->delete();
            if (! empty($rows))
                AssetSource::insert($rows);
        });
        $this->sources = null;
    }

    // -----------------------------------------------------------------
    // Stock
    // -----------------------------------------------------------------

    /**
     * Owned quantities and where they are, for the requested types.
     *
     * @param  int[]  $typeIds
     * @return array{0: array<int,int>, 1: array<int, array<int, array>>}|null ({type: qty}, {type: [places]}), null without any source
     */
    public function stock(array $typeIds): ?array
    {
        if (! $this->hasSources())
            return null;

        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        $sources = $this->sources();
        $characterNames = $this->context->characters();
        $corporationNames = $this->context->corporations();

        $quantities = [];
        $grouped = []; // "type|kind|owner|place|system" => [.., quantity]

        $add = function (int $typeId, string $kind, string $owner, array $where, int $quantity) use (&$quantities, &$grouped) {
            $quantities[$typeId] = ($quantities[$typeId] ?? 0) + $quantity;
            $key = implode('|', [$typeId, $kind, $owner, $where['place'], $where['system']]);
            if (! isset($grouped[$key]))
                $grouped[$key] = ['type_id' => $typeId, 'owner' => $owner, 'owner_kind' => $kind, 'place' => $where['place'], 'system' => $where['system'], 'quantity' => 0];
            $grouped[$key]['quantity'] += $quantity;
        };

        if (! empty($sources['characters']) && ! empty($typeIds)) {
            $rows = DB::table('character_assets')->whereIn('character_id', $sources['characters'])->whereIn('type_id', $typeIds)
                ->get(['item_id', 'character_id', 'type_id', 'quantity', 'location_id', 'location_flag', 'location_type']);
            $parents = $this->parents('character_assets', $rows);
            foreach ($rows as $row) {
                [$top] = $this->climb($row, $parents);
                $add((int) $row->type_id, 'character', $characterNames[$row->character_id] ?? (string) $row->character_id,
                    $this->describe((int) $top->location_id), (int) $row->quantity);
            }
        }

        if (! empty($sources['corporations']) && ! empty($typeIds)) {
            $divisionNames = $this->context->divisionNames(array_keys($sources['corporations']));
            $rows = DB::table('corporation_assets')->whereIn('corporation_id', array_keys($sources['corporations']))->whereIn('type_id', $typeIds)
                ->get(['item_id', 'corporation_id', 'type_id', 'quantity', 'location_id', 'location_flag', 'location_type']);
            $parents = $this->parents('corporation_assets', $rows);
            foreach ($rows as $row) {
                [$top, $division] = $this->climb($row, $parents);
                if ($division === null || ! in_array($division, $sources['corporations'][$row->corporation_id] ?? [], true))
                    continue;
                $where = $this->describe((int) $top->location_id);
                $where['place'] .= ' — ' . ($divisionNames[$row->corporation_id][$division] ?? trans('indus-planner::plan.hangar', ['division' => $division]));
                $add((int) $row->type_id, 'corporation', $corporationNames[$row->corporation_id] ?? (string) $row->corporation_id,
                    $where, (int) $row->quantity);
            }
        }

        $places = [];
        foreach ($grouped as $entry) {
            $typeId = $entry['type_id'];
            unset($entry['type_id']);
            $places[$typeId][] = $entry;
        }

        return [$quantities, $places];
    }

    // -----------------------------------------------------------------
    // Blueprints
    // -----------------------------------------------------------------

    /**
     * Owned blueprints, by blueprint type.
     *
     * @param  int[]  $blueprintTypeIds
     * @return array<int, array{bpo: ?array{me:int,te:int,count:int}, bpc: ?array{me:int,te:int,count:int,runs:int}}>
     */
    public function blueprints(array $blueprintTypeIds): array
    {
        if (! $this->hasSources() || empty($blueprintTypeIds))
            return [];

        $blueprintTypeIds = array_values(array_unique(array_map('intval', $blueprintTypeIds)));
        $sources = $this->sources();
        $owned = [];

        $record = function ($row) use (&$owned) {
            $typeId = (int) $row->type_id;
            $owned[$typeId] ??= ['bpo' => null, 'bpc' => null];
            $me = (int) $row->material_efficiency;
            $te = (int) $row->time_efficiency;
            // quantity: -1 original, -2 copy, > 0 stack of originals.
            if ((int) $row->quantity === -2) {
                $bpc = $owned[$typeId]['bpc'] ?? ['me' => $me, 'te' => $te, 'count' => 0, 'runs' => 0];
                if ([$me, $te] > [$bpc['me'], $bpc['te']])
                    [$bpc['me'], $bpc['te']] = [$me, $te];
                $bpc['count']++;
                $bpc['runs'] += max(0, (int) $row->runs);
                $owned[$typeId]['bpc'] = $bpc;
            } else {
                $bpo = $owned[$typeId]['bpo'] ?? ['me' => $me, 'te' => $te, 'count' => 0];
                if ([$me, $te] > [$bpo['me'], $bpo['te']])
                    [$bpo['me'], $bpo['te']] = [$me, $te];
                $bpo['count'] += max(1, (int) $row->quantity);
                $owned[$typeId]['bpo'] = $bpo;
            }
        };

        if (! empty($sources['characters'])) {
            foreach (DB::table('character_blueprints')->whereIn('character_id', $sources['characters'])->whereIn('type_id', $blueprintTypeIds)->get() as $row) {
                $record($row);
            }
        }

        if (! empty($sources['corporations'])) {
            $rows = DB::table('corporation_blueprints')->whereIn('corporation_id', array_keys($sources['corporations']))->whereIn('type_id', $blueprintTypeIds)
                ->get(['item_id', 'corporation_id', 'type_id', 'quantity', 'material_efficiency', 'time_efficiency', 'runs', 'location_id', 'location_flag']);
            // A blueprint is also an asset: its division is found by climbing
            // its containers in corporation_assets.
            $parents = $this->parents('corporation_assets', $rows);
            foreach ($rows as $row) {
                [, $division] = $this->climb($row, $parents);
                if ($division !== null && in_array($division, $sources['corporations'][$row->corporation_id] ?? [], true))
                    $record($row);
            }
        }

        return $owned;
    }

    // -----------------------------------------------------------------
    // Locations
    // -----------------------------------------------------------------

    /**
     * Loads the containers (ships, containers, offices) of the whole parent
     * chain of the given rows.
     *
     * @return array<int, object> {item_id: row}
     */
    private function parents(string $table, $rows): array
    {
        $parents = [];
        $pending = $rows->pluck('location_id')->map('intval')->unique()->values()->all();
        for ($depth = 0; $depth < 6 && ! empty($pending); $depth++) {
            $found = DB::table($table)->whereIn('item_id', $pending)
                ->get(['item_id', 'location_id', 'location_flag', 'location_type']);
            $pending = [];
            foreach ($found as $parent) {
                if (isset($parents[$parent->item_id]))
                    continue;
                $parents[(int) $parent->item_id] = $parent;
                $pending[] = (int) $parent->location_id;
            }
            $pending = array_values(array_diff(array_unique($pending), array_keys($parents)));
        }

        return $parents;
    }

    /**
     * Climbs the container chain of an asset.
     *
     * @return array{0: object, 1: ?int} (topmost ancestor, corporation hangar division or null)
     */
    private function climb(object $row, array $parents): array
    {
        $division = null;
        $current = $row;
        for ($depth = 0; $depth < 8; $depth++) {
            if ($division === null && preg_match('/^CorpSAG([1-7])$/', (string) $current->location_flag, $m))
                $division = (int) $m[1];
            $parent = $parents[(int) $current->location_id] ?? null;
            if ($parent === null)
                break;
            $current = $parent;
        }

        return [$current, $division];
    }

    /**
     * Readable name of a top-level location: station, structure or solar
     * system.
     *
     * @return array{place:string, system:string}
     */
    private function describe(int $locationId): array
    {
        if (isset($this->locationNames[$locationId]))
            return $this->locationNames[$locationId];

        $place = null;
        $systemId = null;

        if ($station = DB::table('universe_stations')->where('station_id', $locationId)->first(['name', 'system_id'])) {
            [$place, $systemId] = [$station->name, $station->system_id];
        } elseif ($station = DB::table('staStations')->where('stationID', $locationId)->first(['stationName', 'solarSystemID'])) {
            [$place, $systemId] = [$station->stationName, $station->solarSystemID];
        } elseif ($structure = DB::table('universe_structures')->where('structure_id', $locationId)->first(['name', 'solar_system_id'])) {
            [$place, $systemId] = [$structure->name, $structure->solar_system_id];
        } elseif ($system = DB::table('solar_systems')->where('system_id', $locationId)->first(['name'])) {
            [$place, $systemId] = [trans('indus-planner::plan.in_space'), $locationId];
        } elseif ($locationId === 2004) {
            // ESI virtual location of items in Asset Safety.
            $place = 'Asset Safety';
        } elseif ($locationId > 1_000_000_000_000) {
            // Upwell structure whose name SeAT does not know (no access).
            $place = trans('indus-planner::plan.unknown_structure', ['id' => $locationId]);
        }

        $systemName = $systemId ? (string) DB::table('solar_systems')->where('system_id', $systemId)->value('name') : '';

        return $this->locationNames[$locationId] = [
            'place' => $place ?? (string) $locationId,
            'system' => $systemName,
        ];
    }
}
