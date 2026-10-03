{{-- This file is part of seat-indus-planner. | Copyright (C) 2026 EveDev | SPDX-License-Identifier: GPL-2.0-or-later --}}
@extends('web::layouts.grids.12')

@section('title', trans('indus-planner::menu.production'))
@section('page_header', trans('indus-planner::menu.production'))
@section('page_description', trans('indus-planner::ui.production_description'))

@push('head')
  <link rel="stylesheet" href="{{ \EveDev\Seat\IndusPlanner\Http\Controllers\AssetController::url('indus-planner.css') }}">
@endpush

@section('full')
  <div class="card">
    <div class="card-header py-2"><h3 class="card-title">{{ trans('indus-planner::ui.parameters') }}</h3></div>
    <div class="card-body py-2">
      @unless ($hasStructures)
        <div class="alert alert-warning py-2">
          {{ trans('indus-planner::ui.no_structure') }}
          <a href="{{ route('indus-planner.setup') }}">{{ trans('indus-planner::ui.choose_structures') }}</a>.
        </div>
      @endunless
      <div class="indus-param-tiles production">
        <div class="indus-param-tile" style="--tile: #888780">
          <div class="indus-param-title">{{ trans('indus-planner::ui.character') }}</div>
          <select id="p-character" class="form-control form-control-sm">
            @foreach ($characters as $id => $name)
              <option value="{{ $id }}" @selected($id === $bestCharacter)>{{ $name }}</option>
            @endforeach
          </select>
        </div>
        <div class="indus-param-tile" style="--tile: #378add">
          <div class="indus-param-title">{{ trans('indus-planner::ui.item_to_produce') }}</div>
          <div class="indus-param-kv">
            <label for="p-item">{{ trans('indus-planner::ui.item') }}</label>
            <div class="position-relative">
              <input type="search" id="p-item" class="form-control form-control-sm" autocomplete="off"
                     placeholder="{{ trans('indus-planner::ui.search_placeholder') }}">
              <div id="p-item-suggestions" class="list-group indus-suggestions"></div>
            </div>
            <label for="p-qty" title="{{ trans('indus-planner::ui.final_quantity_help') }}">{{ trans('indus-planner::ui.final_quantity') }}</label>
            <input type="number" id="p-qty" class="form-control form-control-sm indus-param-number" min="1" max="99999999" value="1">
          </div>
        </div>
        <div class="indus-param-tile" style="--tile: #7f77dd">
          <div class="indus-param-title">{{ trans('indus-planner::ui.tile_blueprint') }}</div>
          <div class="indus-param-bp">
            <label for="p-me" title="{{ trans('indus-planner::ui.bp_me_help') }}">ME</label>
            <input type="number" id="p-me" class="form-control form-control-sm" min="0" max="10" value="0">
            <div id="p-blueprint" class="small text-muted"></div>
            <label for="p-te" title="{{ trans('indus-planner::ui.bp_te_help') }}">TE</label>
            <input type="number" id="p-te" class="form-control form-control-sm" min="0" max="20" step="2" value="0">
            <div></div>
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
        <div class="indus-param-tile" style="--tile: #ba7517">
          <div class="indus-param-title">{{ trans('indus-planner::ui.tile_others') }}</div>
          <div class="custom-control custom-checkbox">
            <input type="checkbox" class="custom-control-input" id="p-reactions" checked>
            <label class="custom-control-label small" for="p-reactions"
                   title="{{ trans('indus-planner::ui.include_reactions_help') }}">{{ trans('indus-planner::ui.include_reactions') }}</label>
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
    IndusPlanner.production({
      computeUrl: @json(route('indus-planner.api.production.compute')),
      searchUrl: @json(route('indus-planner.api.production.search')),
      blueprintUrl: @json(route('indus-planner.api.production.blueprint')),
      planUrl: @json(route('indus-planner.api.plan')),
      exportUrl: @json(route('indus-planner.api.plan.export')),
      saveUrl: @json(route('indus-planner.plans.store')),
      csrf: @json(csrf_token()),
      storageKey: @json('indus-planner:v2:' . auth()->id() . ':production'),
    });
  </script>
@endpush
