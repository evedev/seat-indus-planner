<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Http\Controllers;

use EveDev\Seat\IndusPlanner\Domain\Constants;
use EveDev\Seat\IndusPlanner\Models\Structure;
use EveDev\Seat\IndusPlanner\Models\StructureRig;
use EveDev\Seat\IndusPlanner\Services\SdeCatalog;
use EveDev\Seat\IndusPlanner\Services\StructureService;
use EveDev\Seat\IndusPlanner\Services\StructureSync;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Structure management (indus-planner.manage permission): manual structure
 * creation, rigs and tax of imported structures, synchronisation.
 */
class StructureAdminController extends Controller
{
    public function create(SdeCatalog $sde)
    {
        return $this->form(new Structure([
            'source' => 'manual',
            'structure_type_id' => 35825,
            'facility_tax_pct' => config('indus-planner.default_facility_tax'),
        ]), $sde, null);
    }

    public function edit(Structure $structure, SdeCatalog $sde, StructureService $structures)
    {
        $system = $structures->systems([$structure->solar_system_id])[$structure->solar_system_id] ?? null;

        return $this->form($structure->load('rigs'), $sde, $system);
    }

    private function form(Structure $structure, SdeCatalog $sde, ?array $system)
    {
        $rigsByType = [];
        foreach (array_keys(Constants::STRUCTURE_TYPES) as $typeId) {
            $rigsByType[$typeId] = array_map(fn ($r) => [
                'type_id' => $r['type_id'], 'name' => $r['name'], 'tier' => $r['tier'],
            ], $sde->rigsForStructureType($typeId));
        }

        return view('indus-planner::structure-edit', [
            'structure' => $structure,
            'system' => $system,
            'rigsByType' => $rigsByType,
            'currentRigs' => $structure->exists ? $structure->rigs->pluck('rig_type_id')->all() : [],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        $structure = DB::transaction(function () use ($data, $request) {
            $structure = Structure::create([
                'source' => 'manual',
                'name' => $data['name'],
                'structure_type_id' => $data['structure_type_id'],
                'solar_system_id' => $data['solar_system_id'],
                'facility_tax_pct' => $data['facility_tax_pct'],
                'rigs_manual' => true,
                'rigs_known' => true,
                'created_by' => $request->user()->id,
            ]);
            $this->saveRigs($structure, $data['rigs']);

            return $structure;
        });

        return redirect()->route('indus-planner.setup')->with('success', trans('indus-planner::messages.structure_added', ['name' => $structure->name]));
    }

    public function update(Request $request, Structure $structure, StructureSync $sync)
    {
        $data = $this->validated($request, $structure->source === 'manual');

        DB::transaction(function () use ($structure, $data) {
            if ($structure->source === 'manual') {
                $structure->fill([
                    'name' => $data['name'],
                    'structure_type_id' => $data['structure_type_id'],
                    'solar_system_id' => $data['solar_system_id'],
                ]);
            }
            $structure->facility_tax_pct = $data['facility_tax_pct'];

            // Imported structure: "detected rigs" hands control back to the
            // synchronisation, otherwise the manager's entry wins.
            if ($structure->source === 'esi' && $data['use_detected_rigs']) {
                $structure->rigs_manual = false;
            } else {
                $structure->rigs_manual = true;
                $this->saveRigs($structure, $data['rigs']);
            }
            $structure->save();
        });

        if ($structure->source === 'esi' && ! $structure->rigs_manual)
            $sync->run();

        return redirect()->route('indus-planner.setup')->with('success', trans('indus-planner::messages.structure_updated', ['name' => $structure->name]));
    }

    public function destroy(Structure $structure)
    {
        if ($structure->source !== 'manual')
            return back()->with('error', trans('indus-planner::messages.imported_structure_delete'));

        $name = $structure->name;
        $structure->delete();

        return redirect()->route('indus-planner.setup')->with('success', trans('indus-planner::messages.structure_deleted', ['name' => $name]));
    }

    public function sync(StructureSync $sync)
    {
        $result = $sync->run();

        return redirect()->route('indus-planner.setup')->with('success', trans('indus-planner::messages.sync_done', $result));
    }

    private function validated(Request $request, bool $full): array
    {
        $rules = [
            'facility_tax_pct' => 'required|numeric|min:0|max:100',
            'rigs' => 'array|max:3',
            'rigs.*' => 'nullable|integer',
            'use_detected_rigs' => 'nullable|boolean',
        ];
        if ($full) {
            $rules += [
                'name' => 'required|string|max:255',
                'structure_type_id' => 'required|integer|in:' . implode(',', array_keys(Constants::STRUCTURE_TYPES)),
                'solar_system_id' => 'required|integer|exists:solar_systems,system_id',
            ];
        }
        $data = $request->validate($rules, [
            'solar_system_id.required' => trans('indus-planner::messages.system_required'),
            'solar_system_id.exists' => trans('indus-planner::messages.system_unknown'),
        ]);
        $data['rigs'] = array_values(array_filter(array_map('intval', $data['rigs'] ?? [])));
        $data['use_detected_rigs'] = (bool) ($data['use_detected_rigs'] ?? false);

        return $data;
    }

    private function saveRigs(Structure $structure, array $rigTypeIds): void
    {
        StructureRig::where('structure_id', $structure->id)->delete();
        foreach (array_slice($rigTypeIds, 0, 3) as $rigTypeId) {
            StructureRig::create(['structure_id' => $structure->id, 'rig_type_id' => $rigTypeId]);
        }
    }
}
