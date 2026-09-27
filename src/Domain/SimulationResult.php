<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Result of a manufacturing or reaction simulation.
 *
 * Both kinds only differ by their source (`blueprint` or `formula`) and by
 * the blueprint ME/TE levels, so a single class covers them.
 */
final class SimulationResult
{
    /**
     * @param  array<int, array{type_id:int,name:string,group_id:int,qty_base:int,qty_actual:int}>  $materialsActual
     */
    public function __construct(
        public ?ManufacturingBlueprint $blueprint,
        public ?ReactionFormula $formula,
        public ?IndustrialStructure $structure,
        public int $runs,
        public int $outputQuantity, // quantity obtained (runs x output per run)
        public array $materialsActual,
        public int $timePerRunSeconds,
        public int $timeTotalSeconds,
        public float $meReductionPct,
        public float $teReductionPct,
        public float $jobCost = 0.0,
        public float $sccCost = 0.0,
        public float $facilityCost = 0.0,
        public float $totalJobCost = 0.0,
        public int $meLevel = 0,
        public int $teLevel = 0,
    ) {
    }

    public function isManufacturing(): bool
    {
        return $this->blueprint !== null;
    }

    public function outputTypeId(): int
    {
        return $this->blueprint?->outputTypeId ?? $this->formula->outputTypeId;
    }

    public function outputName(): string
    {
        return $this->blueprint?->outputName ?? $this->formula->outputName;
    }

    public function outputPerRun(): int
    {
        return $this->blueprint?->outputQuantity ?? $this->formula->outputQuantity;
    }

    public function category(): string
    {
        return $this->blueprint?->productCategory ?? $this->formula->category;
    }
}
