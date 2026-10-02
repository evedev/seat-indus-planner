<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Http\Controllers;

use EveDev\Seat\IndusPlanner\Services\Planner;
use EveDev\Seat\IndusPlanner\Services\SdeCatalog;
use EveDev\Seat\IndusPlanner\Services\UserContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ReactionsController extends Controller
{
    // Reaction types, in display order.
    public const REACTION_TYPES = ['composite', 'hybrid', 'biochemical'];

    public function index(UserContext $context, Planner $planner, SdeCatalog $sde)
    {
        $reactionStructures = $planner->setup()->reactionStructures();
        $best = $planner->setup()->defaultStructureFor('composite', 'reaction');

        return view('indus-planner::reactions', [
            'characters' => $context->characters(),
            'bestCharacter' => $planner->bestCharacterForIndustry(),
            'structures' => $reactionStructures,
            'bestStructure' => $best?->id,
            'types' => array_combine(self::REACTION_TYPES, array_map(fn ($type) => trans('indus-planner::ui.reaction_types.' . $type), self::REACTION_TYPES)),
            'reactions' => $sde->reactableItems('composite'),
        ]);
    }

    public function list(Request $request, SdeCatalog $sde)
    {
        $category = in_array($request->query('category'), self::REACTION_TYPES, true) ? $request->query('category') : 'composite';

        return response()->json(array_map(fn ($r) => ['id' => $r[0], 'name' => $r[1]], $sde->reactableItems($category)));
    }

    public function compute(Request $request, Planner $planner)
    {
        $payload = $planner->reaction([
            'character_id' => (int) $request->input('character_id') ?: null,
            'structure_id' => $request->input('structure_id') ? (string) $request->input('structure_id') : null,
            'type_id' => (int) $request->input('type_id'),
            'qty_mode' => $request->input('qty_mode') === 'runs' ? 'runs' : 'qty',
            'qty' => (int) $request->input('qty', 1),
            'runs' => (int) $request->input('runs', 1),
            'overrides' => (array) $request->input('overrides', []),
            'buy' => (array) $request->input('buy', []),
        ]);

        return $payload === null
            ? response()->json(['error' => trans('indus-planner::messages.unknown_reaction')], 404)
            : response()->json($payload);
    }
}
