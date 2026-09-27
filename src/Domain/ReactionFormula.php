<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Reaction formula from the SDE ("reaction" activity).
 */
final class ReactionFormula
{
    /**
     * @param  Material[]  $materials
     */
    public function __construct(
        public int $formulaTypeId,
        public int $outputTypeId,
        public string $outputName,
        public int $outputQuantity, // per run, never affected by ME
        public int $timePerRun,     // seconds
        public array $materials = [],
        public string $category = Constants::DEFAULT_REACTION_CATEGORY,
    ) {
    }
}
