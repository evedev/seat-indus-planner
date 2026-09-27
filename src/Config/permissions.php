<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Scope 'indus-planner'. Reactions and Production are open to every account:
// only structure management requires a permission. A SeAT administrator
// always has access.
return [
    'manage' => [
        'label' => 'indus-planner::permissions.manage_label',
        'description' => 'indus-planner::permissions.manage_description',
    ],
];
