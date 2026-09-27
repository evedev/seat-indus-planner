<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Http\Controllers;

use EveDev\Seat\IndusPlanner\Models\SavedPlan;
use EveDev\Seat\IndusPlanner\Services\SavedPlanService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Saved production plans. Every plan is private: a user only sees and edits
 * their own plans (404 otherwise).
 */
class SavedPlanController extends Controller
{
    public function index(SavedPlanService $plans)
    {
        $list = $plans->forUser()->map(fn (SavedPlan $plan) => [
            'plan' => $plan,
            'state' => $plans->progressState($plan)['summary'],
        ]);

        return view('indus-planner::plans', ['plans' => $list]);
    }

    public function show(int $plan, SavedPlanService $plans)
    {
        $model = $this->owned($plan, $plans);

        return view('indus-planner::plan', ['plan' => $model]);
    }

    /** Data of a plan page: frozen snapshot and progress. */
    public function data(int $plan, SavedPlanService $plans)
    {
        $model = $this->owned($plan, $plans);
        $response = $this->payload($model, $plans);
        // Jobs found since the previous visit are flagged once.
        $model->timestamps = false;
        $model->update(['last_viewed_at' => now()]);

        return response()->json($response);
    }

    public function store(Request $request, SavedPlanService $plans)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'tool' => 'required|in:reactions,production',
            'params' => 'array',
            'payload' => 'required|array',
            'payload.result' => 'required|array',
            'payload.tree' => 'required|array',
            'entries' => 'required|array|min:1',
            'user_modes' => 'array',
        ]);

        // The full payload is read as is: validated data only keeps the keys
        // that have a rule (result and tree), and would lose prices,
        // blueprints, groups and structures.
        $plan = $plans->create($data['name'], $data['tool'], $data['params'] ?? [], (array) $request->input('payload'),
            (array) $request->input('entries'), $data['user_modes'] ?? []);

        return response()->json([
            'id' => $plan->id,
            'url' => route('indus-planner.plans.show', $plan->id),
            'message' => trans('indus-planner::messages.plan_saved', ['name' => $plan->name]),
        ]);
    }

    public function progress(Request $request, int $plan, SavedPlanService $plans)
    {
        $model = $this->owned($plan, $plans);
        $data = $request->validate([
            'kind' => 'required|in:job,purchase',
            'type_id' => 'required|integer',
            'qty' => 'nullable|integer|min:0',
            'done' => 'nullable|boolean',
            'reset' => 'nullable|boolean',
        ]);

        $input = array_intersect_key($data, array_flip(['qty', 'done', 'reset']));
        $plans->setProgress($model, $data['kind'], (int) $data['type_id'], $input);

        return response()->json($plans->progressState($model->fresh('progress')));
    }

    public function refresh(int $plan, SavedPlanService $plans)
    {
        $model = $plans->refresh($this->owned($plan, $plans));

        return response()->json($this->payload($model->fresh('progress'), $plans));
    }

    public function rename(Request $request, int $plan, SavedPlanService $plans)
    {
        $model = $this->owned($plan, $plans);
        $model->update($request->validate(['name' => 'required|string|max:255']));

        return response()->json(['name' => $model->name]);
    }

    public function destroy(int $plan, SavedPlanService $plans)
    {
        $model = $this->owned($plan, $plans);
        $name = $model->name;
        $model->delete();

        return redirect()->route('indus-planner.plans')->with('success', trans('indus-planner::messages.plan_deleted', ['name' => $name]));
    }

    private function owned(int $id, SavedPlanService $plans): SavedPlan
    {
        return $plans->find($id) ?? throw new NotFoundHttpException();
    }

    private function payload(SavedPlan $plan, SavedPlanService $plans): array
    {
        return [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'tool' => $plan->tool,
                'root_type_id' => $plan->root_type_id,
                'root_name' => $plan->root_name,
                'quantity' => $plan->quantity,
                'created_at' => $plan->created_at?->toIso8601String(),
                'refreshed_at' => $plan->refreshed_at?->toIso8601String(),
            ],
            'payload' => $plan->payload,
            'user_modes' => $plan->user_modes ?? [],
            'jobs' => $plan->jobs,
            'purchases' => $plan->purchases,
            'stock_known' => $plan->stock_known,
            'progress' => $plans->progressState($plan),
        ];
    }
}
