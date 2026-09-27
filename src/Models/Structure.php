<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Models;

use EveDev\Seat\IndusPlanner\Domain\Constants;
use Illuminate\Database\Eloquent\Model;

/**
 * Industrial structure known to the plugin.
 *
 * @property int $id
 * @property string $source esi|manual
 * @property int|null $structure_id
 * @property int|null $corporation_id
 * @property string $name
 * @property int $structure_type_id
 * @property int $solar_system_id
 * @property float $facility_tax_pct
 * @property bool $rigs_manual
 * @property bool $rigs_known
 */
class Structure extends Model
{
    protected $table = 'indus_planner_structures';

    protected $fillable = [
        'source', 'structure_id', 'corporation_id', 'name', 'structure_type_id', 'solar_system_id',
        'facility_tax_pct', 'rigs_manual', 'rigs_known', 'created_by',
    ];

    protected $casts = [
        'facility_tax_pct' => 'float',
        'rigs_manual' => 'boolean',
        'rigs_known' => 'boolean',
    ];

    public function rigs()
    {
        return $this->hasMany(StructureRig::class, 'structure_id');
    }

    public function typeName(): string
    {
        return Constants::STRUCTURE_TYPES[$this->structure_type_id]['name'] ?? 'Unknown';
    }

    public function vocation(): string
    {
        return Constants::STRUCTURE_TYPES[$this->structure_type_id]['category'] ?? 'manufacturing';
    }
}
