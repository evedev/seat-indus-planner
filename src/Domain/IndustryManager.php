<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Domain entry point: catalog, structure choice and complete production
 * tree (consolidated quantities, runs, overproduction, costs).
 *
 * Two modes:
 *  - basic (`componentBonuses` false): component materials use their raw SDE
 *    quantity, with no blueprint ME and no structure bonus;
 *  - SeAT (`componentBonuses` true): every manufactured component benefits
 *    from the ME/TE of the best owned blueprint (given by
 *    `$blueprintEfficiency`) and from its structure ME bonuses.
 */
final class IndustryManager
{
    /** @var callable(int): (array{me:int,te:int}|null)|null */
    private $blueprintEfficiency;

    /**
     * @param  array<int, ManufacturingBlueprint>  $blueprints  indexed by product
     * @param  array<int, ReactionFormula>  $formulas  indexed by product
     * @param  callable(int): (array{me:int,te:int}|null)|null  $blueprintEfficiency
     *                                                                               ME/TE of the owned blueprint for a manufactured product
     */
    public function __construct(
        private TypeInfo $types,
        public IndustrySetup $setup,
        public array $blueprints,
        public array $formulas,
        private bool $componentBonuses = false,
        ?callable $blueprintEfficiency = null,
    ) {
        $this->blueprintEfficiency = $blueprintEfficiency;
    }

    // -----------------------------------------------------------------
    // Catalog
    // -----------------------------------------------------------------

    public function isManufacturable(int $typeId): bool
    {
        return isset($this->blueprints[$typeId]);
    }

    public function isReactable(int $typeId): bool
    {
        return isset($this->formulas[$typeId]);
    }

    /** Manufacturing wins when an item can be both manufactured and reacted. */
    public function usesReaction(int $typeId): bool
    {
        return $this->isReactable($typeId) && ! $this->isManufacturable($typeId);
    }

    public function isProducible(int $typeId): bool
    {
        return $this->isManufacturable($typeId) || $this->isReactable($typeId);
    }

    public function blueprint(int $typeId): ?ManufacturingBlueprint
    {
        return $this->blueprints[$typeId] ?? null;
    }

    public function formula(int $typeId): ?ReactionFormula
    {
        return $this->formulas[$typeId] ?? null;
    }

    // -----------------------------------------------------------------
    // Structure selection
    // -----------------------------------------------------------------

    /**
     * Structure used to produce an item: explicit user choice, otherwise the
     * best time reduction among the structures able to run the activity.
     *
     * @param  array<int,string>  $overrides  {type_id: structure_id}
     */
    public function structureFor(int $typeId, string $category, array $overrides = []): ?IndustrialStructure
    {
        $structureId = $overrides[$typeId] ?? null;
        if ($structureId) {
            $structure = $this->setup->getStructure($structureId);
            if ($structure !== null)
                return $structure;
        }

        return $this->setup->defaultStructureFor($category, $this->vocationOf($category));
    }

    private function vocationOf(string $category): string
    {
        return in_array($category, Constants::REACTION_CATEGORIES, true) ? 'reaction' : 'manufacturing';
    }

    // -----------------------------------------------------------------
    // Production tree
    // -----------------------------------------------------------------

    /**
     * Builds the production tree of a simulation result.
     *
     * Walks rank by rank: at each level, the needs for the same item coming
     * from different branches are consolidated before computing the number
     * of runs, so that a component used in several places is not
     * overestimated.
     *
     * @param  array<int,float>|null  $adjustedPrices
     * @param  array<int,string>  $overrides  explicit structure choices
     * @param  array<string,float>  $timeSkills  {'manufacturing': x, 'reaction': y}
     * @param  string[]  $buyKeys  "rank:type_id" of the nodes the user buys: their
     *                             materials are neither expanded nor counted
     */
    public function buildProductionTree(
        SimulationResult $result,
        int $desiredQty,
        ?array $adjustedPrices = null,
        bool $includeReactions = true,
        array $overrides = [],
        array $timeSkills = [],
        array $buyKeys = [],
    ): TreeData {
        $prices = $adjustedPrices ?? [];
        $bought = array_fill_keys($buyKeys, true);
        $rootTypeId = $result->outputTypeId();

        $output = new TreeItem(
            typeId: $rootTypeId,
            name: $result->outputName(),
            groupId: $this->types->groupId($rootTypeId),
            qtyBase: $result->outputPerRun(),
            qtyActual: $result->outputPerRun(),
            // The need is what the user asked for; the quantity obtained is
            // often higher, since a run cannot be split.
            qtyTotal: $desiredQty,
            isReactionOutput: false,
            producedByReaction: ! $result->isManufacturing(),
            runs: $result->runs,
            qtyProduced: $result->outputQuantity,
            surplus: max(0, $result->outputQuantity - $desiredQty),
            jobCost: $result->totalJobCost,
            volume: $this->types->volume($rootTypeId),
            productCategory: $result->category(),
            timeSeconds: $result->timeTotalSeconds,
            structureName: $result->structure?->name ?? '',
        );

        $rank1 = [];
        $rank1InitiallyBuy = [];
        $subItems = [];
        $pending = [];

        $runs = max(1, $result->runs);
        foreach ($result->materialsActual as $material) {
            $item = $this->makeItem(
                typeId: $material['type_id'],
                name: $material['name'],
                groupId: $material['group_id'] ?? 0,
                qtyBase: intdiv($material['qty_base'], $runs),
                qtyActual: intdiv($material['qty_actual'], $runs),
                qtyTotal: $material['qty_actual'],
                prices: $prices,
                overrides: $overrides,
                includeReactions: $includeReactions,
                timeSkills: $timeSkills,
            );
            $rank1[] = $item;

            // A reaction intermediate is offered for purchase when the user
            // turned reaction production off.
            if (! $includeReactions && $this->isReactable($item->typeId) && ! $this->isManufacturable($item->typeId))
                $rank1InitiallyBuy[] = $item->typeId;

            if ($item->isReactionOutput && $item->runs > 0 && ! isset($bought['1:' . $item->typeId]))
                $pending[$item->typeId] = $item;
        }

        $visited = [$rootTypeId => true];
        foreach ($rank1 as $item) {
            $visited[$item->typeId] = true;
        }

        for ($depth = 0; $depth < Constants::MAX_TREE_DEPTH; $depth++) {
            if (empty($pending))
                break;
            $nextPending = [];

            foreach ($pending as $parentId => $parent) {
                $materials = $this->materialsOf($parentId, $includeReactions);
                if (empty($materials))
                    continue;

                $children = [];
                foreach ($materials as $material) {
                    $child = $this->makeItem(
                        typeId: $material->typeId,
                        name: $material->name,
                        groupId: $material->groupId,
                        qtyBase: $material->quantity,
                        qtyActual: $material->quantity,
                        qtyTotal: $this->childQuantity($parentId, $parent, $material, $overrides),
                        prices: $prices,
                        overrides: $overrides,
                        includeReactions: $includeReactions,
                        timeSkills: $timeSkills,
                    );
                    $children[] = $child;

                    if (isset($visited[$child->typeId]))
                        continue;
                    $existing = $nextPending[$child->typeId] ?? null;
                    $nextPending[$child->typeId] = $existing !== null
                        ? $this->mergeItems($existing, $child, $prices, $overrides, $includeReactions, $timeSkills)
                        : $child;
                }

                if (! empty($children))
                    $subItems[$parentId] = $children;
            }

            foreach ($nextPending as $typeId => $_) {
                $visited[$typeId] = true;
            }
            $rank = $depth + 2;
            $pending = array_filter($nextPending, fn (TreeItem $i) => $i->isReactionOutput && $i->runs > 0
                && ! isset($bought[$rank . ':' . $i->typeId]));
        }

        return new TreeData($output, $rank1, $subItems, $rank1InitiallyBuy);
    }

    /**
     * Quantity of a material needed for every run of a component.
     */
    private function childQuantity(int $parentId, TreeItem $parent, Material $material, array $overrides): int
    {
        $base = $material->quantity * $parent->runs;
        if (! $this->componentBonuses)
            return $base;

        $blueprint = $this->blueprints[$parentId] ?? null;
        if ($blueprint !== null) {
            $meLevel = $this->efficiencyOf($parentId)['me'] ?? 0;
            $structure = $this->structureFor($parentId, $blueprint->productCategory, $overrides);
            $multiplier = (1.0 - $meLevel / 100.0)
                * ($structure?->effectiveMeMultiplier($blueprint->productCategory) ?? 1.0);

            return max(1, (int) ceil($base * $multiplier));
        }

        $formula = $this->formulas[$parentId] ?? null;
        if ($formula !== null) {
            $structure = $this->structureFor($parentId, $formula->category, $overrides);
            $multiplier = $structure?->effectiveMeMultiplier($formula->category) ?? 1.0;

            return (int) ceil($base * $multiplier);
        }

        return $base;
    }

    private function efficiencyOf(int $productId): ?array
    {
        return $this->blueprintEfficiency ? ($this->blueprintEfficiency)($productId) : null;
    }

    /** @return Material[] */
    private function materialsOf(int $typeId, bool $includeReactions): array
    {
        $blueprint = $this->blueprints[$typeId] ?? null;
        if ($blueprint !== null)
            return $blueprint->materials;

        $formula = $this->formulas[$typeId] ?? null;
        if ($formula !== null && $includeReactions)
            return $formula->materials;

        return [];
    }

    private function makeItem(
        int $typeId,
        string $name,
        int $groupId,
        int $qtyBase,
        int $qtyActual,
        int $qtyTotal,
        array $prices,
        array $overrides,
        bool $includeReactions,
        array $timeSkills,
    ): TreeItem {
        [$runs, $produced, $surplus, $jobCost, $category] = $this->productionPlan($typeId, $qtyTotal, $prices, $overrides, $includeReactions);
        [$seconds, $structureName] = $this->durationAndStructure($typeId, $runs, $category, $overrides, $timeSkills);

        return new TreeItem(
            typeId: $typeId,
            name: $name,
            groupId: $groupId ?: $this->types->groupId($typeId),
            qtyBase: $qtyBase,
            qtyActual: $qtyActual,
            qtyTotal: $qtyTotal,
            isReactionOutput: $runs > 0,
            producedByReaction: $this->usesReaction($typeId),
            runs: $runs,
            qtyProduced: $produced,
            surplus: $surplus,
            jobCost: $jobCost,
            volume: $this->types->volume($typeId),
            productCategory: $category,
            timeSeconds: $seconds,
            structureName: $structureName,
        );
    }

    /**
     * Total run duration of an item and its structure: skills, then rigs and
     * role, rounded down once.
     *
     * @return array{0:int, 1:string}
     */
    private function durationAndStructure(int $typeId, int $runs, string $category, array $overrides, array $timeSkills): array
    {
        if ($runs <= 0)
            return [0, ''];

        $blueprint = $this->blueprints[$typeId] ?? null;
        if ($blueprint !== null) {
            $baseTime = $blueprint->timePerRun;
            $activity = 'manufacturing';
        } else {
            $formula = $this->formulas[$typeId] ?? null;
            if ($formula === null)
                return [0, ''];
            $baseTime = $formula->timePerRun;
            $activity = 'reaction';
        }

        $structure = $this->structureFor($typeId, $category, $overrides);
        $multiplier = 1.0 - ($timeSkills[$activity] ?? 0.0);
        if ($structure !== null)
            $multiplier *= $structure->effectiveTeMultiplier($category);

        // SeAT mode: the owned blueprint TE applies to the component (basic
        // mode assumes a blueprint without TE).
        if ($this->componentBonuses && $activity === 'manufacturing')
            $multiplier *= 1.0 - ($this->efficiencyOf($typeId)['te'] ?? 0) / 100.0;

        $perRun = max(1, (int) floor($baseTime * $multiplier));

        return [$perRun * $runs, $structure?->name ?? ''];
    }

    /**
     * Merges two needs for the same item: runs are recomputed on the
     * consolidated quantity, not added up.
     */
    private function mergeItems(TreeItem $existing, TreeItem $addition, array $prices, array $overrides, bool $includeReactions, array $timeSkills): TreeItem
    {
        $mergedQty = $existing->qtyTotal + $addition->qtyTotal;
        [$runs, $produced, $surplus, $jobCost, $category] = $this->productionPlan($existing->typeId, $mergedQty, $prices, $overrides, $includeReactions);
        $category = $category ?: $existing->productCategory;
        [$seconds, $structureName] = $this->durationAndStructure($existing->typeId, $runs, $category, $overrides, $timeSkills);

        return $existing->with([
            'qtyTotal' => $mergedQty,
            'runs' => $runs,
            'qtyProduced' => $produced,
            'surplus' => $surplus,
            'jobCost' => $jobCost,
            'productCategory' => $category,
            'timeSeconds' => $seconds,
            'structureName' => $structureName,
        ]);
    }

    /**
     * @return array{0:int, 1:int, 2:int, 3:float, 4:string} (runs, produced, overproduction, cost, category)
     */
    private function productionPlan(int $typeId, int $qtyNeeded, array $prices, array $overrides, bool $includeReactions): array
    {
        $blueprint = $this->blueprints[$typeId] ?? null;
        if ($blueprint !== null)
            return $this->planFrom($qtyNeeded, $blueprint->outputQuantity, $blueprint->materials, $blueprint->productCategory, $typeId, $prices, $overrides);

        $formula = $this->formulas[$typeId] ?? null;
        if ($formula !== null && $includeReactions)
            return $this->planFrom($qtyNeeded, $formula->outputQuantity, $formula->materials, $formula->category, $typeId, $prices, $overrides);

        // Item that cannot be produced (ore, raw material): bought.
        return [0, 0, 0, 0.0, ''];
    }

    private function planFrom(int $qtyNeeded, int $outputPerRun, array $materials, string $category, int $typeId, array $prices, array $overrides): array
    {
        $outputPerRun = max(1, $outputPerRun);
        $runs = max(1, (int) ceil($qtyNeeded / $outputPerRun));
        $produced = $runs * $outputPerRun;
        $surplus = $produced - $qtyNeeded;

        $structure = $this->structureFor($typeId, $category, $overrides);
        $jobCost = 0.0;
        if ($structure !== null && ! empty($prices)) {
            $eiv = 0.0;
            foreach ($materials as $m) {
                $eiv += $m->quantity * ($prices[$m->typeId] ?? 0.0);
            }
            $jobCost = $eiv * $runs * $structure->jobCostRate();
        }

        return [$runs, $produced, $surplus, $jobCost, $category];
    }

    // -----------------------------------------------------------------
    // Structures offered in the tree
    // -----------------------------------------------------------------

    /**
     * Selectable structures for every producible item.
     *
     * @param  TreeItem[]  $items
     * @return array<int, array{0: array<int, array{0:string,1:string}>, 1: ?string}>
     */
    public function structuresForItems(array $items, array $overrides = []): array
    {
        $manufacturing = array_map(fn ($s) => [$s->id, $s->name], $this->setup->manufacturingStructures());
        $reaction = array_map(fn ($s) => [$s->id, $s->name], $this->setup->reactionStructures());

        $result = [];
        foreach ($items as $item) {
            if (! $item->isReactionOutput || isset($result[$item->typeId]))
                continue;

            $usesReaction = $this->usesReaction($item->typeId);
            $available = $usesReaction ? $reaction : $manufacturing;
            if (empty($available))
                continue;

            $selected = $overrides[$item->typeId] ?? null;
            if ($selected === null) {
                $selected = $this->setup->defaultStructureFor($item->productCategory, $usesReaction ? 'reaction' : 'manufacturing')?->id;
            }
            $result[$item->typeId] = [$available, $selected];
        }

        return $result;
    }
}
