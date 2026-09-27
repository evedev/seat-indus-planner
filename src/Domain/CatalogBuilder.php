<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Builds usable manufacturing blueprints and reaction formulas from the SDE
 * activities, indexed by product.
 */
final class CatalogBuilder
{
    /**
     * @param  array<int, array<string, array{time:int, products:array<int,array{0:int,1:int}>, materials:array<int,array{0:int,1:int}>}>>  $activities
     *                                                                                                                                              {blueprint_type_id: {'manufacturing'|'reaction': {time, products: [[type_id, qty]], materials: [[type_id, qty]]}}}
     * @param  callable(int): ?string  $nameOf  type name, null when unknown
     * @return array{0: array<int, ManufacturingBlueprint>, 1: array<int, ReactionFormula>}
     */
    public static function build(array $activities, callable $nameOf, TypeInfo $types): array
    {
        $bestManufacturing = [];
        $bestReaction = [];

        // Walk blueprints by ascending id: on a perfect tie between two
        // blueprints, the first one is kept.
        ksort($activities);

        foreach ($activities as $blueprintId => $record) {
            foreach (['manufacturing', 'reaction'] as $activityName) {
                $activity = $record[$activityName] ?? null;
                if (! $activity || empty($activity['products']))
                    continue;

                $productId = (int) $activity['products'][0][0];
                if ($activityName === 'manufacturing') {
                    $previous = $bestManufacturing[$productId] ?? null;
                    if ($previous === null || self::isBetter($activity, $previous[1]))
                        $bestManufacturing[$productId] = [$blueprintId, $activity];
                } else {
                    $previous = $bestReaction[$productId] ?? null;
                    if ($previous === null || self::isBetter($activity, $previous[1]))
                        $bestReaction[$productId] = [$blueprintId, $activity];
                }
            }
        }

        $blueprints = [];
        foreach ($bestManufacturing as $productId => [$blueprintId, $activity]) {
            $name = $nameOf($productId);
            if (! $name)
                continue;
            $blueprints[$productId] = new ManufacturingBlueprint(
                blueprintTypeId: $blueprintId,
                outputTypeId: $productId,
                outputName: $name,
                outputQuantity: (int) ($activity['products'][0][1] ?? 1),
                timePerRun: (int) ($activity['time'] ?? 0),
                productCategory: self::manufacturingCategoryOf($types->groupId($productId)),
                materials: self::materials($activity, $nameOf, $types),
            );
        }

        $formulas = [];
        foreach ($bestReaction as $productId => [$blueprintId, $activity]) {
            $name = $nameOf($productId);
            if (! $name)
                continue;
            $formulas[$productId] = new ReactionFormula(
                formulaTypeId: $blueprintId,
                outputTypeId: $productId,
                outputName: $name,
                outputQuantity: (int) ($activity['products'][0][1] ?? 1),
                timePerRun: (int) ($activity['time'] ?? 3600),
                materials: self::materials($activity, $nameOf, $types),
                category: self::reactionCategoryOf($types->groupId($productId)),
            );
        }

        return [$blueprints, $formulas];
    }

    /**
     * A product may come from several blueprints: keep the best output per
     * run, otherwise the fastest one.
     */
    public static function isBetter(array $candidate, array $current): bool
    {
        $candQty = (int) ($candidate['products'][0][1] ?? 1);
        $currQty = (int) ($current['products'][0][1] ?? 1);
        if ($candQty !== $currQty)
            return $candQty > $currQty;

        return (int) ($candidate['time'] ?? 0) < (int) ($current['time'] ?? 0);
    }

    public static function manufacturingCategoryOf(int $groupId): string
    {
        return Constants::GROUP_TO_MANUFACTURING_CATEGORY[$groupId] ?? Constants::DEFAULT_MANUFACTURING_CATEGORY;
    }

    public static function reactionCategoryOf(int $groupId): string
    {
        return Constants::OUTPUT_GROUP_TO_REACTION_CATEGORY[$groupId] ?? Constants::DEFAULT_REACTION_CATEGORY;
    }

    /** @return Material[] */
    private static function materials(array $activity, callable $nameOf, TypeInfo $types): array
    {
        $out = [];
        foreach ($activity['materials'] ?? [] as [$typeId, $quantity]) {
            $typeId = (int) $typeId;
            $out[] = new Material(
                typeId: $typeId,
                name: $nameOf($typeId) ?: "Type {$typeId}",
                quantity: (int) ($quantity ?? 1),
                groupId: $types->groupId($typeId),
            );
        }

        return $out;
    }
}
