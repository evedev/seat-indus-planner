<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Domain tests need neither Laravel nor the database: a plain PSR-4 loader
// is enough. Run them in the container:
//   docker compose exec front vendor/bin/phpunit -c packages/evedev/seat-indus-planner/phpunit.xml
spl_autoload_register(function (string $class) {
    $prefixes = [
        'EveDev\\Seat\\IndusPlanner\\Tests\\' => __DIR__ . '/',
        'EveDev\\Seat\\IndusPlanner\\' => __DIR__ . '/../src/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file))
                require $file;

            return;
        }
    }
});

$vendor = getcwd() . '/vendor/autoload.php';
if (is_file($vendor))
    require_once $vendor;
