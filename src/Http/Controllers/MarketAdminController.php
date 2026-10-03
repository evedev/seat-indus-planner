<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Http\Controllers;

use EveDev\Seat\IndusPlanner\Jobs\RefreshMarket;
use EveDev\Seat\IndusPlanner\Models\Market;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Price markets of the Industry setup (manage permission): player
 * structure markets added, refreshed or removed.
 */
class MarketAdminController extends Controller
{
    // Structures known by SeAT (seen in assets, jobs, contracts…), by name.
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3)
            return response()->json([]);

        $rows = DB::table('universe_structures')
            ->leftJoin('solar_systems', 'solar_systems.system_id', '=', 'universe_structures.solar_system_id')
            ->where('universe_structures.name', 'like', '%' . addcslashes($q, '%_\\') . '%')
            ->whereNotIn('universe_structures.structure_id', Market::pluck('structure_id'))
            ->orderBy('universe_structures.name')->limit(20)
            ->get(['universe_structures.structure_id', 'universe_structures.name', 'solar_systems.name as system']);

        return response()->json($rows->map(fn ($r) => ['id' => (string) $r->structure_id, 'name' => $r->name, 'system' => $r->system]));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['structure_id' => 'required|integer|exists:universe_structures,structure_id|unique:indus_planner_markets,structure_id']);
        $structure = DB::table('universe_structures')->where('structure_id', $data['structure_id'])->first();

        $market = Market::create([
            'structure_id' => $structure->structure_id,
            'name' => $structure->name,
            'solar_system_id' => $structure->solar_system_id,
            'created_by' => $request->user()->id,
        ]);
        RefreshMarket::dispatch($market);

        return redirect()->route('indus-planner.setup')->with('success', trans('indus-planner::messages.market_added', ['name' => $market->name]));
    }

    public function refresh(Market $market)
    {
        RefreshMarket::dispatch($market);

        return redirect()->route('indus-planner.setup')->with('success', trans('indus-planner::messages.market_refresh_queued', ['name' => $market->name]));
    }

    public function destroy(Market $market)
    {
        $name = $market->name;
        $market->delete();

        return redirect()->route('indus-planner.setup')->with('success', trans('indus-planner::messages.market_deleted', ['name' => $name]));
    }
}
