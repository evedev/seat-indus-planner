<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use EveDev\Seat\IndusPlanner\Domain\Calculator;
use EveDev\Seat\IndusPlanner\Domain\Constants;
use EveDev\Seat\IndusPlanner\Domain\IndustryManager;
use EveDev\Seat\IndusPlanner\Domain\IndustrySetup;
use EveDev\Seat\IndusPlanner\Domain\Plan;
use EveDev\Seat\IndusPlanner\Domain\SimulationResult;
use EveDev\Seat\IndusPlanner\Domain\TreeData;
use EveDev\Seat\IndusPlanner\Domain\TreeItem;
use EveDev\Seat\IndusPlanner\Models\Market;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the Reactions and Production tools for a user: reads the
 * SeAT state (selected structures, skills, prices, stock, blueprints), calls
 * the domain and builds the view response.
 */
class Planner
{
    private ?IndustrySetup $setup = null;
    private ?array $ownedByProduct = null;

    public function __construct(
        private UserContext $context,
        private SdeCatalog $sde,
        private StructureService $structures,
        private MarketService $market,
        private AssetService $assets,
    ) {
    }

    public function setup(): IndustrySetup
    {
        return $this->setup ??= $this->structures->setupFor($this->context);
    }

    // -----------------------------------------------------------------
    // Characters and skills
    // -----------------------------------------------------------------

    /**
     * Time reduction given by the skills of one of the user's characters (0
     * for a character the user does not own).
     */
    public function skillReduction(?int $characterId, array $table): float
    {
        if (! $characterId || ! isset($this->context->characters()[$characterId]))
            return 0.0;

        $levels = DB::table('character_skills')->where('character_id', $characterId)
            ->whereIn('skill_id', array_keys($table))->pluck('active_skill_level', 'skill_id')
            ->map(fn ($l) => (int) $l)->all();

        return Constants::timeReductionFromSkills($levels, $table);
    }

    /** Character with the highest Advanced Industry level. */
    public function bestCharacterForIndustry(): ?int
    {
        $ids = array_keys($this->context->characters());
        if (empty($ids))
            return null;

        $levels = DB::table('character_skills')->whereIn('character_id', $ids)
            ->where('skill_id', Constants::SKILL_ADVANCED_INDUSTRY)->pluck('active_skill_level', 'character_id');
        $best = null;
        $bestLevel = -1;
        foreach ($ids as $id) {
            $level = (int) ($levels[$id] ?? 0);
            if ($level > $bestLevel) {
                $bestLevel = $level;
                $best = $id;
            }
        }

        return $best;
    }

    // -----------------------------------------------------------------
    // Owned blueprints
    // -----------------------------------------------------------------

    /**
     * Owned blueprints for a list of products.
     *
     * @param  int[]  $productIds
     * @return array<int, array{kind:string, blueprint_type_id:int, blueprint_name:string, bpo:?array, bpc:?array}>
     */
    public function ownedBlueprints(array $productIds): array
    {
        $map = [];
        foreach (array_unique($productIds) as $productId) {
            if ($bp = $this->sde->blueprints()[$productId] ?? null)
                $map[$productId] = ['manufacturing', $bp->blueprintTypeId];
            elseif ($formula = $this->sde->formulas()[$productId] ?? null)
                $map[$productId] = ['reaction', $formula->formulaTypeId];
        }

        $owned = $this->assets->blueprints(array_column($map, 1));
        $out = [];
        foreach ($map as $productId => [$kind, $blueprintTypeId]) {
            $out[$productId] = [
                'kind' => $kind,
                'blueprint_type_id' => $blueprintTypeId,
                'blueprint_name' => $this->sde->name($blueprintTypeId) ?? "Blueprint {$blueprintTypeId}",
                'bpo' => $owned[$blueprintTypeId]['bpo'] ?? null,
                'bpc' => $owned[$blueprintTypeId]['bpc'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * ME/TE of the best owned blueprint for a manufactured product: an
     * original if any, otherwise the best copy.
     *
     * @return array{me:int, te:int}|null
     */
    public function efficiencyFor(int $productId): ?array
    {
        $owned = $this->ownedByProduct[$productId] ?? null;
        if ($owned === null)
            return null;
        $best = $owned['bpo'] ?? $owned['bpc'];

        return $best ? ['me' => $best['me'], 'te' => $best['te']] : null;
    }

    /**
     * Runs of one job for a product: the runs of the best owned copy when the
     * user has copies, otherwise the maximum runs of a copy (SDE). The limit
     * applies even with an original.
     */
    public function runsPerJobFor(int $productId): ?int
    {
        $limit = $this->sde->runsLimit($productId);
        $copyRuns = (int) ($this->ownedByProduct[$productId]['bpc']['max_runs'] ?? 0);
        if ($copyRuns > 0)
            return $limit !== null ? min($copyRuns, $limit) : $copyRuns;

        return $limit;
    }


    private function manager(): IndustryManager
    {
        return new IndustryManager(
            $this->sde,
            $this->setup(),
            $this->sde->blueprints(),
            $this->sde->formulas(),
            true,
            fn (int $productId) => $this->efficiencyFor($productId),
            fn (int $productId) => $this->runsPerJobFor($productId),
        );
    }

    /**
     * Preloads the owned blueprints of every product the tree may contain,
     * down to the maximum depth.
     */
    private function preloadBlueprints(int $rootProductId, bool $includeReactions): void
    {
        $products = [];
        $frontier = [$rootProductId];
        for ($depth = 0; $depth <= Constants::MAX_TREE_DEPTH + 1 && ! empty($frontier); $depth++) {
            $next = [];
            foreach ($frontier as $productId) {
                if (isset($products[$productId]))
                    continue;
                $products[$productId] = true;
                $materials = ($this->sde->blueprints()[$productId] ?? null)?->materials
                    ?? ($includeReactions ? (($this->sde->formulas()[$productId] ?? null)?->materials ?? []) : []);
                foreach ($materials as $material) {
                    $next[] = $material->typeId;
                }
            }
            $frontier = $next;
        }
        $this->ownedByProduct = $this->ownedBlueprints(array_keys($products));
    }

    // -----------------------------------------------------------------
    // Tools
    // -----------------------------------------------------------------

    /**
     * Simulates a reaction chain (Reactions tool).
     *
     * @param  array{character_id:?int, structure_id:?string, type_id:int, qty_mode:string, qty:int, runs:int, overrides:array, buy?:string[], market?:?Market}  $input
     */
    public function reaction(array $input): ?array
    {
        $formula = $this->sde->formulas()[$input['type_id']] ?? null;
        if ($formula === null)
            return null;

        $this->preloadBlueprints($formula->outputTypeId, true);
        $structure = $this->setup()->getStructure($input['structure_id'] ?? null);
        if ($structure !== null && $structure->structureCategory() !== 'reaction')
            $structure = null;
        $skill = $this->skillReduction($input['character_id'] ?? null, Constants::REACTION_TIME_SKILLS);

        if (($input['qty_mode'] ?? 'qty') === 'qty') {
            $desired = max(1, (int) $input['qty']);
            $runs = max(1, (int) ceil($desired / max(1, $formula->outputQuantity)));
        } else {
            $runs = max(1, (int) $input['runs']);
            $desired = $runs * $formula->outputQuantity;
        }

        $adjusted = $this->market->adjustedPrices();
        $result = Calculator::reaction($formula, $structure, $runs, $skill, 0.0, $adjusted, $this->runsPerJobFor($formula->outputTypeId) ?? 0);
        $manager = $this->manager();
        $overrides = $this->overrides($input['overrides'] ?? []);
        $tree = $manager->buildProductionTree($result, $desired, $adjusted, true, $overrides, buyKeys: $this->buyKeys($input['buy'] ?? []));

        return $this->payload($result, $tree, $manager, $overrides, $input['market'] ?? null);
    }

    /**
     * Simulates a manufacturing order (Production tool).
     *
     * @param  array{character_id:?int, type_id:int, qty:int, me:int, te:int, include_reactions:bool, overrides:array, buy?:string[], market?:?Market}  $input
     */
    public function production(array $input): ?array
    {
        $blueprint = $this->sde->blueprints()[$input['type_id']] ?? null;
        if ($blueprint === null)
            return null;

        $includeReactions = (bool) ($input['include_reactions'] ?? true);
        $this->preloadBlueprints($blueprint->outputTypeId, $includeReactions);
        $manager = $this->manager();
        $overrides = $this->overrides($input['overrides'] ?? []);
        $structure = $manager->structureFor($blueprint->outputTypeId, $blueprint->productCategory, $overrides);
        $skill = $this->skillReduction($input['character_id'] ?? null, Constants::MANUFACTURING_TIME_SKILLS);
        $desired = max(1, (int) $input['qty']);
        $adjusted = $this->market->adjustedPrices();

        $result = Calculator::manufacturing(
            $blueprint, $structure, $desired,
            max(0, min(10, (int) $input['me'])), max(0, min(20, (int) $input['te'])),
            $skill, $adjusted, $this->runsPerJobFor($blueprint->outputTypeId) ?? 0,
        );
        $tree = $manager->buildProductionTree($result, $desired, $adjusted, $includeReactions, $overrides, [
            'manufacturing' => $skill,
            'reaction' => $this->skillReduction($input['character_id'] ?? null, Constants::REACTION_TIME_SKILLS),
        ], $this->buyKeys($input['buy'] ?? []));

        return $this->payload($result, $tree, $manager, $overrides, $input['market'] ?? null);
    }

    /**
     * Production plan (jobs and purchases) built from the visible tree nodes
     * and the mode chosen for each of them.
     *
     * @param  array<int, array{item:array, rank:int, mode:string}>  $rawEntries
     * @param  Market|null  $market  price source, Jita when null
     */
    public function plan(array $rawEntries, ?Market $market = null): array
    {
        $entries = [];
        foreach ($rawEntries as $raw) {
            if (! isset($raw['item']['type_id']))
                continue;
            $entries[] = [
                'item' => TreeItem::fromArray($raw['item']),
                'rank' => (int) ($raw['rank'] ?? 0),
                'mode' => ($raw['mode'] ?? 'P') === Plan::MODE_BUY ? Plan::MODE_BUY : Plan::MODE_PRODUCE,
            ];
        }

        $typeIds = array_map(fn ($e) => $e['item']->typeId, $entries);
        $prices = $this->market->priceMap($typeIds, $market);
        $stock = $this->assets->stock($typeIds);
        [$quantities, $places] = $stock ?? [[], []];

        $jobs = Plan::productionJobs($entries);
        $owned = $this->ownedBlueprints(array_column($jobs, 'type_id'));
        foreach ($jobs as &$job) {
            $job['blueprint'] = $owned[$job['type_id']] ?? null;
        }
        unset($job);

        $labels = self::labels();
        $purchases = Plan::purchases($entries, $prices, $quantities, fn (int $g) => $this->sde->groupName($g), $places, $labels);
        foreach ($purchases as &$line) {
            $line['stock_tooltip'] = Plan::stockTooltip($line['places'], $stock !== null, $labels);
        }
        unset($line);

        return [
            'jobs' => $jobs,
            'purchases' => $purchases,
            'stock_known' => $stock !== null,
            'market' => MarketCatalog::describe($market),
        ];
    }

    // -----------------------------------------------------------------
    // Response building
    // -----------------------------------------------------------------

    /**
     * Translated labels of the plan texts (stock tooltips, group names).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = trans('indus-planner::plan');

        return is_array($labels) ? $labels : [];
    }

    /** @return string[] well-formed "rank:type_id" keys of the bought nodes */
    private function buyKeys(array $raw): array
    {
        return array_values(array_filter(array_map('strval', $raw), fn ($key) => preg_match('/^\d+:\d+$/', $key) === 1));
    }

    /** @return array<int,string> structure choices limited to the selected structures */
    private function overrides(array $raw): array
    {
        $out = [];
        foreach ($raw as $typeId => $structureId) {
            if ($this->setup()->getStructure((string) $structureId) !== null)
                $out[(int) $typeId] = (string) $structureId;
        }

        return $out;
    }

    private function payload(SimulationResult $result, TreeData $tree, IndustryManager $manager, array $overrides, ?Market $market = null): array
    {
        $items = $tree->allItems();
        $typeIds = array_map(fn (TreeItem $i) => $i->typeId, $items);
        $stock = $this->assets->stock($typeIds);

        $structures = [];
        foreach ($manager->structuresForItems($items, $overrides) as $typeId => [$available, $selected]) {
            $structures[$typeId] = [
                'available' => array_map(fn ($s) => ['id' => $s[0], 'name' => $s[1]], $available),
                'selected' => $selected,
            ];
        }

        $producible = array_values(array_unique(array_map(fn (TreeItem $i) => $i->typeId,
            array_filter($items, fn (TreeItem $i) => $i->runs > 0))));
        $blueprints = array_intersect_key($this->ownedByProduct ?? [], array_flip($producible));

        // Item group names, used to group the tree cards by family.
        $labels = self::labels();
        $groups = [];
        foreach ($items as $item) {
            $groups[$item->groupId] ??= Plan::planetaryGroupName($item->groupId, $labels)
                ?? $this->sde->groupName($item->groupId) ?? Plan::unknownGroup($labels);
        }

        $stockTooltips = [];
        if ($stock !== null) {
            foreach ($stock[1] as $typeId => $places) {
                $stockTooltips[$typeId] = Plan::stockTooltip($places, true, $labels);
            }
        }

        return [
            'result' => [
                'output_type_id' => $result->outputTypeId(),
                'output_name' => $result->outputName(),
                'is_manufacturing' => $result->isManufacturing(),
                'runs' => $result->runs,
                'output_quantity' => $result->outputQuantity,
                'time_per_run_seconds' => $result->timePerRunSeconds,
                'time_total_seconds' => $result->timeTotalSeconds,
                'me_reduction_pct' => $result->meReductionPct,
                'te_reduction_pct' => $result->teReductionPct,
                'job_cost' => $result->jobCost,
                'scc_cost' => $result->sccCost,
                'facility_cost' => $result->facilityCost,
                'total_job_cost' => $result->totalJobCost,
                'structure_name' => $result->structure?->name,
            ],
            'tree' => $tree->toArray(),
            'prices' => (object) $this->market->priceMap($typeIds, $market),
            'market' => MarketCatalog::describe($market),
            'groups' => (object) $groups,
            'structures' => (object) $structures,
            'blueprints' => (object) $blueprints,
            'stock_known' => $stock !== null,
            'stock' => (object) ($stock[0] ?? []),
            'stock_tooltips' => (object) $stockTooltips,
            'has_structures' => $this->setup()->count() > 0,
            'adjusted_prices_known' => ! empty($this->market->adjustedPrices()),
        ];
    }
}
