<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Tests\Domain;

use EveDev\Seat\IndusPlanner\Domain\Calculator;
use EveDev\Seat\IndusPlanner\Domain\IndustrialStructure;
use EveDev\Seat\IndusPlanner\Domain\IndustryManager;
use EveDev\Seat\IndusPlanner\Domain\IndustrySetup;
use EveDev\Seat\IndusPlanner\Domain\ManufacturingBlueprint;
use EveDev\Seat\IndusPlanner\Domain\Material;
use EveDev\Seat\IndusPlanner\Domain\Plan;
use EveDev\Seat\IndusPlanner\Domain\ReactionFormula;
use EveDev\Seat\IndusPlanner\Domain\TreeData;
use EveDev\Seat\IndusPlanner\Domain\TreeItem;
use EveDev\Seat\IndusPlanner\Domain\TypeInfo;
use PHPUnit\Framework\TestCase;

/**
 * Production tree and production plan: durations, jobs, purchases, purchase
 * families, Multibuy, stock tooltip and SeAT mode.
 */
class ProductionPlanTest extends TestCase
{
    private const FINAL = 1000;
    private const COMPONENT = 2000;
    private const RAW = 34;
    private const RAW2 = 16640;
    private const SKILL = 0.10;

    private function tatara(): IndustrialStructure
    {
        return new IndustrialStructure('tatara', 'Tatara', 35836, 1, 'X', 'Null / Wormhole', [], 0.05);
    }

    private function manager(bool $componentBonuses = false, ?callable $efficiency = null, ?array $structures = null): IndustryManager
    {
        $noData = new class implements TypeInfo {
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
            $noData,
            new IndustrySetup($structures ?? [$this->tatara()]),
            [self::FINAL => new ManufacturingBlueprint(1, self::FINAL, 'Final product', 1, 1_000, 'equipment', [
                new Material(self::COMPONENT, 'Component', 130, 0),
                new Material(self::RAW, 'Tritanium', 500, 0),
            ])],
            [self::COMPONENT => new ReactionFormula(2, self::COMPONENT, 'Component', 100, 10_800,
                [new Material(self::RAW2, 'Gas', 200, 20)], 'composite')],
            $componentBonuses,
            $efficiency,
        );
    }

    /** @return array{0: \EveDev\Seat\IndusPlanner\Domain\SimulationResult, 1: TreeData} */
    private function simulate(int $quantity = 2): array
    {
        $manager = $this->manager();
        $result = Calculator::manufacturing($manager->blueprints[self::FINAL], null, $quantity);
        $tree = $manager->buildProductionTree($result, $quantity, timeSkills: ['reaction' => self::SKILL]);

        return [$result, $tree];
    }

    private function entriesOf(TreeData $tree, array $buy = []): array
    {
        $mode = fn (TreeItem $i) => (in_array($i->typeId, $buy, true) || ! $i->isReactionOutput) ? Plan::MODE_BUY : Plan::MODE_PRODUCE;
        $entries = [['item' => $tree->output, 'rank' => 0, 'mode' => 'P']];
        foreach ($tree->rank1 as $item) {
            $entries[] = ['item' => $item, 'rank' => 1, 'mode' => $mode($item)];
        }
        foreach ($tree->subItems as $parentId => $children) {
            if (in_array($parentId, $buy, true))
                continue;
            foreach ($children as $item) {
                $entries[] = ['item' => $item, 'rank' => 2, 'mode' => $mode($item)];
            }
        }

        return $entries;
    }

    private function component(TreeData $tree): TreeItem
    {
        foreach ($tree->rank1 as $item) {
            if ($item->typeId === self::COMPONENT)
                return $item;
        }
        $this->fail('composant absent');
    }

    // --------------------------------------------------------- Durations

    public function test_component_duration_follows_the_reaction_chain(): void
    {
        [, $tree] = $this->simulate();
        $component = $this->component($tree);
        $this->assertSame(3, $component->runs);
        $perRun = (int) floor(10_800 * (1 - self::SKILL) * $this->tatara()->effectiveTeMultiplier('composite'));
        $this->assertSame($perRun * 3, $component->timeSeconds);
    }

    public function test_component_carries_its_structure(): void
    {
        [, $tree] = $this->simulate();
        $this->assertSame('Tatara', $this->component($tree)->structureName);
    }

    public function test_root_keeps_the_simulated_duration(): void
    {
        [$result, $tree] = $this->simulate();
        $this->assertSame($result->timeTotalSeconds, $tree->output->timeSeconds);
    }

    public function test_raw_material_has_no_duration(): void
    {
        [, $tree] = $this->simulate();
        $raw = array_values(array_filter($tree->rank1, fn ($i) => $i->typeId === self::RAW))[0];
        $this->assertSame([0, ''], [$raw->timeSeconds, $raw->structureName]);
    }

    // ------------------------------------------------------------- Jobs

    public function test_reactions_come_before_the_final_product(): void
    {
        [, $tree] = $this->simulate();
        $jobs = Plan::productionJobs($this->entriesOf($tree));
        $this->assertSame([self::COMPONENT, self::FINAL], array_column($jobs, 'type_id'));
        $this->assertSame([false, true], array_column($jobs, 'is_final'));
        $this->assertSame(['reaction', 'manufacturing'], array_column($jobs, 'activity'));
    }

    public function test_quantities_match_the_tree(): void
    {
        [, $tree] = $this->simulate();
        $c = Plan::productionJobs($this->entriesOf($tree))[0];
        $this->assertSame([3, 300, 260, 40], [$c['runs'], $c['qty_produced'], $c['qty_needed'], $c['surplus']]);
    }

    public function test_bought_component_is_no_longer_a_job(): void
    {
        [, $tree] = $this->simulate();
        $jobs = Plan::productionJobs($this->entriesOf($tree, [self::COMPONENT]));
        $this->assertSame([self::FINAL], array_column($jobs, 'type_id'));
    }

    public function test_same_article_is_grouped_on_one_line(): void
    {
        [, $tree] = $this->simulate();
        $c = $this->component($tree);
        $jobs = Plan::productionJobs([['item' => $c, 'rank' => 1, 'mode' => 'P'], ['item' => $c, 'rank' => 2, 'mode' => 'P']]);
        $this->assertCount(1, $jobs);
        $this->assertSame(2 * $c->runs, $jobs[0]['runs']);
        $this->assertSame(2, $jobs[0]['rank']);
    }

    public function test_deeper_reactions_are_launched_first(): void
    {
        [, $tree] = $this->simulate();
        $c = $this->component($tree);
        $deeper = $c->with(['typeId' => 3000, 'name' => 'Profond']);
        $jobs = Plan::productionJobs([['item' => $c, 'rank' => 1, 'mode' => 'P'], ['item' => $deeper, 'rank' => 2, 'mode' => 'P']]);
        $this->assertSame([3000, self::COMPONENT], array_column($jobs, 'type_id'));
    }

    public function test_ranks_run_from_deepest_to_final(): void
    {
        [, $tree] = $this->simulate();
        $c = $this->component($tree);
        $jobs = Plan::productionJobs([
            ['item' => $tree->output, 'rank' => 0, 'mode' => 'P'],
            ['item' => $c, 'rank' => 1, 'mode' => 'P'],
            ['item' => $c->with(['typeId' => 3000, 'name' => 'B']), 'rank' => 3, 'mode' => 'P'],
            ['item' => $c->with(['typeId' => 3001, 'name' => 'A']), 'rank' => 2, 'mode' => 'P'],
        ]);
        $this->assertSame([3, 2, 1, 0], array_column($jobs, 'rank'));
    }

    public function test_same_rank_lists_reactions_first_then_names(): void
    {
        [, $tree] = $this->simulate();
        $c = $this->component($tree);
        $jobs = Plan::productionJobs([
            ['item' => $c->with(['typeId' => 1, 'name' => 'Zeta', 'producedByReaction' => false]), 'rank' => 1, 'mode' => 'P'],
            ['item' => $c->with(['typeId' => 2, 'name' => 'Beta', 'producedByReaction' => true]), 'rank' => 1, 'mode' => 'P'],
            ['item' => $c->with(['typeId' => 3, 'name' => 'Alpha', 'producedByReaction' => true]), 'rank' => 1, 'mode' => 'P'],
        ]);
        $this->assertSame(['Alpha', 'Beta', 'Zeta'], array_column($jobs, 'name'));
    }

    // --------------------------------------------------------- Purchases

    public function test_only_bought_nodes_are_listed(): void
    {
        [, $tree] = $this->simulate();
        $lines = array_column(Plan::purchases($this->entriesOf($tree), []), null, 'type_id');
        $this->assertEqualsCanonicalizing([self::RAW, self::RAW2], array_keys($lines));
        $this->assertSame(1_000, $lines[self::RAW]['quantity']);
        $this->assertSame(600, $lines[self::RAW2]['quantity']);
    }

    public function test_a_component_bought_by_choice_is_listed(): void
    {
        [, $tree] = $this->simulate();
        $lines = Plan::purchases($this->entriesOf($tree, [self::COMPONENT]), []);
        $this->assertEqualsCanonicalizing([self::RAW, self::COMPONENT], array_column($lines, 'type_id'));
    }

    public function test_stock_is_deducted(): void
    {
        [, $tree] = $this->simulate();
        $lines = array_column(Plan::purchases($this->entriesOf($tree), [], [self::RAW => 300]), null, 'type_id');
        $this->assertSame([300, 700], [$lines[self::RAW]['in_stock'], $lines[self::RAW]['to_buy']]);
        $this->assertSame(600, $lines[self::RAW2]['to_buy']);
    }

    public function test_stock_larger_than_need_leaves_nothing_to_buy(): void
    {
        [, $tree] = $this->simulate();
        $lines = array_column(Plan::purchases($this->entriesOf($tree), [], [self::RAW => 5_000]), null, 'type_id');
        $this->assertSame(0, $lines[self::RAW]['to_buy']);
    }

    public function test_prices_and_volumes_feed_the_totals(): void
    {
        [, $tree] = $this->simulate();
        $lines = array_column(Plan::purchases($this->entriesOf($tree), [self::RAW => ['jita_sell' => 5.0, 'volume' => 0.01]]), null, 'type_id');
        $this->assertSame(5_000.0, $lines[self::RAW]['total_price']);
        $this->assertEqualsWithDelta(10.0, $lines[self::RAW]['total_volume'], 1e-9);
    }

    // -------------------------------------------------- Purchase families

    private function groupEntries(array $specs): array
    {
        [, $tree] = $this->simulate();
        $template = $tree->rank1[1];

        return array_map(fn ($s) => [
            'item' => $template->with(['typeId' => $s[0], 'name' => $s[1], 'groupId' => $s[2], 'qtyTotal' => $s[3]]),
            'rank' => 1, 'mode' => Plan::MODE_BUY,
        ], $specs);
    }

    private function groupName(): callable
    {
        return fn (int $gid) => [10 => 'Mineral', 20 => 'Ice Product', 30 => 'Moon Materials'][$gid] ?? null;
    }

    public function test_lines_are_sorted_by_group_then_name(): void
    {
        $lines = Plan::purchases($this->groupEntries([
            [1, 'Tritanium', 10, 100], [2, 'Heavy Water', 20, 50], [3, 'Pyerite', 10, 40], [4, 'Neodymium', 30, 5],
        ]), [], null, $this->groupName());
        $this->assertSame(
            [['Ice Product', 'Heavy Water'], ['Mineral', 'Pyerite'], ['Mineral', 'Tritanium'], ['Moon Materials', 'Neodymium']],
            array_map(fn ($l) => [$l['group_name'], $l['name']], $lines),
        );
    }

    public function test_unknown_group_comes_last(): void
    {
        $lines = Plan::purchases($this->groupEntries([[1, 'Mystere', 999, 1], [2, 'Tritanium', 10, 1]]), [], null, $this->groupName());
        $this->assertSame(['Mineral', 'Other'], array_column($lines, 'group_name'));
    }

    public function test_planetary_products_are_named_by_tier(): void
    {
        $lines = Plan::purchases($this->groupEntries([
            [1, 'Water', 1042, 1], [2, 'Coolant', 1034, 1], [3, 'Robotics', 1040, 1],
            [4, 'Nano-Factory', 1041, 1], [5, 'Aqueous Liquids', 1031, 1],
        ]), [], null, fn ($gid) => 'Game name');
        $this->assertSame([
            'Aqueous Liquids' => 'Planetary products - tier 0',
            'Water' => 'Planetary products - tier 1',
            'Coolant' => 'Planetary products - tier 2',
            'Robotics' => 'Planetary products - tier 3',
            'Nano-Factory' => 'Planetary products - tier 4',
        ], array_column($lines, 'group_name', 'name'));
    }

    public function test_without_a_name_provider_everything_is_other(): void
    {
        $lines = Plan::purchases($this->groupEntries([[1, 'Tritanium', 10, 1]]), []);
        $this->assertSame('Other', $lines[0]['group_name']);
    }

    // -------------------------------------------------------- Multibuy

    private function multibuyLines(): array
    {
        return [
            ['name' => 'Tritanium', 'quantity' => 1_000, 'in_stock' => 300],
            ['name' => 'Pyerite', 'quantity' => 200, 'in_stock' => 200],
            ['name' => 'Heavy Water', 'quantity' => 50, 'in_stock' => 0],
        ];
    }

    public function test_full_quantities_when_stock_is_unknown(): void
    {
        $this->assertSame("Tritanium\t1000\nPyerite\t200\nHeavy Water\t50", Plan::multibuyText($this->multibuyLines(), false));
    }

    public function test_only_missing_quantities_when_stock_is_known(): void
    {
        $this->assertSame("Tritanium\t700\nHeavy Water\t50", Plan::multibuyText($this->multibuyLines(), true));
    }

    public function test_nothing_to_buy_gives_an_empty_text(): void
    {
        $this->assertSame('', Plan::multibuyText([], true));
    }

    // ------------------------------------------------------ Stock tooltip

    private const PLACES = [
        ['owner' => 'Sarge', 'owner_kind' => 'character', 'place' => 'Structure My Base', 'system' => 'Jita', 'quantity' => 200],
        ['owner' => 'My Corp', 'owner_kind' => 'corporation', 'place' => 'Corp depot', 'system' => 'Amarr', 'quantity' => 1_500],
    ];

    public function test_tooltip_lists_owner_station_system_and_quantity(): void
    {
        $text = Plan::stockTooltip(self::PLACES);
        $this->assertStringContainsString('Character Sarge', $text);
        $this->assertStringContainsString('Structure My Base (Jita)', $text);
        $this->assertStringContainsString('Corporation My Corp', $text);
        $this->assertStringContainsString('Corp depot (Amarr)', $text);
    }

    public function test_largest_stock_comes_first(): void
    {
        $lines = explode("\n", Plan::stockTooltip(self::PLACES));
        $this->assertStringContainsString('My Corp', $lines[1]);
    }

    public function test_system_is_not_repeated_when_the_station_already_names_it(): void
    {
        $text = Plan::stockTooltip([['owner' => 'A', 'owner_kind' => 'character', 'place' => 'Jita IV', 'system' => 'Jita', 'quantity' => 1]]);
        $this->assertStringNotContainsString('(Jita)', $text);
    }

    public function test_unknown_and_empty_stock(): void
    {
        $this->assertStringContainsString('unknown', Plan::stockTooltip([], false));
        $this->assertStringContainsString('No stock', Plan::stockTooltip([]));
    }

    public function test_a_long_list_is_truncated(): void
    {
        $many = array_map(fn ($i) => ['owner' => 'A', 'owner_kind' => 'character', 'place' => "Station {$i}", 'system' => '', 'quantity' => $i + 1], range(0, 19));
        $this->assertStringContainsString('and 8 more location(s)', Plan::stockTooltip($many));
    }

    // -------------------------------------------- SeAT mode (owned blueprints)

    public function test_basic_mode_ignores_component_bonuses(): void
    {
        // Basic mode: 3 runs of Component x 200 Gas, without any reduction.
        [, $tree] = $this->simulate();
        $gas = $tree->subItems[self::COMPONENT][0];
        $this->assertSame(600, $gas->qtyTotal);
    }

    public function test_seat_mode_applies_structure_me_to_components(): void
    {
        // Athanor with a composite rig: -2 % ME x 2.1 (nullsec) = 4.2 %.
        $athanor = new IndustrialStructure('ath', 'Athanor', 35835, 1, 'X', 'Null / Wormhole', [
            new \EveDev\Seat\IndusPlanner\Domain\StructureRig(46484, 'Rig composite', 1933, -2.0, 0.0, 1.0, 2.1),
        ]);
        $manager = $this->manager(true, null, [$athanor]);
        $result = Calculator::manufacturing($manager->blueprints[self::FINAL], null, 2);
        $tree = $manager->buildProductionTree($result, 2);
        $gas = $tree->subItems[self::COMPONENT][0];
        $this->assertSame((int) ceil(600 * (1 - 0.042)), $gas->qtyTotal);
    }

    public function test_seat_mode_applies_owned_blueprint_me_to_manufactured_components(): void
    {
        // A manufactured (not reacted) component with an ME 10 blueprint.
        $noData = new class implements TypeInfo {
            public function groupId(int $typeId): int
            {
                return 0;
            }

            public function volume(int $typeId): float
            {
                return 0.0;
            }
        };
        $blueprints = [
            self::FINAL => new ManufacturingBlueprint(1, self::FINAL, 'Final product', 1, 1_000, 'equipment', [new Material(self::COMPONENT, 'Component', 10, 0)]),
            self::COMPONENT => new ManufacturingBlueprint(3, self::COMPONENT, 'Component', 1, 100, 'advanced_component', [new Material(self::RAW, 'Tritanium', 100, 0)]),
        ];
        $efficiency = fn (int $productId) => $productId === self::COMPONENT ? ['me' => 10, 'te' => 20] : null;

        $basic = new IndustryManager($noData, new IndustrySetup(), $blueprints, [], false);
        $seat = new IndustryManager($noData, new IndustrySetup(), $blueprints, [], true, $efficiency);

        $result = Calculator::manufacturing($blueprints[self::FINAL], null, 1);
        $basicTree = $basic->buildProductionTree($result, 1);
        $seatTree = $seat->buildProductionTree($result, 1);

        $this->assertSame(1000, $basicTree->subItems[self::COMPONENT][0]->qtyTotal);
        $this->assertSame(900, $seatTree->subItems[self::COMPONENT][0]->qtyTotal);
        // TE 20 of the owned blueprint: 10 runs x 100 s x 0.8.
        $this->assertSame(800, $seatTree->rank1[0]->timeSeconds);
        $this->assertSame(1000, $basicTree->rank1[0]->timeSeconds);
    }
}
