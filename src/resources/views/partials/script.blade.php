{{-- This file is part of seat-indus-planner. | Copyright (C) 2026 EveDev | SPDX-License-Identifier: GPL-2.0-or-later --}}
{{-- Plugin script with the texts of the current SeAT language. --}}
<script>
  window.IndusPlannerLang = {
    locale: @json(str_replace('_', '-', app()->getLocale())),
    texts: @json(trans('indus-planner::js')),
  };
</script>
<script src="{{ \EveDev\Seat\IndusPlanner\Http\Controllers\AssetController::url('indus-planner.js') }}"></script>
