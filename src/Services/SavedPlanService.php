<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use EveDev\Seat\IndusPlanner\Models\SavedPlan;
use EveDev\Seat\IndusPlanner\Models\SavedPlanProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saved production plans: creation (frozen), manual and automatic progress,
 * stock and price refresh.
 *
 * Automatic progress: industry jobs synchronised by SeAT whose product is a
 * plan line, started after the plan creation by one of the user's characters
 * (personal or corporation jobs), move the run counter forward. A manual
 * entry always wins.
 */
class SavedPlanService
{
    // ESI job statuses that produce nothing.
    private const IGNORED_STATUSES = ['cancelled', 'reverted'];

    public function __construct(private UserContext $context, private Planner $planner)
    {
    }

    /** @return Collection<int, SavedPlan> */
    public function forUser(): Collection
    {
        return SavedPlan::with('progress')->where('user_id', $this->context->user->id)->orderByDesc('updated_at')->get();
    }

    public function find(int $id): ?SavedPlan
    {
        return SavedPlan::with('progress')->where('user_id', $this->context->user->id)->find($id);
    }

    /**
     * Saves a plan from the latest simulation of the tool. Jobs and purchases
     * are recomputed server side from the tree nodes, with the current stock
     * and prices.
     */
    public function create(string $name, string $tool, array $params, array $payload, array $entries, array $userModes): SavedPlan
    {
        $plan = $this->planner->plan($entries);
        $result = $payload['result'] ?? [];

        return SavedPlan::create([
            'user_id' => $this->context->user->id,
            'name' => $name,
            'tool' => $tool,
            'root_type_id' => (int) ($result['output_type_id'] ?? 0),
            'root_name' => (string) ($result['output_name'] ?? ''),
            'quantity' => (int) ($payload['tree']['output']['qty_total'] ?? 0),
            'params' => $params,
            'payload' => $payload,
            'entries' => $entries,
            'jobs' => $plan['jobs'],
            'purchases' => $plan['purchases'],
            'stock_known' => $plan['stock_known'],
            'user_modes' => $userModes,
            'refreshed_at' => now(),
            'last_viewed_at' => now(),
        ]);
    }

    /**
     * Reloads stock and prices for the plan purchases, without touching the
     * job list or the quantities.
     */
    public function refresh(SavedPlan $plan): SavedPlan
    {
        $result = $this->planner->plan($plan->entries);
        $fresh = collect($result['purchases'])->keyBy('type_id');

        // Targets (quantity to buy when the plan was saved) stay frozen; only
        // the price and the current stock (`stock_now`) change.
        $purchases = array_map(function ($line) use ($fresh, $result) {
            $new = $fresh->get($line['type_id']);
            if ($new) {
                $line['unit_price'] = $new['unit_price'];
                $line['total_price'] = $new['unit_price'] * $line['quantity'];
                $line['stock_now'] = $result['stock_known'] ? $new['in_stock'] : null;
                $line['stock_tooltip'] = $new['stock_tooltip'];
            }

            return $line;
        }, $plan->purchases);

        $plan->update(['purchases' => $purchases, 'refreshed_at' => now()]);

        return $plan;
    }

    // -----------------------------------------------------------------
    // Progress
    // -----------------------------------------------------------------

    /**
     * Saves a manual entry for a plan line.
     *
     * @param  array{qty?:int|null, done?:bool|null, reset?:bool}  $input
     */
    public function setProgress(SavedPlan $plan, string $kind, int $typeId, array $input): void
    {
        $progress = SavedPlanProgress::firstOrNew(['plan_id' => $plan->id, 'kind' => $kind, 'type_id' => $typeId]);

        if (! empty($input['reset'])) {
            $progress->exists && $progress->delete();
            $plan->touch();

            return;
        }
        if (array_key_exists('qty', $input))
            $progress->qty_manual = $input['qty'] === null ? null : max(0, (int) $input['qty']);
        if (array_key_exists('done', $input))
            $progress->done_manual = $input['done'] === null ? null : (bool) $input['done'];

        $progress->save();
        $plan->touch();
    }

    /**
     * Jobs found in SeAT for the items the plan produces.
     *
     * @return array<int, array{runs:int, jobs:int, active:int, new:int}> by item type
     */
    public function autoProgress(SavedPlan $plan): array
    {
        $types = array_values(array_unique(array_map(fn ($j) => (int) $j['type_id'], $plan->jobs)));
        $characters = array_keys($this->context->characters());
        if (empty($types) || empty($characters))
            return [];

        $since = $plan->created_at;
        $columns = ['job_id', 'product_type_id', 'runs', 'status', 'start_date'];
        $query = fn (string $table) => DB::table($table)
            ->whereIn('installer_id', $characters)
            ->whereIn('product_type_id', $types)
            ->where('start_date', '>=', $since)
            ->whereNotIn('status', self::IGNORED_STATUSES)
            ->get($columns);

        // The same job may appear in personal and corporation jobs: dedupe.
        $jobs = $query('character_industry_jobs')->concat($query('corporation_industry_jobs'))->unique('job_id');

        $lastViewed = $plan->last_viewed_at ?? $plan->created_at;
        $out = [];
        foreach ($jobs as $job) {
            $typeId = (int) $job->product_type_id;
            $out[$typeId] ??= ['runs' => 0, 'jobs' => 0, 'active' => 0, 'new' => 0];
            $out[$typeId]['runs'] += (int) $job->runs;
            $out[$typeId]['jobs']++;
            if (in_array($job->status, ['active', 'paused'], true))
                $out[$typeId]['active']++;
            if ($job->start_date > $lastViewed)
                $out[$typeId]['new']++;
        }

        return $out;
    }

    /**
     * Progress state of each line and of the whole plan.
     *
     * @return array{jobs: array<int, array>, purchases: array<int, array>, summary: array}
     */
    public function progressState(SavedPlan $plan): array
    {
        $manual = [];
        foreach ($plan->progress as $row) {
            $manual[$row->kind][(int) $row->type_id] = $row;
        }
        $auto = $this->autoProgress($plan);

        $jobs = [];
        foreach ($plan->jobs as $job) {
            $typeId = (int) $job['type_id'];
            $row = $manual['job'][$typeId] ?? null;
            $detected = $auto[$typeId] ?? null;
            $target = (int) $job['runs'];
            $qty = $row?->qty_manual ?? ($detected['runs'] ?? 0);
            $jobs[$typeId] = [
                'target' => $target,
                'qty' => (int) $qty,
                'done' => $row?->done_manual ?? ($qty >= $target),
                'manual' => $row !== null && ($row->qty_manual !== null || $row->done_manual !== null),
                'auto' => $detected,
            ];
        }

        $purchases = [];
        foreach ($plan->purchases as $line) {
            $typeId = (int) $line['type_id'];
            $row = $manual['purchase'][$typeId] ?? null;
            // To buy when the plan was saved (stock deducted when known).
            $target = (int) ($plan->stock_known ? $line['to_buy'] : $line['quantity']);
            $qty = (int) ($row?->qty_manual ?? 0);
            $purchases[$typeId] = [
                'target' => $target,
                'qty' => $qty,
                'done' => $row?->done_manual ?? ($qty >= $target),
                'manual' => $row !== null && ($row->qty_manual !== null || $row->done_manual !== null),
            ];
        }

        $jobsDone = count(array_filter($jobs, fn ($j) => $j['done']));
        $purchasesDone = count(array_filter($purchases, fn ($p) => $p['done']));
        $total = count($jobs) + count($purchases);

        return [
            'jobs' => $jobs,
            'purchases' => $purchases,
            'summary' => [
                'jobs_done' => $jobsDone,
                'jobs_total' => count($jobs),
                'purchases_done' => $purchasesDone,
                'purchases_total' => count($purchases),
                'ratio' => $total ? ($jobsDone + $purchasesDone) / $total : 1,
                'finished' => $total === 0 || ($jobsDone + $purchasesDone) === $total,
                'new_jobs' => array_sum(array_map(fn ($a) => $a['new'], $auto)),
                'active_jobs' => array_sum(array_map(fn ($a) => $a['active'], $auto)),
            ],
        ];
    }
}
