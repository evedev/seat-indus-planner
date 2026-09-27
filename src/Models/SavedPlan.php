<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Production plan saved (frozen) by a user.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $tool reactions|production
 * @property int $root_type_id
 * @property string $root_name
 * @property int $quantity
 * @property array $params
 * @property array $payload
 * @property array $entries
 * @property array $jobs
 * @property array $purchases
 * @property bool $stock_known
 * @property array|null $user_modes
 */
class SavedPlan extends Model
{
    protected $table = 'indus_planner_saved_plans';

    protected $fillable = [
        'user_id', 'name', 'tool', 'root_type_id', 'root_name', 'quantity', 'params', 'payload',
        'entries', 'jobs', 'purchases', 'stock_known', 'user_modes', 'refreshed_at', 'last_viewed_at',
    ];

    protected $casts = [
        'params' => 'array',
        'payload' => 'array',
        'entries' => 'array',
        'jobs' => 'array',
        'purchases' => 'array',
        'user_modes' => 'array',
        'stock_known' => 'boolean',
        'refreshed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
    ];

    public function progress()
    {
        return $this->hasMany(SavedPlanProgress::class, 'plan_id');
    }
}
