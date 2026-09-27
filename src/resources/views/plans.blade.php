{{-- This file is part of seat-indus-planner. | Copyright (C) 2026 EveDev | SPDX-License-Identifier: GPL-2.0-or-later --}}
@extends('web::layouts.grids.12')

@section('title', trans('indus-planner::menu.plans'))
@section('page_header', trans('indus-planner::menu.plans'))
@section('page_description', trans('indus-planner::ui.plans_description'))

@push('head')
  <link rel="stylesheet" href="{{ \EveDev\Seat\IndusPlanner\Http\Controllers\AssetController::url('indus-planner.css') }}">
@endpush

@section('full')
  <div class="card">
    <div class="card-body">
      @if ($plans->isEmpty())
        <p class="text-muted mb-0">
          {!! trans('indus-planner::ui.no_plans', [
              'production' => '<a href="' . e(route('indus-planner.production')) . '">' . e(trans('indus-planner::menu.production')) . '</a>',
              'reactions' => '<a href="' . e(route('indus-planner.reactions')) . '">' . e(trans('indus-planner::menu.reactions')) . '</a>',
              'save' => e(trans('indus-planner::ui.save_plan')),
          ]) !!}
        </p>
      @else
        @foreach ($plans as ['plan' => $plan, 'state' => $state])
          <div class="indus-plan-row">
            <img src="https://images.evetech.net/types/{{ $plan->root_type_id }}/icon?size=64" width="40" height="40" alt="" loading="lazy">
            <div class="flex-grow-1 min-w-0">
              <div>
                <a href="{{ route('indus-planner.plans.show', $plan->id) }}" class="font-weight-bold">{{ $plan->name }}</a>
                <span class="text-muted small">
                  · {{ number_format($plan->quantity, 0, trans('indus-planner::ui.decimal_point'), trans('indus-planner::ui.thousands_sep')) }} {{ $plan->root_name }}
                  · {{ $plan->tool === 'reactions' ? trans('indus-planner::ui.activity_reaction') : trans('indus-planner::ui.production') }}
                  · {{ trans('indus-planner::ui.created_ago', ['ago' => $plan->created_at->diffForHumans()]) }}
                </span>
              </div>
              <div class="d-flex align-items-center mt-1">
                <div class="indus-progress flex-grow-1 mr-2">
                  <div class="{{ $state['finished'] ? 'done' : '' }}" style="width: {{ round($state['ratio'] * 100) }}%"></div>
                </div>
                <span class="text-muted small text-nowrap">
                  @if ($state['finished'])
                    {{ trans('indus-planner::ui.finished') }}
                  @else
                    {{ trans('indus-planner::ui.progress_summary', [
                        'jobs_done' => $state['jobs_done'], 'jobs_total' => $state['jobs_total'],
                        'purchases_done' => $state['purchases_done'], 'purchases_total' => $state['purchases_total'],
                    ]) }}
                  @endif
                </span>
              </div>
            </div>
            @if ($state['finished'])
              <span class="badge badge-success">{{ trans('indus-planner::ui.finished') }}</span>
            @elseif ($state['new_jobs'] > 0)
              <span class="badge badge-info" title="{{ trans('indus-planner::ui.new_jobs_help') }}">
                {{ trans('indus-planner::ui.new_jobs', ['count' => $state['new_jobs']]) }}
              </span>
            @endif
            <a href="{{ route('indus-planner.plans.show', $plan->id) }}" class="btn btn-sm btn-default">{{ trans('indus-planner::ui.open') }}</a>
            <form action="{{ route('indus-planner.plans.destroy', $plan->id) }}" method="post" class="d-inline"
                  onsubmit="return confirm(@js(trans('indus-planner::ui.delete_named_plan_confirm', ['name' => $plan->name])));">
              @csrf
              @method('DELETE')
              <button type="submit" class="btn btn-sm btn-default" title="{{ trans('indus-planner::ui.delete') }}"><i class="fas fa-trash"></i></button>
            </form>
          </div>
        @endforeach
      @endif
    </div>
  </div>
@stop
