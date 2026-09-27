<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Asset holder selected by a user.
 *
 * @property int $user_id
 * @property string $owner_type character|corporation
 * @property int $owner_id
 * @property int|null $division 1 to 7 for a corporation, null for a character
 */
class AssetSource extends Model
{
    protected $table = 'indus_planner_asset_sources';

    public $timestamps = false;

    protected $fillable = ['user_id', 'owner_type', 'owner_id', 'division'];
}
