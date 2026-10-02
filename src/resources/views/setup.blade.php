{{-- This file is part of seat-indus-planner. | Copyright (C) 2026 EveDev | SPDX-License-Identifier: GPL-2.0-or-later --}}
@extends('web::layouts.grids.12')

@section('title', trans('indus-planner::menu.setup'))
@section('page_header', trans('indus-planner::menu.setup'))
@section('page_description', trans('indus-planner::ui.setup_description'))

@push('head')
  <link rel="stylesheet" href="{{ \EveDev\Seat\IndusPlanner\Http\Controllers\AssetController::url('indus-planner.css') }}">
@endpush

@php
  $structureColors = ['Raitaru' => '#5588dd', 'Azbel' => '#dd8833', 'Sotiyo' => '#9944cc', 'Athanor' => '#44aa66', 'Tatara' => '#cc4444'];
  $securityColors = ['Highsec' => '#44cc44', 'Lowsec' => '#ccaa33', 'Null / Wormhole' => '#cc4444'];
  // Funnel of a column header: opens its filter (checkboxes, or a maximum).
  $filterButton = fn (string $col, string $kind = 'list', ?array $options = null) =>
      '<button type="button" class="indus-filter-btn" data-filter-col="' . $col . '" data-filter-kind="' . $kind . '"'
      . ($options ? ' data-options="' . e(json_encode($options)) . '"' : '')
      . ' aria-label="' . e(trans('indus-planner::ui.filter')) . '"><i class="fas fa-filter"></i></button>';
  $abbrev = function (string $name) {
      $s = preg_replace('/^Standup ?/', '', $name);
      return trim(str_replace(
          ['XL-Set ', 'L-Set ', 'M-Set ', 'Manufacturing ', 'Material Efficiency', 'Time Efficiency', 'Reactor ', 'Efficiency',
           'Equipment and Consumable', 'Structure and Component', 'Drone and Fighter', 'Advanced ', 'Component', 'Capital ',
           'Equipment', 'Structure', 'Blueprint Copy', 'Research ', 'Optimization', 'Accelerator', 'Laboratory', 'Ammunition'],
          ['XL ', 'L ', 'M ', 'Mfg ', 'ME', 'TE', 'Reac ', 'Eff',
           'Equip/Cons', 'Struct/Comp', 'Drone/Fighter', 'Adv ', 'Comp', 'Cap ',
           'Equip', 'Struct', 'BPC', 'Res ', 'Opt', 'Accel', 'Lab', 'Ammo'], $s));
  };
@endphp

@section('full')

  {{-- ===================================================== Structures --}}
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">{{ trans('indus-planner::ui.industrial_structures') }} <i class="fas fa-question-circle text-muted indus-help" data-placement="right" title="{{ trans('indus-planner::ui.structures_help') }}"></i></h3>
      <div class="card-tools">
        @if ($canManage)
          <form action="{{ route('indus-planner.structures.sync') }}" method="post" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-default" title="{{ trans('indus-planner::ui.sync_help') }}">
              <i class="fas fa-sync"></i> {{ trans('indus-planner::ui.sync') }}
            </button>
          </form>
          <a href="{{ route('indus-planner.structures.create') }}" class="btn btn-sm btn-primary">
            <i class="fas fa-plus"></i> {{ trans('indus-planner::ui.add_structure') }}
          </a>
        @endif
      </div>
    </div>

    <form action="{{ route('indus-planner.setup.structures') }}" method="post" data-dirty-watch>
      @csrf
      <div class="card-body p-0">

        @if ($structures->isEmpty())
          <div class="alert alert-info mx-3">
            {{ trans('indus-planner::ui.no_structures_available') }}
            @if ($canManage)
              {{ trans('indus-planner::ui.no_structures_manager') }}
            @else
              {{ trans('indus-planner::ui.no_structures_member') }}
            @endif
          </div>
        @else
          <div class="indus-structures-scroll">
          <table class="table table-sm mb-0 indus-structures" data-texts="{{ json_encode([
            'all' => trans('indus-planner::ui.filter_all'),
            'selected' => trans('indus-planner::ui.filter_selected'),
            'check_all' => trans('indus-planner::ui.check_all'),
            'uncheck_all' => trans('indus-planner::ui.uncheck_all'),
            'no_rig' => trans('indus-planner::ui.filter_no_rig'),
            'columns' => trans('indus-planner::ui.columns'),
            'summary' => trans('indus-planner::ui.filter_summary'),
            'max' => trans('indus-planner::ui.filter_max'),
            'clear' => trans('indus-planner::ui.filter_clear'),
            'text_hint' => trans('indus-planner::ui.filter_text_hint'),
          ]) }}" data-columns="{{ json_encode([
            ['use', trans('indus-planner::ui.use')],
            ['icon', trans('indus-planner::ui.icon')],
            ['type', trans('indus-planner::ui.type')],
            ['security', trans('indus-planner::ui.security')],
            ['system', trans('indus-planner::ui.system')],
            ['constellation', trans('indus-planner::ui.constellation')],
            ['region', trans('indus-planner::ui.region')],
            ['sci', 'SCI'],
            ['tax', trans('indus-planner::ui.tax')],
            ['rigs', trans('indus-planner::ui.rigs')],
            ['origin', trans('indus-planner::ui.origin')],
          ]) }}">
            <thead>
              <tr>
                <th class="text-center" style="width:60px" data-sort-col="use" data-column="use"><span class="indus-th-label">{{ trans('indus-planner::ui.use') }}</span>{!! $filterButton('use', 'list', [['used', trans('indus-planner::ui.filter_used')], ['unused', trans('indus-planner::ui.filter_unused')]]) !!}</th>
                <th style="width:40px" data-column="icon"></th>
                <th data-sort-col="name"><span class="indus-th-label">{{ trans('indus-planner::ui.name') }}</span>{!! $filterButton('name', 'text') !!}</th>
                <th data-sort-col="type" data-column="type"><span class="indus-th-label">{{ trans('indus-planner::ui.type') }}</span>{!! $filterButton('type') !!}</th>
                <th data-sort-col="security" data-column="security"><span class="indus-th-label">{{ trans('indus-planner::ui.security') }}</span>{!! $filterButton('security') !!}</th>
                <th data-sort-col="system" data-column="system"><span class="indus-th-label">{{ trans('indus-planner::ui.system') }}</span>{!! $filterButton('system') !!}</th>
                <th data-sort-col="constellation" data-column="constellation"><span class="indus-th-label">{{ trans('indus-planner::ui.constellation') }}</span>{!! $filterButton('constellation') !!}</th>
                <th data-sort-col="region" data-column="region"><span class="indus-th-label">{{ trans('indus-planner::ui.region') }}</span>{!! $filterButton('region') !!}</th>
                <th class="text-right" data-sort-col="sci" data-column="sci"><span class="indus-th-label" title="{{ trans('indus-planner::ui.sci_help') }}">SCI</span>{!! $filterButton('sci', 'max') !!}</th>
                <th class="text-right" data-sort-col="tax" data-column="tax"><span class="indus-th-label">{{ trans('indus-planner::ui.tax') }}</span>{!! $filterButton('tax', 'max') !!}</th>
                @for ($slot = 1; $slot <= 3; $slot++)
                  <th data-sort-col="rig{{ $slot }}" data-column="rigs"><span class="indus-th-label">Rig {{ $slot }}</span>{!! $filterButton('rig') !!}</th>
                @endfor
                <th data-sort-col="origin" data-column="origin"><span class="indus-th-label">{{ trans('indus-planner::ui.origin') }}</span>{!! $filterButton('origin') !!}</th>
                @if ($canManage)<th></th>@endif
              </tr>
            </thead>
            <tbody>
              @foreach ($structures as $structure)
                @php
                  $system = $systems[$structure->solar_system_id] ?? null;
                  $index = $indices->get($structure->solar_system_id);
                  $sci = $index ? ($structure->vocation() === 'reaction' ? $index->reaction : $index->manufacturing) : 0;
                  $structureRigs = $structure->rigs->pluck('rig_type_id')->values();
                @endphp
                <tr>
                  <td class="text-center" data-column="use">
                    <input type="checkbox" name="structures[]" value="{{ $structure->id }}" @checked(isset($selected[$structure->id]))>
                  </td>
                  <td data-column="icon"><img src="https://images.evetech.net/types/{{ $structure->structure_type_id }}/icon?size=32" width="28" height="28" alt="" loading="lazy"></td>
                  <td data-col="name" data-filter="{{ $structure->name }}"><strong>{{ $structure->name }}</strong></td>
                  <td data-column="type" data-col="type" data-filter="{{ $structure->typeName() }}" style="color: {{ $structureColors[$structure->typeName()] ?? '#888' }}">
                    {{ $structure->typeName() }}
                    <small class="text-muted">({{ $structure->vocation() === 'reaction' ? trans('indus-planner::ui.activity_reaction') : trans('indus-planner::ui.activity_manufacturing') }})</small>
                  </td>
                  <td data-column="security" data-col="security" data-filter="{{ $system['security_class'] ?? '—' }}" data-value="{{ $system['security_status'] ?? '' }}" style="color: {{ $securityColors[$system['security_class'] ?? ''] ?? '#888' }}">
                    {{ $system['security_class'] ?? '—' }}
                    @if ($system)<small>({{ number_format($system['security_status'], 2) }})</small>@endif
                  </td>
                  <td data-column="system" data-col="system" data-filter="{{ $system['name'] ?? $structure->solar_system_id }}">{{ $system['name'] ?? $structure->solar_system_id }}</td>
                  <td data-column="constellation" data-col="constellation" data-filter="{{ $system['constellation'] ?? '—' }}">{{ $system['constellation'] ?? '—' }}</td>
                  <td data-column="region" data-col="region" data-filter="{{ $system['region'] ?? '—' }}">{{ $system['region'] ?? '—' }}</td>
                  <td class="text-right" data-column="sci" data-col="sci" data-value="{{ $sci > 0 ? round($sci * 100, 2) : '' }}">{{ $sci > 0 ? number_format($sci * 100, 2, trans('indus-planner::ui.decimal_point'), trans('indus-planner::ui.thousands_sep')) . ' %' : '—' }}</td>
                  <td class="text-right" data-column="tax" data-col="tax" data-value="{{ (float) $structure->facility_tax_pct }}">{{ number_format($structure->facility_tax_pct, 2, trans('indus-planner::ui.decimal_point'), trans('indus-planner::ui.thousands_sep')) }} %</td>
                  @for ($i = 0; $i < 3; $i++)
                    @php
                      $rig = isset($structureRigs[$i]) ? ($rigs[$structureRigs[$i]] ?? null) : null;
                      $rigsUnknown = ! $rig && $i === 0 && $structure->source === 'esi' && ! $structure->rigs_known && ! $structure->rigs_manual;
                      $rigFilter = $rig ? $abbrev($rig['name']) . ($rig['tier'] === 2 ? ' ★' : '') : ($rigsUnknown ? trans('indus-planner::ui.rigs_unknown') : '—');
                    @endphp
                    <td data-column="rigs" data-col="rig{{ $i + 1 }}" data-filter="{{ $rigFilter }}">
                      @if ($rig)
                        <span title="{{ $rig['name'] }}">{{ $abbrev($rig['name']) }}@if ($rig['tier'] === 2) ★@endif</span>
                      @elseif ($rigsUnknown)
                        <span class="badge badge-warning" title="{{ trans('indus-planner::ui.rigs_unknown_help') }}">{{ trans('indus-planner::ui.rigs_unknown') }}</span>
                      @else
                        —
                      @endif
                    </td>
                  @endfor
                  <td data-column="origin" data-col="origin" data-filter="{{ $structure->source === 'esi' ? ($corporationNames[$structure->corporation_id] ?? trans('indus-planner::ui.corporation')) : trans('indus-planner::ui.manual') }}">
                    @if ($structure->source === 'esi')
                      <span class="badge badge-info" title="{{ trans('indus-planner::ui.imported_help') }}">{{ $corporationNames[$structure->corporation_id] ?? trans('indus-planner::ui.corporation') }}</span>
                      @if ($structure->rigs_manual)<span class="badge badge-secondary" title="{{ trans('indus-planner::ui.manual_rigs_help') }}">{{ trans('indus-planner::ui.manual_rigs') }}</span>@endif
                    @else
                      <span class="badge badge-secondary">{{ trans('indus-planner::ui.manual') }}</span>
                    @endif
                  </td>
                  @if ($canManage)
                    <td class="text-right text-nowrap">
                      <a href="{{ route('indus-planner.structures.edit', $structure) }}" class="btn btn-xs btn-default" title="{{ trans('indus-planner::ui.edit') }}"><i class="fas fa-pen"></i></a>
                      @if ($structure->source === 'manual')
                        <button type="submit" form="delete-structure-{{ $structure->id }}" class="btn btn-xs btn-danger" title="{{ trans('indus-planner::ui.delete') }}"><i class="fas fa-trash"></i></button>
                      @endif
                    </td>
                  @endif
                </tr>
              @endforeach
            </tbody>
          </table>
          </div>
        @endif
      </div>
      @if ($structures->isNotEmpty())
        <div class="card-footer">
          <button type="submit" class="btn btn-primary" data-save-button><i class="fas fa-save"></i> {{ trans('indus-planner::ui.save_selection') }}</button>
          <span id="indus-structures-count" class="text-muted ml-3" data-template="{{ trans('indus-planner::ui.structures_selected') }}" data-filtered="{{ trans('indus-planner::ui.structures_filtered') }}"></span>
        </div>
      @endif
    </form>

    @if ($canManage)
      @foreach ($structures->where('source', 'manual') as $structure)
        <form id="delete-structure-{{ $structure->id }}" action="{{ route('indus-planner.structures.destroy', $structure) }}" method="post"
              onsubmit="return confirm(@js(trans('indus-planner::ui.delete_structure_confirm', ['name' => $structure->name])));">
          @csrf
          @method('DELETE')
        </form>
      @endforeach
    @endif
  </div>

  {{-- ================================================== Asset sources --}}
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">{{ trans('indus-planner::ui.asset_sources') }} <i class="fas fa-question-circle text-muted indus-help" data-placement="right" title="{{ trans('indus-planner::ui.asset_sources_help') }}"></i></h3>
    </div>
    <form action="{{ route('indus-planner.setup.sources') }}" method="post" data-dirty-watch>
      @csrf
      <div class="card-body">

        <div class="indus-sources">
          <div data-check-group>
            <h5>{{ trans('indus-planner::ui.personal_hangars') }}</h5>
            @forelse ($characters as $characterId => $characterName)
              <div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input" id="source-char-{{ $characterId }}" name="characters[]"
                       value="{{ $characterId }}" @checked(in_array($characterId, $sources['characters'], true))>
                <label class="custom-control-label" for="source-char-{{ $characterId }}">
                  <img src="https://images.evetech.net/characters/{{ $characterId }}/portrait?size=32" width="20" height="20" class="img-circle" alt="">
                  {{ $characterName }}
                </label>
              </div>
            @empty
              <p class="text-muted">{{ trans('indus-planner::ui.no_characters') }}</p>
            @endforelse
            @if (count($characters))
              @include('indus-planner::partials.check-buttons')
            @endif
          </div>

          <div class="indus-sources-section">
            <h5>{{ trans('indus-planner::ui.corporation_hangars') }}</h5>
            <div class="indus-sources">
            @forelse ($corporations as $corporationId => $corporationName)
              @php $allowed = $allowedDivisions[$corporationId] ?? []; @endphp
              <div data-check-group>
                <strong>
                  <img src="https://images.evetech.net/corporations/{{ $corporationId }}/logo?size=32" width="20" height="20" alt="">
                  {{ $corporationName }}
                </strong>
                <div class="mt-1">
                  @for ($division = 1; $division <= 7; $division++)
                    @php $isAllowed = in_array($division, $allowed, true); @endphp
                    <div class="custom-control custom-checkbox" @unless ($isAllowed) title="{{ trans('indus-planner::ui.hangar_role_required', ['division' => $division]) }}" @endunless>
                      <input type="checkbox" class="custom-control-input" id="source-corp-{{ $corporationId }}-{{ $division }}"
                             name="corporations[{{ $corporationId }}][]" value="{{ $division }}"
                             @disabled(! $isAllowed)
                             @checked(in_array($division, $sources['corporations'][$corporationId] ?? [], true))>
                      <label class="custom-control-label {{ $isAllowed ? '' : 'text-muted' }}" for="source-corp-{{ $corporationId }}-{{ $division }}">
                        {{ $division }}. {{ $divisionNames[$corporationId][$division] ?? trans('indus-planner::plan.hangar', ['division' => $division]) }}
                      </label>
                    </div>
                  @endfor
                </div>
                {{-- The note takes the place of the buttons, so that the hangar
                     lists of every corporation start on the same line. --}}
                @if (empty($allowed))
                  <div class="indus-sources-note"><small class="text-muted">{{ trans('indus-planner::ui.no_hangar_role') }}</small></div>
                @else
                  @include('indus-planner::partials.check-buttons')
                @endif
              </div>
            @empty
              <p class="text-muted">{{ trans('indus-planner::ui.no_corporations') }}</p>
            @endforelse
            </div>
          </div>
        </div>
      </div>
      <div class="card-footer">
        <button type="submit" class="btn btn-primary" data-save-button><i class="fas fa-save"></i> {{ trans('indus-planner::ui.save_sources') }}</button>
      </div>
    </form>
  </div>

@stop

@push('javascript')
  <script src="{{ \EveDev\Seat\IndusPlanner\Http\Controllers\AssetController::url('setup.js') }}"></script>
@endpush
