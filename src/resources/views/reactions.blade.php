{{-- This file is part of seat-indus-planner. | Copyright (C) 2026 EveDev | SPDX-License-Identifier: GPL-2.0-or-later --}}
@extends('web::layouts.grids.12')

@section('title', trans('indus-planner::menu.reactions'))
@section('page_header', trans('indus-planner::menu.reactions'))
@section('page_description', trans('indus-planner::ui.reactions_description'))

@push('head')
  <link rel="stylesheet" href="{{ \EveDev\Seat\IndusPlanner\Http\Controllers\AssetController::url('indus-planner.css') }}">
@endpush

@section('full')
  <div class="card">
    <div class="card-header py-2"><h3 class="card-title">{{ trans('indus-planner::ui.parameters') }}</h3></div>
    <div class="card-body py-2">
      @if ($structures === [])
        <div class="alert alert-warning py-2">
          {{ trans('indus-planner::ui.no_reaction_structure') }}
          <a href="{{ route('indus-planner.setup') }}">{{ trans('indus-planner::ui.choose_structures') }}</a>.
        </div>
      @endif
      <div class="indus-param-tiles reactions">
        <div class="indus-param-tile" style="--tile: #888780">
          <div class="indus-param-title">{{ trans('indus-planner::ui.character') }}</div>
          <select id="p-character" class="form-control form-control-sm">
            @foreach ($characters as $id => $name)
              <option value="{{ $id }}" @selected($id === $bestCharacter)>{{ $name }}</option>
            @endforeach
          </select>
        </div>
        <div class="indus-param-tile" style="--tile: #378add">
          <div class="indus-param-title">{{ trans('indus-planner::ui.reaction') }}</div>
          <div class="indus-param-reaction">
            <label for="p-structure">{{ trans('indus-planner::ui.structure') }}</label>
            <select id="p-structure" class="form-control form-control-sm">
              @forelse ($structures as $structure)
                <option value="{{ $structure->id }}" @selected($structure->id === $bestStructure)>{{ $structure->name }}</option>
              @empty
                <option value="">— {{ trans('indus-planner::ui.none') }} —</option>
              @endforelse
            </select>
            <label for="p-type">{{ trans('indus-planner::ui.type') }}</label>
            <select id="p-type" class="form-control form-control-sm">
              @foreach ($types as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
              @endforeach
            </select>
            <div class="custom-control custom-radio">
              <input type="radio" id="p-mode-qty" name="p-mode" value="qty" class="custom-control-input" checked>
              <label class="custom-control-label small" for="p-mode-qty" title="{{ trans('indus-planner::ui.final_quantity_help') }}">{{ trans('indus-planner::ui.final_quantity') }}</label>
            </div>
            <input type="number" id="p-qty" class="form-control form-control-sm" min="1" max="99999999" value="1">

            <span></span>
            <span></span>
            <label for="p-reaction">{{ trans('indus-planner::ui.reaction') }}</label>
            <select id="p-reaction" class="form-control form-control-sm">
              @foreach ($reactions as [$id, $name])
                <option value="{{ $id }}">{{ $name }}</option>
              @endforeach
            </select>
            <div class="custom-control custom-radio">
              <input type="radio" id="p-mode-runs" name="p-mode" value="runs" class="custom-control-input">
              <label class="custom-control-label small" for="p-mode-runs">{{ trans('indus-planner::ui.runs') }}</label>
            </div>
            <input type="number" id="p-runs" class="form-control form-control-sm" min="1" max="99999999" value="1" disabled>
          </div>
        </div>
        <div class="indus-param-tile" style="--tile: #1d9e75">
          <div class="indus-param-title">{{ trans('indus-planner::ui.tile_market') }}</div>
          <select id="p-market" class="form-control form-control-sm" title="{{ trans('indus-planner::ui.price_market_help') }}">
            @foreach ($markets as $option)
              <option value="{{ $option['value'] }}" @selected($option['value'] === $market) @disabled($option['disabled'])>{{ $option['label'] }}</option>
            @endforeach
          </select>
          <div class="custom-control custom-checkbox mt-1">
            <input type="checkbox" class="custom-control-input" id="p-optimize">
            <label class="custom-control-label small" for="p-optimize"
                   title="{{ trans('indus-planner::ui.optimize_margin_help') }}">{{ trans('indus-planner::ui.optimize_margin') }}</label>
          </div>
        </div>
      </div>
    </div>
  </div>

  @include('indus-planner::partials.tool')
@stop

@push('javascript')
  @include('indus-planner::partials.script')
  <script>
    IndusPlanner.reactions({
      computeUrl: @json(route('indus-planner.api.reactions.compute')),
      listUrl: @json(route('indus-planner.api.reactions.list')),
      planUrl: @json(route('indus-planner.api.plan')),
      exportUrl: @json(route('indus-planner.api.plan.export')),
      saveUrl: @json(route('indus-planner.plans.store')),
      csrf: @json(csrf_token()),
      storageKey: @json('indus-planner:v2:' . auth()->id() . ':reactions'),
    });
  </script>
@endpush
