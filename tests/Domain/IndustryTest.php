<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Tests\Domain;

use EveDev\Seat\IndusPlanner\Domain\Calculator;
use EveDev\Seat\IndusPlanner\Domain\IndustrialStructure;
use EveDev\Seat\IndusPlanner\Domain\IndustrySetup;
use EveDev\Seat\IndusPlanner\Domain\ManufacturingBlueprint;
use EveDev\Seat\IndusPlanner\Domain\Material;
use EveDev\Seat\IndusPlanner\Domain\ReactionFormula;
use EveDev\Seat\IndusPlanner\Domain\StructureRig;
use PHPUnit\Framework\TestCase;

/**
 * Industry engine: structure bonuses, manufacturing, reactions, setup.
 */
class IndustryTest extends TestCase
{
    private function raitaru(array $rigs = [], string $security = 'Null / Wormhole'): IndustrialStructure
    {
        return new IndustrialStructure('test-raitaru', 'Test Raitaru', 35825, 30000142, 'Jita', $security, $rigs, 0.05, 1.0);
    }

    private function blueprint(int $outputQuantity = 1, int $timePerRun = 1000, ?array $materials = null): ManufacturingBlueprint
    {
        return new ManufacturingBlueprint(1, 2, 'Test item', $outputQuantity, $timePerRun, 'equipment',
            $materials ?? [new Material(34, 'Tritanium', 100, 18)]);
    }

    private function equipmentRig(): StructureRig
    {
        return new StructureRig(37155, 'Rig equipement', 1816, -2.0, 0.0, 1.9, 2.1);
    }

    private function formula(): ReactionFormula
    {
        return new ReactionFormula(1, 16671, 'Test product', 100, 10_800,
            [new Material(16640, 'Matiere', 100)], 'composite');
    }

    // --------------------------------------------------- Structure bonuses

    public function test_role_bonus_only(): void
    {
        $s = $this->raitaru();
        $this->assertEqualsWithDelta(1.0, $s->totalMeReduction('equipment'), 1e-9);
        $this->assertEqualsWithDelta(15.0, $s->totalTeReduction('equipment'), 1e-9);
    }

    public function test_rig_applies_only_to_its_category(): void
    {
        $s = $this->raitaru([$this->equipmentRig()]);
        $this->assertGreaterThan(1.0, $s->totalMeReduction('equipment'));
        $this->assertEqualsWithDelta(1.0, $s->totalMeReduction('ammunition'), 1e-9);
    }

    public function test_nullsec_multiplier_amplifies_rig(): void
    {
        $high = $this->raitaru([$this->equipmentRig()], 'Highsec');
        $null = $this->raitaru([$this->equipmentRig()], 'Null / Wormhole');
        $this->assertGreaterThan($high->totalMeReduction('equipment'), $null->totalMeReduction('equipment'));
    }

    public function test_job_cost_rate_sums_three_components(): void
    {
        $this->assertEqualsWithDelta(0.10, $this->raitaru()->jobCostRate(), 1e-9);
    }

    // -------------------------------------------------------- Manufacturing

    public function test_runs_rounded_up(): void
    {
        $r = Calculator::manufacturing($this->blueprint(3), null, 10);
        $this->assertSame(4, $r->runs);
        $this->assertSame(12, $r->outputQuantity);
    }

    public function test_me_level_reduces_materials(): void
    {
        $bp = $this->blueprint();
        $this->assertSame(100, Calculator::manufacturing($bp, null, 1, 0)->materialsActual[0]['qty_actual']);
        $this->assertSame(90, Calculator::manufacturing($bp, null, 1, 10)->materialsActual[0]['qty_actual']);
    }

    public function test_material_never_drops_below_one(): void
    {
        $bp = $this->blueprint(materials: [new Material(34, 'Tritanium', 1)]);
        $this->assertSame(1, Calculator::manufacturing($bp, null, 1, 10)->materialsActual[0]['qty_actual']);
    }

    public function test_te_reduces_time(): void
    {
        $bp = $this->blueprint(timePerRun: 1000);
        $this->assertSame(1000, Calculator::manufacturing($bp, null, 1)->timePerRunSeconds);
        $this->assertSame(800, Calculator::manufacturing($bp, null, 1, 0, 20)->timePerRunSeconds);
    }

    public function test_skills_and_structure_combine_multiplicatively(): void
    {
        $r = Calculator::manufacturing($this->blueprint(timePerRun: 1000), $this->raitaru(), 1, skillTeReduction: 0.20);
        // 1000 x 0.85 (structure) x 0.80 (skills) = 680
        $this->assertSame(680, $r->timePerRunSeconds);
    }

    public function test_job_cost_uses_base_quantities(): void
    {
        $r = Calculator::manufacturing($this->blueprint(), $this->raitaru(), 1, 10, adjustedPrices: [34 => 10.0]);
        $this->assertEqualsWithDelta(100 * 10.0 * 0.05, $r->jobCost, 1e-9);
        $this->assertEqualsWithDelta(100 * 10.0 * 0.04, $r->sccCost, 1e-9);
        $this->assertEqualsWithDelta(100 * 10.0 * 0.10, $r->totalJobCost, 1e-9);
    }

    public function test_no_structure_means_no_job_cost(): void
    {
        $this->assertSame(0.0, Calculator::manufacturing($this->blueprint(), null, 1, adjustedPrices: [34 => 10.0])->totalJobCost);
    }

    // -------------------------------------------------------------- Reaction

    public function test_output_scales_with_runs(): void
    {
        $r = Calculator::reaction($this->formula(), null, 5);
        $this->assertSame(500, $r->outputQuantity);
        $this->assertSame(5 * 10_800, $r->timeTotalSeconds);
    }

    public function test_runs_floor_at_one(): void
    {
        $this->assertSame(1, Calculator::reaction($this->formula(), null, 0)->runs);
    }

    public function test_skills_reduce_time(): void
    {
        $this->assertSame(8_640, Calculator::reaction($this->formula(), null, 1, 0.20)->timePerRunSeconds);
    }

    public function test_tatara_role_bonus_applies(): void
    {
        $tatara = new IndustrialStructure('t', 'Tatara', 35836, 1, 'X', 'Null / Wormhole');
        $this->assertSame(8_100, Calculator::reaction($this->formula(), $tatara, 1)->timePerRunSeconds);
    }

    // ---------------------------------------------------------------- Setup

    public function test_best_structure_for_category(): void
    {
        $weak = $this->raitaru();
        $strong = new IndustrialStructure('sotiyo', 'Sotiyo', 35827, 1, 'X', 'Null / Wormhole');
        $setup = new IndustrySetup([$weak, $strong]);
        $this->assertSame($strong, $setup->bestStructureFor('equipment', 'te'));
    }

    public function test_remove_and_get(): void
    {
        $s = $this->raitaru();
        $setup = new IndustrySetup([$s]);
        $this->assertNotNull($setup->getStructure($s->id));
        $this->assertTrue($setup->removeStructure($s->id));
        $this->assertFalse($setup->removeStructure($s->id));
        $this->assertNull($setup->getStructure($s->id));
    }

    // ------------------------------------------------ Structure vocation

    public function test_reaction_structure_is_never_chosen_for_manufacturing(): void
    {
        $tatara = new IndustrialStructure('t', 'Tatara', 35836, 1, 'X', 'Null / Wormhole');
        $raitaru = $this->raitaru();
        $setup = new IndustrySetup([$tatara, $raitaru]);
        // Without the vocation filter, the Tatara would win (25 % > 15 %).
        $this->assertSame($tatara, $setup->bestStructureFor('equipment', 'te'));
        $this->assertSame($raitaru, $setup->bestStructureFor('equipment', 'te', 'manufacturing'));
        $this->assertSame($tatara, $setup->bestStructureFor('composite', 'te', 'reaction'));
    }
}
