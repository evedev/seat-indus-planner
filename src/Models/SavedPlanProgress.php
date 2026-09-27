<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Progress entered by hand for a saved plan line.
 *
 * @property int $plan_id
 * @property string $kind job|purchase
 * @property int $type_id
 * @property int|null $qty_manual runs started or quantity bought
 * @property bool|null $done_manual forced "Done" checkbox
 */
class SavedPlanProgress extends Model
{
    protected $table = 'indus_planner_saved_plan_progress';

    protected $fillable = ['plan_id', 'kind', 'type_id', 'qty_manual', 'done_manual'];

    protected $casts = [
        'done_manual' => 'boolean',
    ];
}
