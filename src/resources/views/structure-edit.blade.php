{{-- This file is part of seat-indus-planner. | Copyright (C) 2026 EveDev | SPDX-License-Identifier: GPL-2.0-or-later --}}
@extends('web::layouts.grids.12')

@php
  $isManual = $structure->source === 'manual';
  $title = $structure->exists ? trans('indus-planner::ui.edit_structure') : trans('indus-planner::ui.add_structure');
@endphp

@section('title', $title)
@section('page_header', $title)

@push('head')
  <link rel="stylesheet" href="{{ \EveDev\Seat\IndusPlanner\Http\Controllers\AssetController::url('indus-planner.css') }}">
@endpush

@section('full')
  <div class="row">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">{{ $structure->exists ? $structure->name : trans('indus-planner::ui.new_structure') }}</h3>
          <div class="card-tools">
            <a href="{{ route('indus-planner.setup') }}" class="btn btn-sm btn-default"><i class="fas fa-arrow-left"></i> {{ trans('indus-planner::ui.back') }}</a>
          </div>
        </div>

        <form id="structure-form" method="post"
              action="{{ $structure->exists ? route('indus-planner.structures.update', $structure) : route('indus-planner.structures.store') }}">
          @csrf
          @if ($structure->exists) @method('PUT') @endif

          <div class="card-body">
            @unless ($isManual)
              <div class="alert alert-info">{{ trans('indus-planner::ui.imported_structure_help') }}</div>
            @endunless

            <div class="form-group row">
              <label class="col-sm-3 col-form-label">{{ trans('indus-planner::ui.name') }}</label>
              <div class="col-sm-9">
                <input type="text" name="name" class="form-control" required maxlength="255"
                       placeholder="{{ trans('indus-planner::ui.structure_name_placeholder') }}"
                       value="{{ old('name', $structure->name) }}" @disabled(! $isManual)>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label">{{ trans('indus-planner::ui.type') }}</label>
              <div class="col-sm-9">
                <select name="structure_type_id" id="structure-type" class="form-control" @disabled(! $isManual)>
                  @foreach (\EveDev\Seat\IndusPlanner\Domain\Constants::STRUCTURE_TYPES as $typeId => $info)
                    <option value="{{ $typeId }}" @selected(old('structure_type_id', $structure->structure_type_id) == $typeId)>
                      {{ $info['name'] }} ({{ $info['category'] === 'manufacturing' ? trans('indus-planner::ui.activity_manufacturing') : trans('indus-planner::ui.activity_reaction') }})
                    </option>
                  @endforeach
                </select>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label">{{ trans('indus-planner::ui.system') }}</label>
              <div class="col-sm-9">
                <div class="input-group">
                  <input type="text" id="system-name" class="form-control" autocomplete="off"
                         placeholder="{{ trans('indus-planner::ui.system_placeholder') }}" value="{{ old('system_name', $system['name'] ?? '') }}"
                         name="system_name" @disabled(! $isManual)>
                  <div class="input-group-append">
                    <span class="input-group-text" id="security-badge" style="min-width:150px"></span>
                  </div>
                </div>
                <input type="hidden" name="solar_system_id" id="system-id" value="{{ old('solar_system_id', $structure->solar_system_id) }}">
                <div id="system-suggestions" class="list-group indus-suggestions"></div>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label">{{ trans('indus-planner::ui.security') }}</label>
              <div class="col-sm-9">
                <select id="security" class="form-control" disabled>
                  @foreach (\EveDev\Seat\IndusPlanner\Domain\Constants::SECURITY_CLASSES as $class)
                    <option value="{{ $class }}" @selected(($system['security_class'] ?? 'Null / Wormhole') === $class)>{{ $class }}</option>
                  @endforeach
                </select>
                <small class="text-muted">{{ trans('indus-planner::ui.security_from_system') }}</small>
              </div>
            </div>

            <div class="form-group row">
              <label class="col-sm-3 col-form-label">{{ trans('indus-planner::ui.facility_tax') }}</label>
              <div class="col-sm-4">
                <div class="input-group">
                  <input type="number" name="facility_tax_pct" class="form-control" step="0.01" min="0" max="100"
                         value="{{ old('facility_tax_pct', $structure->facility_tax_pct) }}">
                  <div class="input-group-append"><span class="input-group-text">%</span></div>
                </div>
              </div>
              <div class="col-sm-5"><small class="text-muted">{{ trans('indus-planner::ui.facility_tax_help') }}</small></div>
            </div>

            <fieldset class="border rounded p-2 mt-3">
              <legend class="w-auto px-2 h6">{{ trans('indus-planner::ui.fitted_rigs') }}</legend>

              @unless ($isManual)
                <div class="custom-control custom-checkbox mb-2">
                  <input type="checkbox" class="custom-control-input" id="use-detected" name="use_detected_rigs" value="1"
                         @checked(! $structure->rigs_manual)>
                  <label class="custom-control-label" for="use-detected">
                    {{ trans('indus-planner::ui.use_detected_rigs') }}
                    @unless ($structure->rigs_known)<span class="badge badge-warning">{{ trans('indus-planner::ui.not_visible') }}</span>@endunless
                  </label>
                </div>
              @endunless

              @for ($i = 0; $i < 3; $i++)
                <div class="form-group row mb-2">
                  <label class="col-sm-3 col-form-label">Rig {{ $i + 1 }}</label>
                  <div class="col-sm-9">
                    <select name="rigs[]" class="form-control rig-select" data-current="{{ $currentRigs[$i] ?? '' }}"></select>
                  </div>
                </div>
              @endfor
            </fieldset>
          </div>

          <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> {{ trans('indus-planner::ui.save') }}</button>
            <a href="{{ route('indus-planner.setup') }}" class="btn btn-default">{{ trans('indus-planner::ui.cancel') }}</a>
          </div>
        </form>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><h3 class="card-title">{{ trans('indus-planner::ui.bonus_preview') }}</h3></div>
        <div class="card-body p-0">
          <table class="table table-sm table-striped mb-0">
            <thead><tr><th>{{ trans('indus-planner::ui.category') }}</th><th class="text-center">ME</th><th class="text-center">TE</th></tr></thead>
            <tbody id="bonus-preview"><tr><td colspan="3" class="text-muted text-center">—</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
@stop

@push('javascript')
  <script>
    window.IndusStructureEditor = {
      rigsByType: @json($rigsByType),
      systemsUrl: @json(route('indus-planner.api.systems')),
      previewUrl: @json(route('indus-planner.api.preview')),
      csrf: @json(csrf_token()),
      securityClass: @json($system['security_class'] ?? null),
      securityStatus: @json($system['security_status'] ?? null),
      texts: @json(trans('indus-planner::js.structure_editor')),
    };
  </script>
  <script src="{{ \EveDev\Seat\IndusPlanner\Http\Controllers\AssetController::url('structure-editor.js') }}"></script>
@endpush
