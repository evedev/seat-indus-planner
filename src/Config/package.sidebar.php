<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// No 'permission' key: the menu is visible to every account.
return [
    'indus-planner' => [
        'name' => 'indus-planner',
        'label' => 'indus-planner::menu.industry',
        'icon' => 'fas fa-industry',
        'route_segment' => 'indus-planner',
        'entries' => [
            [
                'name' => 'indus-planner-setup',
                'label' => 'indus-planner::menu.setup',
                'icon' => 'fas fa-cogs',
                'route' => 'indus-planner.setup',
            ],
            [
                'name' => 'indus-planner-reactions',
                'label' => 'indus-planner::menu.reactions',
                'icon' => 'fas fa-flask',
                'route' => 'indus-planner.reactions',
            ],
            [
                'name' => 'indus-planner-production',
                'label' => 'indus-planner::menu.production',
                'icon' => 'fas fa-hammer',
                'route' => 'indus-planner.production',
            ],
            [
                'name' => 'indus-planner-plans',
                'label' => 'indus-planner::menu.plans',
                'icon' => 'fas fa-clipboard-list',
                'route' => 'indus-planner.plans',
            ],
        ],
    ],
];
