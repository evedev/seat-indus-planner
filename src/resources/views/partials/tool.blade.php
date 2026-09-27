{{-- This file is part of seat-indus-planner. | Copyright (C) 2026 EveDev | SPDX-License-Identifier: GPL-2.0-or-later --}}
{{-- Production tree, production plan and summary: shared by the Reactions
     and Production tools and by the saved plan page ($readOnly).
     All the content is rendered by indus-planner.js. --}}
@php($readOnly = $readOnly ?? false)
<div class="card indus-tool">
  <div class="card-header p-0 border-bottom-0 d-flex align-items-center">
    <ul class="nav nav-tabs flex-grow-1" role="tablist">
      <li class="nav-item">
        <a class="nav-link active" data-toggle="tab" href="#indus-tab-tree" role="tab">{{ trans('indus-planner::ui.tree') }}</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#indus-tab-plan" role="tab" id="indus-plan-tab-title">{{ trans('indus-planner::ui.production_plan') }}</a>
      </li>
    </ul>
    @unless ($readOnly)
      {{-- Puts the plan aside to track its progress (Industry > My plans). --}}
      <div class="indus-save-bar input-group input-group-sm mx-2">
        <input type="text" id="indus-save-name" class="form-control" maxlength="255" placeholder="{{ trans('indus-planner::ui.plan_name') }}" disabled>
        <div class="input-group-append">
          <button type="button" id="indus-save-btn" class="btn btn-primary" disabled title="{{ trans('indus-planner::ui.save_plan_help') }}">
            <i class="fas fa-save"></i> {{ trans('indus-planner::ui.save_plan') }}
          </button>
        </div>
      </div>
    @endunless
  </div>
  <div class="card-body p-2">
    <div id="indus-alerts"></div>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="indus-tab-tree" role="tabpanel">
        <div class="indus-tree-scroll">
          <div id="indus-tree" class="indus-tree"></div>
        </div>
        <small class="text-muted">
          @if ($readOnly)
            {!! trans('indus-planner::ui.tree_help_saved', ['check' => '<i class="fas fa-check"></i>']) !!}
          @else
            {!! trans('indus-planner::ui.tree_help', ['badges' => '<span class="indus-badge-produce">' . e(trans('indus-planner::js.mode_produce_short')) . '</span>/<span class="indus-badge-buy">' . e(trans('indus-planner::js.mode_buy_short')) . '</span>']) !!}
          @endif
        </small>
      </div>
      <div class="tab-pane fade" id="indus-tab-plan" role="tabpanel">
        <div class="row">
          <div class="col-xl-6" id="indus-jobs"></div>
          <div class="col-xl-6" id="indus-purchases"></div>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Summary tiles: cost, value, margin, left to buy. Amounts are shortened;
     the exact figures are in each tile tooltip. --}}
<div class="indus-summary-tiles">
  <div class="indus-tile" id="tile-cost">
    <div class="indus-tile-label">{{ trans('indus-planner::ui.total_cost') }}</div>
    <div class="indus-tile-value" id="sum-total">—</div>
    <div class="indus-tile-bar"><div class="purchases" id="sum-bar-purchases" style="width:0"></div><div class="jobs" id="sum-bar-jobs" style="width:0"></div></div>
    <div class="indus-tile-sub"><span class="indus-dot purchases"></span>{{ trans('indus-planner::ui.purchases') }} <span id="sum-purchases">—</span> · <span class="indus-dot jobs"></span>{{ trans('indus-planner::ui.jobs') }} <span id="sum-runs">—</span></div>
  </div>
  <div class="indus-tile" id="tile-value">
    <div class="indus-tile-label">{{ trans('indus-planner::ui.produced_value') }}</div>
    <div class="indus-tile-value" id="sum-value-sell">—</div>
    <div class="indus-tile-sub">Jita sell · buy <span id="sum-value-buy">—</span></div>
  </div>
  <div class="indus-tile" id="tile-margin">
    <div class="indus-tile-label">{{ trans('indus-planner::ui.margin_sell') }}</div>
    <div class="indus-tile-value" id="sum-margin-sell">—</div>
    <div class="indus-tile-sub"><span id="sum-profit-sell">—</span> · buy <span id="sum-margin-buy">—</span></div>
  </div>
  <div class="indus-tile" id="tile-missing">
    <div class="indus-tile-label">{{ trans('indus-planner::ui.left_to_buy') }}</div>
    <div class="indus-tile-value" id="sum-missing">—</div>
    <div class="indus-tile-sub"><span id="sum-missing-note">{{ trans('indus-planner::ui.stock_deducted') }}</span> · {{ trans('indus-planner::ui.surplus') }} <span id="sum-surplus-sell">—</span></div>
  </div>
</div>
