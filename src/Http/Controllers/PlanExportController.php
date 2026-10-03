<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Http\Controllers;

use EveDev\Seat\IndusPlanner\Services\PlanExporter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel download of the production plan shown in the Production and
 * Reactions tools.
 */
class PlanExportController extends Controller
{
    public function export(Request $request, PlanExporter $exporter)
    {
        try {
            $book = $exporter->build($request->json()->all());
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => trans('indus-planner::messages.export_invalid')], 422);
        }

        $slug = Str::slug((string) $request->json('title', '')) ?: 'plan';
        $filename = 'indus-planner-' . $slug . '.xlsx';

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }
}
