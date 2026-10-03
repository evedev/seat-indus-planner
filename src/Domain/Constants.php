<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * EVE Online industry constants.
 *
 * Reference tables: Upwell structures, product categories, rig/category
 * mapping and dogma attributes. These values come from game mechanics and
 * only change with a CCP patch.
 */
final class Constants
{
    // -----------------------------------------------------------------
    // Upwell industrial structures
    // `role_*`: intrinsic structure bonus, as a reduction in %.
    // -----------------------------------------------------------------
    public const STRUCTURE_TYPES = [
        35825 => ['name' => 'Raitaru', 'category' => 'manufacturing', 'role_me' => 1.0, 'role_te' => 15.0, 'role_cost' => 3.0, 'rig_size' => 'M'],
        35826 => ['name' => 'Azbel', 'category' => 'manufacturing', 'role_me' => 1.0, 'role_te' => 20.0, 'role_cost' => 4.0, 'rig_size' => 'L'],
        35827 => ['name' => 'Sotiyo', 'category' => 'manufacturing', 'role_me' => 1.0, 'role_te' => 30.0, 'role_cost' => 5.0, 'rig_size' => 'XL'],
        35835 => ['name' => 'Athanor', 'category' => 'reaction', 'role_me' => 0.0, 'role_te' => 0.0, 'role_cost' => 0.0, 'rig_size' => 'M'],
        35836 => ['name' => 'Tatara', 'category' => 'reaction', 'role_me' => 0.0, 'role_te' => 25.0, 'role_cost' => 0.0, 'rig_size' => 'L'],
    ];

    // -----------------------------------------------------------------
    // Product categories
    // -----------------------------------------------------------------
    public const PRODUCT_CATEGORIES = [
        'equipment', 'ammunition', 'drone_fighter',
        'basic_small_ship', 'basic_medium_ship', 'basic_large_ship',
        'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship',
        'capital_ship', 'advanced_component', 'basic_capital_component',
        'structure', 'invention', 'me_research', 'te_research', 'copy',
        'composite', 'hybrid', 'biochemical',
    ];

    public const REACTION_CATEGORIES = ['composite', 'hybrid', 'biochemical'];

    // -----------------------------------------------------------------
    // Rigs: group_id -> affected product categories
    // -----------------------------------------------------------------
    public const RIG_GROUP_CATEGORIES = [
        // M manufacturing rigs (Raitaru), ME bonus
        1816 => ['equipment'], 1820 => ['ammunition'], 1822 => ['drone_fighter'],
        1824 => ['basic_small_ship'], 1826 => ['basic_medium_ship'], 1828 => ['basic_large_ship'],
        1830 => ['advanced_small_ship'], 1832 => ['advanced_medium_ship'], 1834 => ['advanced_large_ship'],
        1836 => ['advanced_component'], 1839 => ['basic_capital_component'], 1840 => ['structure'],
        // M manufacturing rigs (Raitaru), TE bonus
        1819 => ['equipment'], 1821 => ['ammunition'], 1823 => ['drone_fighter'],
        1825 => ['basic_small_ship'], 1827 => ['basic_medium_ship'], 1829 => ['basic_large_ship'],
        1831 => ['advanced_small_ship'], 1833 => ['advanced_medium_ship'], 1835 => ['advanced_large_ship'],
        1837 => ['advanced_component'], 1838 => ['basic_capital_component'], 1841 => ['structure'],
        // M science rigs (Raitaru)
        1842 => ['invention'], 1843 => ['invention'], 1844 => ['me_research'], 1845 => ['me_research'],
        1846 => ['te_research'], 1847 => ['te_research'], 1848 => ['copy'], 1849 => ['copy'],
        // L manufacturing rigs (Azbel), combined ME and TE
        1850 => ['equipment'], 1851 => ['ammunition'], 1852 => ['drone_fighter'],
        1853 => ['basic_small_ship'], 1854 => ['basic_medium_ship'], 1855 => ['basic_large_ship'],
        1856 => ['advanced_small_ship'], 1857 => ['advanced_medium_ship'], 1858 => ['advanced_large_ship'],
        1859 => ['capital_ship'], 1860 => ['advanced_component'], 1861 => ['basic_capital_component'],
        1862 => ['structure'], 1863 => ['invention'], 1864 => ['me_research'], 1865 => ['te_research'],
        1866 => ['copy'],
        // XL manufacturing rigs (Sotiyo)
        1867 => ['equipment', 'ammunition'],
        1868 => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship',
            'advanced_medium_ship', 'advanced_large_ship', 'capital_ship'],
        1869 => ['structure', 'advanced_component', 'basic_capital_component'],
        1870 => ['invention', 'me_research', 'te_research', 'copy'],
        // M reaction rigs (Athanor)
        1933 => ['composite'], 1934 => ['composite'], 1935 => ['hybrid'], 1936 => ['hybrid'],
        1937 => ['biochemical'], 1938 => ['biochemical'],
        // L reaction rig (Tatara)
        1939 => ['composite', 'hybrid', 'biochemical'],
    ];

    // Rig groups reserved to reaction structures.
    public const REACTION_RIG_GROUPS = [1933, 1934, 1935, 1936, 1937, 1938, 1939];

    // -----------------------------------------------------------------
    // Space security
    // -----------------------------------------------------------------
    public const SECURITY_FACTORS = [
        'Highsec' => 0.5,
        'Lowsec' => 1.0,
        'Null / Wormhole' => 1.0,
    ];

    public const SECURITY_CLASSES = ['Highsec', 'Lowsec', 'Null / Wormhole'];

    // -----------------------------------------------------------------
    // Dogma attributes
    // -----------------------------------------------------------------
    public const ATTR_RIG_TIME_BONUS = 2593;
    public const ATTR_RIG_MAT_BONUS = 2594;
    public const ATTR_RIG_COST_BONUS = 2595;
    public const ATTR_RIG_REACT_TIME_BONUS = 2713;
    public const ATTR_RIG_REACT_MAT_BONUS = 2714;
    public const ATTR_LOWSEC_MULTIPLIER = 2356;
    public const ATTR_NULLSEC_MULTIPLIER = 2357;

    // -----------------------------------------------------------------
    // Product group -> category mapping
    // -----------------------------------------------------------------
    public const OUTPUT_GROUP_TO_REACTION_CATEGORY = [
        428 => 'composite',   // Intermediate Materials
        429 => 'composite',   // Composite
        712 => 'biochemical', // Biochemical Material
        974 => 'hybrid',      // Hybrid Polymers
        4096 => 'composite',  // Molecular-Forged Materials
        4932 => 'biochemical', // Unrefined Mineral
    ];

    public const GROUP_TO_MANUFACTURING_CATEGORY = [
        // Ammunition
        85 => 'ammunition', 86 => 'ammunition', 87 => 'ammunition',
        372 => 'ammunition', 373 => 'ammunition', 374 => 'ammunition',
        375 => 'ammunition', 376 => 'ammunition', 377 => 'ammunition',
        385 => 'ammunition', 386 => 'ammunition', 387 => 'ammunition',
        394 => 'ammunition',
        // Drones and fighters
        100 => 'drone_fighter', 101 => 'drone_fighter', 102 => 'drone_fighter',
        103 => 'drone_fighter', 104 => 'drone_fighter', 105 => 'drone_fighter',
        106 => 'drone_fighter', 107 => 'drone_fighter', 108 => 'drone_fighter',
        548 => 'drone_fighter', 549 => 'drone_fighter',
        // T1 small ships
        25 => 'basic_small_ship', 420 => 'basic_small_ship', 463 => 'basic_small_ship',
        31 => 'basic_small_ship', 237 => 'basic_small_ship',
        // T1 medium ships
        26 => 'basic_medium_ship', 419 => 'basic_medium_ship', 540 => 'basic_medium_ship',
        28 => 'basic_medium_ship', 941 => 'basic_medium_ship',
        // T1 large ships
        27 => 'basic_large_ship', 513 => 'basic_large_ship',
        // T2 small ships
        324 => 'advanced_small_ship', 543 => 'advanced_small_ship', 830 => 'advanced_small_ship',
        831 => 'advanced_small_ship', 893 => 'advanced_small_ship', 906 => 'advanced_small_ship',
        // T2 medium ships
        358 => 'advanced_medium_ship', 380 => 'advanced_medium_ship', 541 => 'advanced_medium_ship',
        832 => 'advanced_medium_ship', 833 => 'advanced_medium_ship',
        // T2 large ships
        898 => 'advanced_large_ship', 900 => 'advanced_large_ship',
        // Capitals
        547 => 'capital_ship', 30 => 'capital_ship', 485 => 'capital_ship',
        659 => 'capital_ship', 883 => 'capital_ship',
        // Components
        334 => 'advanced_component', 913 => 'advanced_component', 787 => 'basic_capital_component',
        // Upwell structures
        1404 => 'structure', 1406 => 'structure', 1408 => 'structure', 1410 => 'structure', 1657 => 'structure',
    ];

    public const DEFAULT_MANUFACTURING_CATEGORY = 'equipment';
    public const DEFAULT_REACTION_CATEGORY = 'composite';

    // SCC tax applied to the EIV, the same for every activity.
    public const SCC_TAX_RATE = 0.04;

    // Maximum exploration depth of the production tree.
    public const MAX_TREE_DEPTH = 4;

    // -----------------------------------------------------------------
    // Time reduction skills (skill_id => reduction per level)
    // -----------------------------------------------------------------
    public const REACTION_TIME_SKILLS = [
        45746 => 0.04, // Reactions
    ];

    public const MANUFACTURING_TIME_SKILLS = [
        3380 => 0.04, // Industry
        3388 => 0.03, // Advanced Industry
    ];

    // Reference skill used to rank characters.
    public const SKILL_ADVANCED_INDUSTRY = 3388;

    // SDE activities (ramActivities table).
    public const ACTIVITY_ID_MANUFACTURING = 1;
    public const ACTIVITY_ID_REACTION = 11;

    // Faction (navy and pirate) ships: their blueprint copies only exist with
    // one run, whatever the SDE limit says.
    public const META_GROUP_FACTION = 4;
    public const CATEGORY_SHIP = 6;

    /**
     * Converts an EVE security status into a space class.
     */
    public static function securityClass(float $status): string
    {
        if ($status >= 0.5)
            return 'Highsec';
        if ($status > 0.0)
            return 'Lowsec';

        return 'Null / Wormhole';
    }

    /**
     * Time reduction given by a set of skills; bonuses stack
     * multiplicatively, as in game.
     *
     * @param  array<int,int>  $levels  {skill_id: active level}
     * @param  array<int,float>  $table  {skill_id: reduction per level}
     * @return float fraction (0.15 = 15 %)
     */
    public static function timeReductionFromSkills(array $levels, array $table): float
    {
        $factor = 1.0;
        foreach ($table as $skillId => $perLevel) {
            $factor *= 1.0 - $perLevel * ($levels[$skillId] ?? 0);
        }

        return 1.0 - $factor;
    }
}
