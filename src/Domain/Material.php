<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Input material consumed by a blueprint or a reaction formula.
 */
final class Material
{
    public function __construct(
        public int $typeId,
        public string $name,
        public int $quantity, // base quantity per run, before ME bonuses
        public int $groupId = 0,
    ) {
    }
}
