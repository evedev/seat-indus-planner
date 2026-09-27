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
  $abbrev = function (string $name) {
      $s = preg_replace('/^Standup ?/', '', $name);
      return trim(str_replace(
          ['XL-Set ', 'L-Set ', 'M-Set ', 'Manufacturing ', 'Material Efficiency', 'Time Efficiency', 'Reactor ', 'Efficiency'],
          ['XL ', 'L ', 'M ', 'Mfg ', 'ME', 'TE', 'Reac ', 'Eff'], $s));
  };
@endphp

@section('full')

  {{-- ===================================================== Structures --}}
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">{{ trans('indus-planner::ui.industrial_structures') }}</h3>
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

    <form action="{{ route('indus-planner.setup.structures') }}" method="post">
      @csrf
      <div class="card-body p-0">
        <p class="text-muted px-3 pt-3 mb-2">{{ trans('indus-planner::ui.structures_help') }}</p>

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
          <table class="table table-striped table-sm mb-0 indus-structures">
            <thead>
              <tr>
                <th class="text-center" style="width:60px">{{ trans('indus-planner::ui.use') }}</th>
                <th style="width:40px"></th>
                <th>{{ trans('indus-planner::ui.name') }}</th>
                <th>{{ trans('indus-planner::ui.type') }}</th>
                <th>{{ trans('indus-planner::ui.security') }}</th>
                <th>{{ trans('indus-planner::ui.system') }}</th>
                <th class="text-right" title="{{ trans('indus-planner::ui.sci_help') }}">SCI</th>
                <th class="text-right">{{ trans('indus-planner::ui.tax') }}</th>
                <th>Rig 1</th>
                <th>Rig 2</th>
                <th>Rig 3</th>
                <th>{{ trans('indus-planner::ui.origin') }}</th>
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
                  <td class="text-center">
                    <input type="checkbox" name="structures[]" value="{{ $structure->id }}" @checked(isset($selected[$structure->id]))>
                  </td>
                  <td><img src="https://images.evetech.net/types/{{ $structure->structure_type_id }}/icon?size=32" width="28" height="28" alt="" loading="lazy"></td>
                  <td><strong>{{ $structure->name }}</strong></td>
                  <td style="color: {{ $structureColors[$structure->typeName()] ?? '#888' }}">
                    {{ $structure->typeName() }}
                    <small class="text-muted">({{ $structure->vocation() === 'reaction' ? trans('indus-planner::ui.activity_reaction') : trans('indus-planner::ui.activity_manufacturing') }})</small>
                  </td>
                  <td style="color: {{ $securityColors[$system['security_class'] ?? ''] ?? '#888' }}">
                    {{ $system['security_class'] ?? '—' }}
                    @if ($system)<small>({{ number_format($system['security_status'], 2) }})</small>@endif
                  </td>
                  <td>{{ $system['name'] ?? $structure->solar_system_id }}</td>
                  <td class="text-right">{{ $sci > 0 ? number_format($sci * 100, 2, trans('indus-planner::ui.decimal_point'), trans('indus-planner::ui.thousands_sep')) . ' %' : '—' }}</td>
                  <td class="text-right">{{ number_format($structure->facility_tax_pct, 2, trans('indus-planner::ui.decimal_point'), trans('indus-planner::ui.thousands_sep')) }} %</td>
                  @for ($i = 0; $i < 3; $i++)
                    @php $rig = isset($structureRigs[$i]) ? ($rigs[$structureRigs[$i]] ?? null) : null; @endphp
                    <td>
                      @if ($rig)
                        <span title="{{ $rig['name'] }}">{{ $abbrev($rig['name']) }}@if ($rig['tier'] === 2) ★@endif</span>
                      @elseif ($i === 0 && $structure->source === 'esi' && ! $structure->rigs_known && ! $structure->rigs_manual)
                        <span class="badge badge-warning" title="{{ trans('indus-planner::ui.rigs_unknown_help') }}">{{ trans('indus-planner::ui.rigs_unknown') }}</span>
                      @else
                        —
                      @endif
                    </td>
                  @endfor
                  <td>
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
        @endif
      </div>
      @if ($structures->isNotEmpty())
        <div class="card-footer">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> {{ trans('indus-planner::ui.save_selection') }}</button>
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
      <h3 class="card-title">{{ trans('indus-planner::ui.asset_sources') }}</h3>
    </div>
    <form action="{{ route('indus-planner.setup.sources') }}" method="post">
      @csrf
      <div class="card-body">
        <p class="text-muted">{{ trans('indus-planner::ui.asset_sources_help') }}</p>

        <div class="row">
          <div class="col-md-4">
            <h5>{{ trans('indus-planner::ui.characters') }}</h5>
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
          </div>

          <div class="col-md-8">
            <h5>{{ trans('indus-planner::ui.corporation_hangars') }}</h5>
            <div class="row">
            @forelse ($corporations as $corporationId => $corporationName)
              @php $allowed = $allowedDivisions[$corporationId] ?? []; @endphp
              <div class="col-sm-6 mb-3">
                <strong>
                  <img src="https://images.evetech.net/corporations/{{ $corporationId }}/logo?size=32" width="20" height="20" alt="">
                  {{ $corporationName }}
                </strong>
                @if (empty($allowed))
                  <div><small class="text-muted">{{ trans('indus-planner::ui.no_hangar_role') }}</small></div>
                @endif
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
              </div>
            @empty
              <p class="col text-muted">{{ trans('indus-planner::ui.no_corporations') }}</p>
            @endforelse
            </div>
          </div>
        </div>
      </div>
      <div class="card-footer">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> {{ trans('indus-planner::ui.save_sources') }}</button>
      </div>
    </form>
  </div>

@stop
