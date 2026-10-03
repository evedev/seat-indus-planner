<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Player structure market used as price source.
 *
 * @property int $id
 * @property int $structure_id
 * @property string $name
 * @property int|null $solar_system_id
 * @property \Illuminate\Support\Carbon|null $refreshed_at
 * @property int $types_count
 * @property string|null $last_error
 */
class Market extends Model
{
    protected $table = 'indus_planner_markets';

    protected $fillable = ['structure_id', 'name', 'solar_system_id', 'refreshed_at', 'types_count', 'last_error', 'created_by'];

    protected $casts = [
        'structure_id' => 'integer',
        'solar_system_id' => 'integer',
        'types_count' => 'integer',
        'refreshed_at' => 'datetime',
    ];

    /** Short label used in the price captions: the system part of the name. */
    public function shortName(): string
    {
        return trim(explode(' - ', $this->name)[0]) ?: $this->name;
    }
}
