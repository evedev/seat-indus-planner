<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Http\Controllers;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the plugin JS/CSS files straight from the package (no publishing
 * into public/ needed).
 */
class AssetController extends Controller
{
    private const TYPES = ['js' => 'application/javascript', 'css' => 'text/css'];

    /**
     * URL of a static file, versioned by its modification time: a plugin
     * update invalidates the browser cache.
     */
    public static function url(string $file): string
    {
        $path = __DIR__ . '/../../resources/assets/' . $file;

        return route('indus-planner.asset', $file) . '?v=' . (is_file($path) ? filemtime($path) : 0);
    }

    public function show(string $file)
    {
        $path = realpath(__DIR__ . '/../../resources/assets/' . $file);
        $root = realpath(__DIR__ . '/../../resources/assets');
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        if (! $path || ! str_starts_with($path, $root) || ! isset(self::TYPES[$extension]))
            throw new NotFoundHttpException();

        return response()->file($path, [
            'Content-Type' => self::TYPES[$extension] . '; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
