<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use EveDev\Seat\IndusPlanner\Domain\Constants;
use EveDev\Seat\IndusPlanner\Domain\IndustrialStructure;
use EveDev\Seat\IndusPlanner\Domain\IndustrySetup;
use EveDev\Seat\IndusPlanner\Domain\StructureRig as DomainRig;
use EveDev\Seat\IndusPlanner\Models\Structure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Structures offered to a user, their selection, and their conversion into
 * domain objects (SDE rigs, security, cost index).
 */
class StructureService
{
    public function __construct(private SdeCatalog $sde)
    {
    }

    /**
     * Visible structures: manual ones (maintained by a manager) and the ones
     * imported from the corporations of the user's alliance.
     *
     * @return Collection<int, Structure>
     */
    public function visibleTo(UserContext $context): Collection
    {
        $corporations = $context->structureCorporationIds();

        return Structure::with('rigs')
            ->where(function ($q) use ($corporations) {
                $q->where('source', 'manual')
                    ->orWhereIn('corporation_id', $corporations);
            })
            ->orderBy('name')
            ->get();
    }

    /** @return int[] ids of the structures selected by the user */
    public function selectedIds(int $userId): array
    {
        return DB::table('indus_planner_user_structures')->where('user_id', $userId)->pluck('structure_id')->map('intval')->all();
    }

    /**
     * @param  int[]  $structureIds  selected structures (limited to visible ones)
     */
    public function saveSelection(UserContext $context, array $structureIds): void
    {
        $visible = $this->visibleTo($context)->pluck('id')->all();
        $keep = array_values(array_intersect(array_map('intval', $structureIds), $visible));

        DB::transaction(function () use ($context, $keep) {
            DB::table('indus_planner_user_structures')->where('user_id', $context->user->id)->delete();
            DB::table('indus_planner_user_structures')->insert(array_map(
                fn ($id) => ['user_id' => $context->user->id, 'structure_id' => $id], $keep));
        });
    }

    /**
     * Domain setup: structures that are both visible and selected by the user.
     */
    public function setupFor(UserContext $context): IndustrySetup
    {
        $selected = array_flip($this->selectedIds($context->user->id));
        $structures = $this->visibleTo($context)->filter(fn ($s) => isset($selected[$s->id]));

        return new IndustrySetup($this->toDomain($structures->all()));
    }

    /**
     * @param  Structure[]  $structures
     * @return IndustrialStructure[]
     */
    public function toDomain(array $structures): array
    {
        $systems = $this->systems(array_map(fn ($s) => $s->solar_system_id, $structures));
        $indices = DB::table('indus_planner_cost_indices')
            ->whereIn('solar_system_id', array_map(fn ($s) => $s->solar_system_id, $structures))
            ->get()->keyBy('solar_system_id');
        $rigs = $this->sde->rigs();

        $out = [];
        foreach ($structures as $structure) {
            $system = $systems[$structure->solar_system_id] ?? null;
            $index = $indices->get($structure->solar_system_id);
            $out[] = new IndustrialStructure(
                id: (string) $structure->id,
                name: $structure->name,
                structureTypeId: $structure->structure_type_id,
                solarSystemId: $structure->solar_system_id,
                solarSystemName: $system['name'] ?? '',
                security: $system['security_class'] ?? 'Null / Wormhole',
                rigs: $this->domainRigs($structure->rigs->pluck('rig_type_id')->all(), $rigs),
                systemCostIndex: $index ? (float) ($structure->vocation() === 'reaction' ? $index->reaction : $index->manufacturing) : 0.0,
                facilityTaxPct: (float) $structure->facility_tax_pct,
            );
        }

        return $out;
    }

    /** @return DomainRig[] */
    public function domainRigs(array $rigTypeIds, ?array $catalog = null): array
    {
        $catalog ??= $this->sde->rigs();
        $out = [];
        foreach ($rigTypeIds as $typeId) {
            $rig = $catalog[(int) $typeId] ?? null;
            if ($rig === null)
                continue;
            $out[] = new DomainRig($rig['type_id'], $rig['name'], $rig['group_id'], $rig['me_bonus'], $rig['te_bonus'], $rig['lowsec_mult'], $rig['null_mult']);
        }

        return $out;
    }

    /**
     * @return array<int, array{id:int, name:string, security_status:float, security_class:string}>
     */
    public function systems(array $systemIds): array
    {
        $out = [];
        foreach (DB::table('solar_systems')->whereIn('system_id', array_unique($systemIds))->get(['system_id', 'name', 'security']) as $row) {
            $out[(int) $row->system_id] = [
                'id' => (int) $row->system_id,
                'name' => (string) $row->name,
                'security_status' => (float) $row->security,
                'security_class' => Constants::securityClass((float) $row->security),
            ];
        }

        return $out;
    }

    /**
     * Bonus preview by category for a structure type, a security class and a
     * set of rigs (structure editor).
     *
     * @return array<int, array{category:string, label:string, me:float, te:float}>
     */
    public function bonusPreview(int $structureTypeId, string $security, array $rigTypeIds): array
    {
        $structure = new IndustrialStructure('__preview__', 'preview', $structureTypeId, 0, '', $security, $this->domainRigs($rigTypeIds));
        $vocation = Constants::STRUCTURE_TYPES[$structureTypeId]['category'] ?? 'manufacturing';
        $categories = $vocation === 'reaction'
            ? Constants::REACTION_CATEGORIES
            : array_values(array_diff(Constants::PRODUCT_CATEGORIES, Constants::REACTION_CATEGORIES));

        $rows = [];
        foreach ($categories as $category) {
            $me = $structure->totalMeReduction($category);
            $te = $structure->totalTeReduction($category);
            if ($me == 0.0 && $te == 0.0)
                continue;
            $rows[] = [
                'category' => $category,
                'label' => trans('indus-planner::categories.' . $category),
                'me' => round($me, 1),
                'te' => round($te, 1),
            ];
        }

        return $rows;
    }
}
