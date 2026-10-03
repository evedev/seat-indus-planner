<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use EveDev\Seat\IndusPlanner\Domain\CatalogBuilder;
use EveDev\Seat\IndusPlanner\Domain\Constants;
use EveDev\Seat\IndusPlanner\Domain\ManufacturingBlueprint;
use EveDev\Seat\IndusPlanner\Domain\ReactionFormula;
use EveDev\Seat\IndusPlanner\Domain\TypeInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Static data read from the SDE loaded by SeAT: blueprints, formulas, rigs,
 * types and groups. The catalog, costly to build, is cached; types missing
 * from the cache are read on demand.
 */
class SdeCatalog implements TypeInfo
{
    private const CACHE_KEY = 'indus-planner:catalog:v2';

    /** @var array{blueprints: array<int, ManufacturingBlueprint>, formulas: array<int, ReactionFormula>, types: array<int, array{0:int,1:float,2:string}>}|null */
    private ?array $catalog = null;

    /** @var array<int, array{0:int,1:float,2:string}> types read outside the cache during the request */
    private array $extraTypes = [];

    private ?array $groupNames = null;

    /** @var array<int,int>|null maximum runs of one copy, by product */
    private ?array $runsLimits = null;

    // -----------------------------------------------------------------
    // Catalog
    // -----------------------------------------------------------------

    private function catalog(): array
    {
        if ($this->catalog === null) {
            $this->catalog = Cache::remember(self::CACHE_KEY, config('indus-planner.catalog_ttl'), fn () => $this->buildCatalog());
        }

        return $this->catalog;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->catalog = null;
    }

    private function buildCatalog(): array
    {
        $activityIds = [
            Constants::ACTIVITY_ID_MANUFACTURING => 'manufacturing',
            Constants::ACTIVITY_ID_REACTION => 'reaction',
        ];

        $activities = [];
        foreach (DB::table('industryActivity')->whereIn('activityID', array_keys($activityIds))->get() as $row) {
            $activities[$row->typeID][$activityIds[$row->activityID]] = ['time' => (int) $row->time, 'products' => [], 'materials' => []];
        }
        foreach (DB::table('industryActivityProducts')->whereIn('activityID', array_keys($activityIds))->orderBy('typeID')->orderBy('productTypeID')->get() as $row) {
            $name = $activityIds[$row->activityID];
            $activities[$row->typeID][$name] ??= ['time' => 0, 'products' => [], 'materials' => []];
            $activities[$row->typeID][$name]['products'][] = [(int) $row->productTypeID, (int) $row->quantity];
        }
        foreach (DB::table('industryActivityMaterials')->whereIn('activityID', array_keys($activityIds))->orderBy('typeID')->orderBy('materialTypeID')->get() as $row) {
            $name = $activityIds[$row->activityID];
            $activities[$row->typeID][$name] ??= ['time' => 0, 'products' => [], 'materials' => []];
            $activities[$row->typeID][$name]['materials'][] = [(int) $row->materialTypeID, (int) $row->quantity];
        }

        // Useful types: activity products and materials.
        $typeIds = [];
        foreach ($activities as $record) {
            foreach ($record as $activity) {
                foreach ($activity['products'] as [$id]) {
                    $typeIds[$id] = true;
                }
                foreach ($activity['materials'] as [$id]) {
                    $typeIds[$id] = true;
                }
            }
        }
        $types = [];
        foreach (array_chunk(array_keys($typeIds), 2000) as $chunk) {
            foreach (DB::table('invTypes')->whereIn('typeID', $chunk)->get(['typeID', 'groupID', 'volume', 'typeName']) as $row) {
                $types[(int) $row->typeID] = [(int) $row->groupID, (float) $row->volume, (string) $row->typeName];
            }
        }

        $this->catalog = ['blueprints' => [], 'formulas' => [], 'types' => $types];
        [$blueprints, $formulas] = CatalogBuilder::build(
            $activities,
            fn (int $id) => $types[$id][2] ?? null,
            $this,
        );

        return ['blueprints' => $blueprints, 'formulas' => $formulas, 'types' => $types];
    }

    /**
     * Maximum runs of one blueprint copy (or reaction job) for a product,
     * from the SDE `industryBlueprints` table; null when unknown.
     */
    public function runsLimit(int $productId): ?int
    {
        return $this->runsLimits()[$productId] ?? null;
    }

    /** @return array<int,int> */
    private function runsLimits(): array
    {
        if ($this->runsLimits !== null)
            return $this->runsLimits;

        // Table imported with the SDE: not cached while it is missing, so
        // that the limits appear right after `eve:update:sde`.
        if (! Schema::hasTable('industryBlueprints'))
            return $this->runsLimits = [];

        return $this->runsLimits = Cache::remember('indus-planner:runs-limits:v1', config('indus-planner.catalog_ttl'), function () {
            $byBlueprint = DB::table('industryBlueprints')->where('maxProductionLimit', '>', 0)->pluck('maxProductionLimit', 'typeID');
            $limits = [];
            foreach ($this->blueprints() as $productId => $blueprint) {
                if (isset($byBlueprint[$blueprint->blueprintTypeId]))
                    $limits[$productId] = (int) $byBlueprint[$blueprint->blueprintTypeId];
            }
            foreach ($this->formulas() as $productId => $formula) {
                if (! isset($limits[$productId]) && isset($byBlueprint[$formula->formulaTypeId]))
                    $limits[$productId] = (int) $byBlueprint[$formula->formulaTypeId];
            }

            $factionShips = DB::table('invMetaTypes')
                ->join('invTypes', 'invTypes.typeID', '=', 'invMetaTypes.typeID')
                ->join('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
                ->where('invMetaTypes.metaGroupID', Constants::META_GROUP_FACTION)
                ->where('invGroups.categoryID', Constants::CATEGORY_SHIP)
                ->pluck('invMetaTypes.typeID');
            foreach ($factionShips as $typeId) {
                if (isset($limits[(int) $typeId]))
                    $limits[(int) $typeId] = 1;
            }

            return $limits;
        });
    }

    /** @return array<int, ManufacturingBlueprint> */
    public function blueprints(): array
    {
        return $this->catalog()['blueprints'];
    }

    /** @return array<int, ReactionFormula> */
    public function formulas(): array
    {
        return $this->catalog()['formulas'];
    }

    /** @return array<int, array{0:int,1:string}> manufacturable items sorted by name */
    public function manufacturableItems(): array
    {
        $items = array_map(fn (ManufacturingBlueprint $bp) => [$bp->outputTypeId, $bp->outputName], $this->blueprints());
        usort($items, fn ($a, $b) => strcmp(mb_strtolower($a[1]), mb_strtolower($b[1])));

        return array_values($items);
    }

    /** @return array<int, array{0:int,1:string}> reaction products sorted by name */
    public function reactableItems(?string $category = null): array
    {
        $items = [];
        foreach ($this->formulas() as $typeId => $formula) {
            if ($category === null || $formula->category === $category)
                $items[] = [$typeId, $formula->outputName];
        }
        usort($items, fn ($a, $b) => strcmp(mb_strtolower($a[1]), mb_strtolower($b[1])));

        return $items;
    }

    // -----------------------------------------------------------------
    // Types and groups (TypeInfo)
    // -----------------------------------------------------------------

    private function type(int $typeId): ?array
    {
        $cached = $this->catalog === null ? null : ($this->catalog['types'][$typeId] ?? null);
        if ($cached !== null)
            return $cached;
        if (array_key_exists($typeId, $this->extraTypes))
            return $this->extraTypes[$typeId];

        $row = DB::table('invTypes')->where('typeID', $typeId)->first(['groupID', 'volume', 'typeName']);

        return $this->extraTypes[$typeId] = $row ? [(int) $row->groupID, (float) $row->volume, (string) $row->typeName] : null;
    }

    public function groupId(int $typeId): int
    {
        return $this->type($typeId)[0] ?? 0;
    }

    public function volume(int $typeId): float
    {
        return $this->type($typeId)[1] ?? 0.0;
    }

    public function name(int $typeId): ?string
    {
        return $this->type($typeId)[2] ?? null;
    }

    public function groupName(int $groupId): ?string
    {
        if ($this->groupNames === null)
            $this->groupNames = Cache::remember('indus-planner:groups:v1', config('indus-planner.catalog_ttl'),
                fn () => DB::table('invGroups')->pluck('groupName', 'groupID')->map(fn ($n) => (string) $n)->all());

        return $this->groupNames[$groupId] ?? null;
    }

    // -----------------------------------------------------------------
    // Rigs
    // -----------------------------------------------------------------

    /**
     * Published industry rigs, with their dogma bonuses.
     *
     * @return array<int, array{type_id:int,name:string,group_id:int,group_name:string,me_bonus:float,te_bonus:float,lowsec_mult:float,null_mult:float,tier:int,size:string}>
     *                                                                                                                                                                          indexed by type_id
     */
    public function rigs(): array
    {
        return Cache::remember('indus-planner:rigs:v1', config('indus-planner.catalog_ttl'), function () {
            $groups = array_keys(Constants::RIG_GROUP_CATEGORIES);
            $types = DB::table('invTypes')->whereIn('groupID', $groups)->where('published', 1)->get(['typeID', 'groupID', 'typeName']);

            $attributes = [];
            $wanted = [
                Constants::ATTR_RIG_TIME_BONUS, Constants::ATTR_RIG_MAT_BONUS,
                Constants::ATTR_RIG_REACT_TIME_BONUS, Constants::ATTR_RIG_REACT_MAT_BONUS,
                Constants::ATTR_LOWSEC_MULTIPLIER, Constants::ATTR_NULLSEC_MULTIPLIER,
            ];
            foreach (DB::table('dgmTypeAttributes')->whereIn('typeID', $types->pluck('typeID'))->whereIn('attributeID', $wanted)->get() as $row) {
                $attributes[$row->typeID][$row->attributeID] = (float) ($row->valueFloat ?? $row->valueInt ?? 0);
            }

            $rigs = [];
            foreach ($types as $type) {
                $a = $attributes[$type->typeID] ?? [];
                $name = (string) $type->typeName;
                // Manufacturing rigs carry 2593/2594, reaction rigs 2713/2714:
                // never both.
                $rigs[(int) $type->typeID] = [
                    'type_id' => (int) $type->typeID,
                    'name' => $name,
                    'group_id' => (int) $type->groupID,
                    'group_name' => $this->groupName((int) $type->groupID) ?? '',
                    'me_bonus' => ($a[Constants::ATTR_RIG_MAT_BONUS] ?? 0.0) + ($a[Constants::ATTR_RIG_REACT_MAT_BONUS] ?? 0.0),
                    'te_bonus' => ($a[Constants::ATTR_RIG_TIME_BONUS] ?? 0.0) + ($a[Constants::ATTR_RIG_REACT_TIME_BONUS] ?? 0.0),
                    'lowsec_mult' => $a[Constants::ATTR_LOWSEC_MULTIPLIER] ?? 1.0,
                    'null_mult' => $a[Constants::ATTR_NULLSEC_MULTIPLIER] ?? 1.0,
                    'tier' => str_ends_with($name, ' II') ? 2 : 1,
                    'size' => str_contains($name, 'XL-Set') ? 'XL' : (str_contains($name, 'L-Set') ? 'L' : 'M'),
                ];
            }
            uasort($rigs, fn ($x, $y) => [$x['group_id'], $x['name']] <=> [$y['group_id'], $y['name']]);

            return $rigs;
        });
    }

    /**
     * Rigs compatible with a structure type (size and vocation).
     */
    public function rigsForStructureType(int $structureTypeId): array
    {
        $info = Constants::STRUCTURE_TYPES[$structureTypeId] ?? null;
        if ($info === null)
            return [];

        return array_values(array_filter($this->rigs(), function ($rig) use ($info) {
            if ($rig['size'] !== $info['rig_size'])
                return false;
            $isReaction = in_array($rig['group_id'], Constants::REACTION_RIG_GROUPS, true);

            return $info['category'] === 'reaction' ? $isReaction : ! $isReaction;
        }));
    }
}
