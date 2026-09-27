<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Http\Controllers;

use EveDev\Seat\IndusPlanner\Domain\Constants;
use EveDev\Seat\IndusPlanner\Services\AssetService;
use EveDev\Seat\IndusPlanner\Services\SdeCatalog;
use EveDev\Seat\IndusPlanner\Services\StructureService;
use EveDev\Seat\IndusPlanner\Services\UserContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Industry setup: structures taken into account and asset sources.
 */
class SetupController extends Controller
{
    public function index(UserContext $context, StructureService $structures, AssetService $assets, SdeCatalog $sde)
    {
        $visible = $structures->visibleTo($context);
        $systems = $structures->systems($visible->pluck('solar_system_id')->all());
        $indices = DB::table('indus_planner_cost_indices')->whereIn('solar_system_id', $visible->pluck('solar_system_id'))->get()->keyBy('solar_system_id');
        $corporationNames = DB::table('corporation_infos')->whereIn('corporation_id', $visible->pluck('corporation_id')->filter())->pluck('name', 'corporation_id');

        $corporations = $context->corporations();
        $allowed = $context->allowedDivisions();

        return view('indus-planner::setup', [
            'structures' => $visible,
            'selected' => array_flip($structures->selectedIds($context->user->id)),
            'systems' => $systems,
            'indices' => $indices,
            'corporationNames' => $corporationNames,
            'rigs' => $sde->rigs(),
            'characters' => $context->characters(),
            'corporations' => $corporations,
            'allowedDivisions' => $allowed,
            'divisionNames' => $context->divisionNames(array_keys($corporations)),
            'sources' => $assets->sources(),
            'canManage' => Gate::allows('indus-planner.manage'),
        ]);
    }

    public function saveStructures(Request $request, UserContext $context, StructureService $structures)
    {
        $structures->saveSelection($context, (array) $request->input('structures', []));

        return redirect()->route('indus-planner.setup')->with('success', trans('indus-planner::messages.structures_saved'));
    }

    public function saveSources(Request $request, AssetService $assets)
    {
        $assets->save((array) $request->input('characters', []), (array) $request->input('corporations', []));

        return redirect()->route('indus-planner.setup')->with('success', trans('indus-planner::messages.sources_saved'));
    }

    /** Solar system autocompletion. */
    public function systems(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2)
            return response()->json([]);

        $rows = DB::table('solar_systems')->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%')
            ->orderByRaw('name LIKE ? DESC', [addcslashes($q, '%_\\') . '%'])->orderBy('name')->limit(15)
            ->get(['system_id', 'name', 'security']);

        return response()->json($rows->map(fn ($r) => [
            'id' => (int) $r->system_id,
            'name' => $r->name,
            'security_status' => round((float) $r->security, 2),
            'security_class' => Constants::securityClass((float) $r->security),
        ]));
    }

    /** Bonus preview of the structure being edited. */
    public function preview(Request $request, StructureService $structures)
    {
        $type = (int) $request->input('structure_type_id');
        if (! isset(Constants::STRUCTURE_TYPES[$type]))
            return response()->json([]);
        $security = in_array($request->input('security'), Constants::SECURITY_CLASSES, true) ? $request->input('security') : 'Null / Wormhole';

        return response()->json($structures->bonusPreview($type, $security, array_filter(array_map('intval', (array) $request->input('rigs', [])))));
    }
}
