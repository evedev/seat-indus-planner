<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Set of structures taken into account for a user.
 */
final class IndustrySetup
{
    /** @var IndustrialStructure[] */
    public array $structures;

    /**
     * @param  IndustrialStructure[]  $structures
     */
    public function __construct(array $structures = [])
    {
        $this->structures = array_values($structures);
    }

    public function getStructure(?string $id): ?IndustrialStructure
    {
        foreach ($this->structures as $structure) {
            if ($structure->id === $id)
                return $structure;
        }

        return null;
    }

    public function removeStructure(string $id): bool
    {
        foreach ($this->structures as $index => $structure) {
            if ($structure->id === $id) {
                array_splice($this->structures, $index, 1);

                return true;
            }
        }

        return false;
    }

    /** @return IndustrialStructure[] */
    public function manufacturingStructures(): array
    {
        return array_values(array_filter($this->structures, fn ($s) => $s->structureCategory() === 'manufacturing'));
    }

    /** @return IndustrialStructure[] */
    public function reactionStructures(): array
    {
        return array_values(array_filter($this->structures, fn ($s) => $s->structureCategory() === 'reaction'));
    }

    /**
     * Structure giving the best reduction for a category, or null when none
     * gives any bonus.
     *
     * A structure role bonus applies to every category, so without a filter
     * a Tatara (25 % TE) could be picked for manufacturing and a Raitaru for a
     * reaction. `$vocation` restricts the search to structures able to run
     * the activity; null searches every structure.
     *
     * @param  string  $bonusType  'me' or 'te'
     * @param  string|null  $vocation  'manufacturing', 'reaction' or null
     */
    public function bestStructureFor(string $category, string $bonusType = 'me', ?string $vocation = null): ?IndustrialStructure
    {
        $best = null;
        $bestValue = 0.0;
        foreach ($this->structures as $structure) {
            if ($vocation !== null && $structure->structureCategory() !== $vocation)
                continue;
            $value = $bonusType === 'me'
                ? $structure->totalMeReduction($category)
                : $structure->totalTeReduction($category);
            if ($value > $bestValue) {
                $bestValue = $value;
                $best = $structure;
            }
        }

        return $best;
    }

    /**
     * Default structure for an activity: best TE reduction, otherwise best
     * ME reduction, otherwise the first structure able to run the activity
     * (so that an Athanor with ME rigs only is still used).
     */
    public function defaultStructureFor(string $category, string $vocation): ?IndustrialStructure
    {
        $best = $this->bestStructureFor($category, 'te', $vocation)
            ?? $this->bestStructureFor($category, 'me', $vocation);
        if ($best !== null)
            return $best;

        foreach ($this->structures as $structure) {
            if ($structure->structureCategory() === $vocation)
                return $structure;
        }

        return null;
    }

    public function count(): int
    {
        return count($this->structures);
    }
}
