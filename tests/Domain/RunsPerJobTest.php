<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Tests\Domain;

use EveDev\Seat\IndusPlanner\Domain\Calculator;
use EveDev\Seat\IndusPlanner\Domain\IndustryManager;
use EveDev\Seat\IndusPlanner\Domain\IndustrySetup;
use EveDev\Seat\IndusPlanner\Domain\ManufacturingBlueprint;
use EveDev\Seat\IndusPlanner\Domain\Material;
use EveDev\Seat\IndusPlanner\Domain\TypeInfo;
use PHPUnit\Framework\TestCase;

/**
 * Runs split into jobs (one blueprint copy per job) and materials computed
 * job by job, as in game.
 */
class RunsPerJobTest extends TestCase
{
    private const FINAL = 1000;
    private const COMPONENT = 2000;
    private const RAW = 34;

    public function test_runs_are_split_into_full_jobs_then_the_remainder(): void
    {
        $this->assertSame([3, 40, 2], Calculator::jobBatches(122, 40));
        $this->assertSame([10, 1, 0], Calculator::jobBatches(10, 1));
        $this->assertSame([1, 22, 0], Calculator::jobBatches(22, 40));
        $this->assertSame([1, 5, 0], Calculator::jobBatches(5, 0));
        $this->assertSame([0, 0, 0], Calculator::jobBatches(0, 10));
    }

    public function test_material_efficiency_is_rounded_job_by_job(): void
    {
        // One job of 10 runs: ceil(27.0); ten jobs of 1 run: 10 x ceil(2.7).
        $this->assertSame(27, Calculator::materialQuantity(3, 10, 10, 0.9));
        $this->assertSame(30, Calculator::materialQuantity(3, 10, 1, 0.9));
        // At least one unit per run.
        $this->assertSame(10, Calculator::materialQuantity(1, 10, 10, 0.5));
    }

    public function test_final_product_jobs_follow_the_runs_per_copy(): void
    {
        $manager = $this->manager(null);
        $blueprint = $manager->blueprints[self::FINAL];
        $single = Calculator::manufacturing($blueprint, null, 10, meLevel: 10);
        $copies = Calculator::manufacturing($blueprint, null, 10, meLevel: 10, runsPerJob: 1);
        $this->assertSame(10, $single->runsPerJob);
        $this->assertSame(1, $copies->runsPerJob);
        $this->assertSame(90, $single->materialsActual[0]['qty_actual']);
        $this->assertSame(90, $copies->materialsActual[0]['qty_actual']);
    }

    public function test_component_materials_depend_on_its_jobs(): void
    {
        $result = Calculator::manufacturing($this->manager(null)->blueprints[self::FINAL], null, 1);

        $oneJob = $this->manager(null)->buildProductionTree($result, 1);
        $tenJobs = $this->manager(1)->buildProductionTree($result, 1);

        $this->assertSame(27, $this->rawNeed($oneJob));
        $this->assertSame(30, $this->rawNeed($tenJobs));
        $this->assertSame(1, $this->component($tenJobs)->runsPerJob);
        $this->assertSame(10, $this->component($oneJob)->runsPerJob);
    }

    private function manager(?int $componentRunsPerJob): IndustryManager
    {
        $types = new class implements TypeInfo {
            public function groupId(int $typeId): int
            {
                return 0;
            }

            public function volume(int $typeId): float
            {
                return 0.0;
            }
        };

        return new IndustryManager(
            $types,
            new IndustrySetup([]),
            [
                self::FINAL => new ManufacturingBlueprint(1, self::FINAL, 'Final product', 1, 1_000, materials: [new Material(self::COMPONENT, 'Component', 10, 0)]),
                self::COMPONENT => new ManufacturingBlueprint(2, self::COMPONENT, 'Component', 1, 600, materials: [new Material(self::RAW, 'Tritanium', 3, 0)]),
            ],
            [],
            true,
            fn (int $productId) => $productId === self::COMPONENT ? ['me' => 10, 'te' => 0] : null,
            fn (int $productId) => $productId === self::COMPONENT ? $componentRunsPerJob : null,
        );
    }

    private function component($tree)
    {
        return array_values(array_filter($tree->rank1, fn ($i) => $i->typeId === self::COMPONENT))[0];
    }

    private function rawNeed($tree): int
    {
        return array_values(array_filter($tree->subItems[self::COMPONENT], fn ($i) => $i->typeId === self::RAW))[0]->qtyTotal;
    }
}
