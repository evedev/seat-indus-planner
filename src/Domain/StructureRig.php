<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Rig fitted to a structure.
 *
 * Bonuses are percentages, negative for a reduction. The lowsec/nullsec
 * multipliers come from dogma attributes 2356/2357.
 */
final class StructureRig
{
    public function __construct(
        public int $typeId,
        public string $name,
        public int $groupId,
        public float $meBonus,
        public float $teBonus,
        public float $lowsecMult = 1.0,
        public float $nullMult = 1.0,
    ) {
    }

    /** @return string[] affected product categories */
    public function categories(): array
    {
        return Constants::RIG_GROUP_CATEGORIES[$this->groupId] ?? [];
    }
}
