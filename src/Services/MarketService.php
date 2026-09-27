<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Prices taken from the market data already synchronised by SeAT:
 *  - Jita buy / sell: best orders of the reference system in
 *    `market_orders`;
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
     * @return array<int, array{jita_buy:float, jita_sell:float, average:float, adjusted:float, volume:float}>
     */
    public function priceMap(array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        if (empty($typeIds))
            return [];

        $systemId = config('indus-planner.price_system_id');
        $sell = DB::table('market_orders')->where('system_id', $systemId)->where('is_buy_order', false)
            ->whereIn('type_id', $typeIds)->groupBy('type_id')->selectRaw('type_id, MIN(price) AS price')->pluck('price', 'type_id');
        $buy = DB::table('market_orders')->where('system_id', $systemId)->where('is_buy_order', true)
            ->whereIn('type_id', $typeIds)->groupBy('type_id')->selectRaw('type_id, MAX(price) AS price')->pluck('price', 'type_id');
        $esi = DB::table('market_prices')->whereIn('type_id', $typeIds)->get(['type_id', 'average_price', 'adjusted_price'])->keyBy('type_id');

        $out = [];
        foreach ($typeIds as $typeId) {
            $row = $esi->get($typeId);
            $out[$typeId] = [
                'jita_buy' => (float) ($buy[$typeId] ?? 0.0),
                'jita_sell' => (float) ($sell[$typeId] ?? 0.0),
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
