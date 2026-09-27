<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Industrial facility: type, system, security, rigs, and the resulting ME/TE
 * bonuses.
 */
final class IndustrialStructure
{
    /**
     * @param  StructureRig[]  $rigs
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $structureTypeId,
        public int $solarSystemId,
        public string $solarSystemName,
        public string $security,
        public array $rigs = [],
        public float $systemCostIndex = 0.0,
        public float $facilityTaxPct = 3.0,
    ) {
    }

    // -----------------------------------------------------------------
    // Properties derived from the structure type
    // -----------------------------------------------------------------

    private function typeInfo(): array
    {
        return Constants::STRUCTURE_TYPES[$this->structureTypeId] ?? [];
    }

    public function structureTypeName(): string
    {
        return $this->typeInfo()['name'] ?? 'Unknown';
    }

    /** 'manufacturing' or 'reaction' */
    public function structureCategory(): string
    {
        return $this->typeInfo()['category'] ?? 'manufacturing';
    }

    public function roleMe(): float
    {
        return $this->typeInfo()['role_me'] ?? 0.0;
    }

    public function roleTe(): float
    {
        return $this->typeInfo()['role_te'] ?? 0.0;
    }

    public function roleCost(): float
    {
        return $this->typeInfo()['role_cost'] ?? 0.0;
    }

    public function rigSize(): string
    {
        return $this->typeInfo()['rig_size'] ?? 'M';
    }

    public function securityFactor(): float
    {
        return Constants::SECURITY_FACTORS[$this->security] ?? 1.0;
    }

    // -----------------------------------------------------------------
    // Bonus computation
    // -----------------------------------------------------------------

    private function rigSecurityMultiplier(StructureRig $rig): float
    {
        if ($this->security === 'Highsec')
            return 1.0;
        if ($this->security === 'Lowsec')
            return $rig->lowsecMult;

        return $rig->nullMult;
    }

    /**
     * Sums the bonuses of the rigs that apply to a category.
     *
     * @return array{0: float, 1: float} (ME bonus, TE bonus) in %, negative for a reduction
     */
    public function rigBonusesFor(string $category): array
    {
        $me = 0.0;
        $te = 0.0;
        foreach ($this->rigs as $rig) {
            if (in_array($category, Constants::RIG_GROUP_CATEGORIES[$rig->groupId] ?? [], true)) {
                $multiplier = $this->rigSecurityMultiplier($rig);
                $me += $rig->meBonus * $multiplier;
                $te += $rig->teBonus * $multiplier;
            }
        }

        return [$me, $te];
    }

    /** Effective ME multiplier (role x rigs). 0.97 = 3 % reduction. */
    public function effectiveMeMultiplier(string $category): float
    {
        [$rigMe] = $this->rigBonusesFor($category);

        return (1.0 - $this->roleMe() / 100.0) * (1.0 + $rigMe / 100.0);
    }

    public function effectiveTeMultiplier(string $category): float
    {
        [, $rigTe] = $this->rigBonusesFor($category);

        return (1.0 - $this->roleTe() / 100.0) * (1.0 + $rigTe / 100.0);
    }

    public function totalMeReduction(string $category): float
    {
        return (1.0 - $this->effectiveMeMultiplier($category)) * 100.0;
    }

    public function totalTeReduction(string $category): float
    {
        return (1.0 - $this->effectiveTeMultiplier($category)) * 100.0;
    }

    /**
     * Rate applied to the EIV: system cost index + SCC tax + facility tax.
     */
    public function jobCostRate(): float
    {
        return $this->systemCostIndex + Constants::SCC_TAX_RATE + $this->facilityTaxPct / 100.0;
    }
}
