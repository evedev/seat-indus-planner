<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

return [

    // Contact sent in the User-Agent of public ESI calls (system cost indices).
    'contact' => env('INDUS_PLANNER_CONTACT', 'unknown'),

    // Reference system for market prices: Jita.
    'price_system_id' => (int) env('INDUS_PLANNER_PRICE_SYSTEM', 30000142),

    // Default structure facility tax (%): ESI does not expose it.
    'default_facility_tax' => 3.0,

    // Cache lifetime of the catalog built from the SDE (seconds).
    'catalog_ttl' => 86400,

];
