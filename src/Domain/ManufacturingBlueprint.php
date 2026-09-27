<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Manufacturing blueprint from the SDE ("manufacturing" activity).
 */
final class ManufacturingBlueprint
{
    /**
     * @param  Material[]  $materials
     */
    public function __construct(
        public int $blueprintTypeId,
        public int $outputTypeId,
        public string $outputName,
        public int $outputQuantity, // per run
        public int $timePerRun,     // seconds
        public string $productCategory = Constants::DEFAULT_MANUFACTURING_CATEGORY,
        public array $materials = [],
    ) {
    }
}
