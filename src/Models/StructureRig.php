<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Models;

use Illuminate\Database\Eloquent\Model;

class StructureRig extends Model
{
    protected $table = 'indus_planner_structure_rigs';

    public $timestamps = false;

    protected $fillable = ['structure_id', 'rig_type_id'];
}
