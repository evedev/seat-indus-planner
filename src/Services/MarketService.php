<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use EveDev\Seat\IndusPlanner\Models\Market;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Prices taken from the market data:
 *  - buy / sell: best orders of the chosen market. Jita by default (orders
 *    of the reference system synchronised by SeAT in `market_orders`), or
 *    a player structure market downloaded by the plugin; an item missing
 *    from that market falls back to its Jita price, flagged;
 *  - average / adjusted: `market_prices` (/markets/prices/ endpoint).
 */
class MarketService
{
    public function __construct(private SdeCatalog $sde)
    {
    }

    /**
     * Price table used by the views.
     *
     * @param  int[]  $typeIds
     * @return array<int, array{buy:float, sell:float, buy_fallback:bool, sell_fallback:bool, average:float, adjusted:float, volume:float}>
     */
    public function priceMap(array $typeIds, ?Market $market = null): array
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        if (empty($typeIds))
            return [];

        $systemId = config('indus-planner.price_system_id');
        $jitaSell = DB::table('market_orders')->where('system_id', $systemId)->where('is_buy_order', false)
            ->whereIn('type_id', $typeIds)->groupBy('type_id')->selectRaw('type_id, MIN(price) AS price')->pluck('price', 'type_id');
        $jitaBuy = DB::table('market_orders')->where('system_id', $systemId)->where('is_buy_order', true)
            ->whereIn('type_id', $typeIds)->groupBy('type_id')->selectRaw('type_id, MAX(price) AS price')->pluck('price', 'type_id');
        $esi = DB::table('market_prices')->whereIn('type_id', $typeIds)->get(['type_id', 'average_price', 'adjusted_price'])->keyBy('type_id');
        $local = $market === null ? collect() : DB::table('indus_planner_market_prices')->where('market_id', $market->id)
            ->whereIn('type_id', $typeIds)->get(['type_id', 'buy', 'sell'])->keyBy('type_id');

        $out = [];
        foreach ($typeIds as $typeId) {
            $row = $esi->get($typeId);
            $own = $local->get($typeId);
            $sell = $own->sell ?? null;
            $buy = $own->buy ?? null;
            $out[$typeId] = [
                'buy' => (float) ($buy ?? $jitaBuy[$typeId] ?? 0.0),
                'sell' => (float) ($sell ?? $jitaSell[$typeId] ?? 0.0),
                'buy_fallback' => $market !== null && $buy === null && isset($jitaBuy[$typeId]),
                'sell_fallback' => $market !== null && $sell === null && isset($jitaSell[$typeId]),
                'average' => (float) ($row->average_price ?? 0.0),
                'adjusted' => (float) ($row->adjusted_price ?? 0.0),
                'volume' => $this->sde->volume($typeId),
            ];
        }

        return $out;
    }

    /**
     * Every adjusted_price, basis of the EIV (job installation cost).
     *
     * @return array<int, float>
     */
    public function adjustedPrices(): array
    {
        return Cache::remember('indus-planner:adjusted-prices', 3600, fn () => DB::table('market_prices')
            ->where('adjusted_price', '>', 0)
            ->pluck('adjusted_price', 'type_id')
            ->map(fn ($p) => (float) $p)
            ->all());
    }
}
