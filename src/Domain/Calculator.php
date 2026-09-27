<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Reaction and manufacturing simulations: pure functions, testable in
 * isolation.
 */
final class Calculator
{
    /**
     * Simulates a reaction.
     *
     * @param  float  $skillTeReduction  TE reduction from skills (0 to 1)
     * @param  float  $implantTeReduction  TE reduction from implants (0 to 1)
     * @param  array<int,float>|null  $adjustedPrices  {type_id: adjusted_price} for the EIV
     */
    public static function reaction(
        ReactionFormula $formula,
        ?IndustrialStructure $structure,
        int $runs,
        float $skillTeReduction = 0.0,
        float $implantTeReduction = 0.0,
        ?array $adjustedPrices = null,
    ): SimulationResult {
        $runs = max(1, $runs);

        if ($structure !== null) {
            $meMultiplier = $structure->effectiveMeMultiplier($formula->category);
            [, $rigTe] = $structure->rigBonusesFor($formula->category);
            $rigTeMult = 1.0 + $rigTe / 100.0; // negative bonus -> multiplier < 1
            $roleTeMult = 1.0 - $structure->roleTe() / 100.0;
        } else {
            $meMultiplier = 1.0;
            $rigTeMult = 1.0;
            $roleTeMult = 1.0;
        }

        $skillTeMult = (1.0 - $skillTeReduction) * (1.0 - $implantTeReduction);
        $totalTeMult = $skillTeMult * $rigTeMult * $roleTeMult;

        // Materials: the ME reduction applies to the total quantity, rounded
        // up as in game.
        $materials = [];
        foreach ($formula->materials as $material) {
            $materials[] = [
                'type_id' => $material->typeId,
                'name' => $material->name,
                'group_id' => $material->groupId,
                'qty_base' => $material->quantity * $runs,
                'qty_actual' => (int) ceil($material->quantity * $runs * $meMultiplier),
            ];
        }

        // Time: skills, then rigs, then role; rounded down once, at the end.
        $timePerRun = max(1, (int) floor($formula->timePerRun * $totalTeMult));

        [$jobCost, $sccCost, $facilityCost, $total] = self::jobCosts($formula->materials, $structure, $runs, $adjustedPrices);

        return new SimulationResult(
            blueprint: null,
            formula: $formula,
            structure: $structure,
            runs: $runs,
            outputQuantity: $formula->outputQuantity * $runs,
            materialsActual: $materials,
            timePerRunSeconds: $timePerRun,
            timeTotalSeconds: $timePerRun * $runs,
            meReductionPct: (1.0 - $meMultiplier) * 100.0,
            teReductionPct: (1.0 - $totalTeMult) * 100.0,
            jobCost: $jobCost,
            sccCost: $sccCost,
            facilityCost: $facilityCost,
            totalJobCost: $total,
        );
    }

    /**
     * Simulates a manufacturing order.
     *
     * @param  int  $qty  final quantity wanted
     * @param  int  $meLevel  blueprint ME level (0 to 10)
     * @param  int  $teLevel  blueprint TE level (0 to 20)
     */
    public static function manufacturing(
        ManufacturingBlueprint $blueprint,
        ?IndustrialStructure $structure,
        int $qty,
        int $meLevel = 0,
        int $teLevel = 0,
        float $skillTeReduction = 0.0,
        ?array $adjustedPrices = null,
    ): SimulationResult {
        $outputQuantity = max(1, $blueprint->outputQuantity);
        $runs = max(1, (int) ceil(max(1, $qty) / $outputQuantity));
        $category = $blueprint->productCategory;

        // ME: 1 % per blueprint level, stacked multiplicatively with the
        // structure role and rigs.
        $bpMeMult = 1.0 - $meLevel / 100.0;
        $bpTeMult = 1.0 - $teLevel / 100.0;

        if ($structure !== null) {
            $roleMeMult = 1.0 - $structure->roleMe() / 100.0;
            $roleTeMult = 1.0 - $structure->roleTe() / 100.0;
            [$rigMe, $rigTe] = $structure->rigBonusesFor($category);
            $rigMeMult = 1.0 + $rigMe / 100.0;
            $rigTeMult = 1.0 + $rigTe / 100.0;
        } else {
            $roleMeMult = $roleTeMult = $rigMeMult = $rigTeMult = 1.0;
        }

        $totalMeMult = $bpMeMult * $roleMeMult * $rigMeMult;
        $totalTeMult = $bpTeMult * $roleTeMult * $rigTeMult * (1.0 - $skillTeReduction);

        $materials = [];
        foreach ($blueprint->materials as $material) {
            $materials[] = [
                'type_id' => $material->typeId,
                'name' => $material->name,
                'group_id' => $material->groupId,
                'qty_base' => $material->quantity * $runs,
                // A job always consumes at least one unit of each material.
                'qty_actual' => max(1, (int) ceil($material->quantity * $runs * $totalMeMult)),
            ];
        }

        $timePerRun = max(1, (int) floor($blueprint->timePerRun * $totalTeMult));

        [$jobCost, $sccCost, $facilityCost, $total] = self::jobCosts($blueprint->materials, $structure, $runs, $adjustedPrices);

        return new SimulationResult(
            blueprint: $blueprint,
            formula: null,
            structure: $structure,
            runs: $runs,
            outputQuantity: $runs * $outputQuantity,
            materialsActual: $materials,
            timePerRunSeconds: $timePerRun,
            timeTotalSeconds: $timePerRun * $runs,
            meReductionPct: (1.0 - $totalMeMult) * 100.0,
            teReductionPct: (1.0 - $totalTeMult) * 100.0,
            jobCost: $jobCost,
            sccCost: $sccCost,
            facilityCost: $facilityCost,
            totalJobCost: $total,
            meLevel: $meLevel,
            teLevel: $teLevel,
        );
    }

    /**
     * The three parts of the job installation cost. The EIV uses the raw SDE
     * quantities (no ME bonus) multiplied by the adjusted price.
     *
     * @param  Material[]  $materials
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function jobCosts(array $materials, ?IndustrialStructure $structure, int $runs, ?array $adjustedPrices): array
    {
        if (empty($adjustedPrices) || $structure === null)
            return [0.0, 0.0, 0.0, 0.0];

        $eiv = 0.0;
        foreach ($materials as $material) {
            $eiv += $material->quantity * ($adjustedPrices[$material->typeId] ?? 0.0);
        }
        $eiv *= $runs;

        $job = $eiv * $structure->systemCostIndex;
        $scc = $eiv * Constants::SCC_TAX_RATE;
        $facility = $eiv * ($structure->facilityTaxPct / 100.0);

        return [$job, $scc, $facility, $job + $scc + $facility];
    }

}
