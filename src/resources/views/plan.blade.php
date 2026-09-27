{{-- This file is part of seat-indus-planner. | Copyright (C) 2026 EveDev | SPDX-License-Identifier: GPL-2.0-or-later --}}
@extends('web::layouts.grids.12')

@section('title', $plan->name)
@section('page_header', $plan->name)
@section('page_description', trans('indus-planner::ui.saved_plan'))

@push('head')
  <link rel="stylesheet" href="{{ \EveDev\Seat\IndusPlanner\Http\Controllers\AssetController::url('indus-planner.css') }}">
@endpush

@section('full')
  <div class="card">
    <div class="card-body py-2">
      <div class="d-flex flex-wrap align-items-center" style="gap: 12px">
        <img src="https://images.evetech.net/types/{{ $plan->root_type_id }}/icon?size=64" width="48" height="48" alt="">
        <div class="flex-grow-1">
          <div class="input-group input-group-sm" style="max-width: 420px">
            <input type="text" id="plan-name" class="form-control font-weight-bold" value="{{ $plan->name }}" maxlength="255">
            <div class="input-group-append">
              <button type="button" id="plan-rename" class="btn btn-default" title="{{ trans('indus-planner::ui.rename') }}"><i class="fas fa-pen"></i></button>
            </div>
          </div>
          <div class="text-muted small mt-1">
            {{ number_format($plan->quantity, 0, trans('indus-planner::ui.decimal_point'), trans('indus-planner::ui.thousands_sep')) }} {{ $plan->root_name }}
            · {{ $plan->tool === 'reactions' ? trans('indus-planner::ui.activity_reaction') : trans('indus-planner::ui.production') }}
            · {{ trans('indus-planner::ui.created_on', ['date' => $plan->created_at->format(trans('indus-planner::ui.datetime_format'))]) }}
            · {{ trans('indus-planner::ui.stock_prices_of') }} <span id="plan-refreshed">{{ optional($plan->refreshed_at)->format(trans('indus-planner::ui.datetime_format')) }}</span>
          </div>
        </div>
        <div style="min-width: 280px" class="flex-grow-1">
          <div class="indus-progress"><div id="plan-progress-bar" style="width: 0"></div></div>
          <div class="small text-muted mt-1" id="plan-progress-text">—</div>
        </div>
        <div class="text-nowrap">
          <button type="button" id="plan-refresh" class="btn btn-sm btn-default" title="{{ trans('indus-planner::ui.refresh_help') }}">
            <i class="fas fa-sync"></i> {{ trans('indus-planner::ui.refresh') }}
          </button>
          <a href="{{ route('indus-planner.plans') }}" class="btn btn-sm btn-default"><i class="fas fa-list"></i> {{ trans('indus-planner::menu.plans') }}</a>
          <form action="{{ route('indus-planner.plans.destroy', $plan->id) }}" method="post" class="d-inline"
                onsubmit="return confirm(@js(trans('indus-planner::ui.delete_plan_confirm')));">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-sm btn-default" title="{{ trans('indus-planner::ui.delete') }}"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
    </div>
  </div>

  @include('indus-planner::partials.tool', ['readOnly' => true])
@stop

@push('javascript')
  @include('indus-planner::partials.script')
  <script>
    IndusPlanner.savedPlan({
      dataUrl: @json(route('indus-planner.plans.data', $plan->id)),
      progressUrl: @json(route('indus-planner.plans.progress', $plan->id)),
      refreshUrl: @json(route('indus-planner.plans.refresh', $plan->id)),
      renameUrl: @json(route('indus-planner.plans.rename', $plan->id)),
      tool: @json($plan->tool),
      csrf: @json(csrf_token()),
    });
  </script>
@endpush
