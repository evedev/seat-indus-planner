<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Http\Controllers;

use EveDev\Seat\IndusPlanner\Services\MarketCatalog;
use EveDev\Seat\IndusPlanner\Services\Planner;
use EveDev\Seat\IndusPlanner\Services\SdeCatalog;
use EveDev\Seat\IndusPlanner\Services\UserContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProductionController extends Controller
{
    public function index(UserContext $context, Planner $planner, MarketCatalog $markets)
    {
        return view('indus-planner::production', [
            'markets' => $markets->options(),
            'market' => $markets->currentValue(),
            'characters' => $context->characters(),
            'bestCharacter' => $planner->bestCharacterForIndustry(),
            'hasStructures' => $planner->setup()->count() > 0,
        ]);
    }

    /** Searches a manufacturable item (3 characters minimum). */
    public function search(Request $request, SdeCatalog $sde)
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        if (mb_strlen($q) < 3)
            return response()->json([]);

        $starts = $contains = [];
        foreach ($sde->manufacturableItems() as [$id, $name]) {
            $position = mb_strpos(mb_strtolower($name), $q);
            if ($position === false)
                continue;
            $position === 0 ? $starts[] = ['id' => $id, 'name' => $name] : $contains[] = ['id' => $id, 'name' => $name];
            if (count($starts) >= 30)
                break;
        }

        return response()->json(array_slice(array_merge($starts, $contains), 0, 30));
    }

    /**
     * Owned blueprint for an item: used to prefill ME/TE.
     */
    public function blueprint(Request $request, Planner $planner)
    {
        $typeId = (int) $request->query('type_id');
        $owned = $planner->ownedBlueprints([$typeId])[$typeId] ?? null;

        return response()->json($owned);
    }

    public function compute(Request $request, Planner $planner, MarketCatalog $markets)
    {
        $market = $markets->resolve($request->input('market'));
        $markets->remember($market);
        $payload = $planner->production([
            'market' => $market,
            'character_id' => (int) $request->input('character_id') ?: null,
            'type_id' => (int) $request->input('type_id'),
            'qty' => (int) $request->input('qty', 1),
            'me' => (int) $request->input('me', 0),
            'te' => (int) $request->input('te', 0),
            'include_reactions' => filter_var($request->input('include_reactions', true), FILTER_VALIDATE_BOOLEAN),
            'overrides' => (array) $request->input('overrides', []),
            'buy' => (array) $request->input('buy', []),
        ]);

        return $payload === null
            ? response()->json(['error' => trans('indus-planner::messages.no_blueprint')], 404)
            : response()->json($payload);
    }

    public function plan(Request $request, Planner $planner, MarketCatalog $markets)
    {
        return response()->json($planner->plan((array) $request->input('entries', []), $markets->resolve($request->input('market'))));
    }
}
