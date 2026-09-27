<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Minimal access to the static type data the engine needs. Backed by the
 * SeAT SDE in production and by a fake data set in tests.
 */
interface TypeInfo
{
    public function groupId(int $typeId): int;

    public function volume(int $typeId): float;
}
