<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use EveDev\Seat\IndusPlanner\Models\Market;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Services\Contracts\EsiClient;
use Throwable;

/**
 * Downloads the orders of a player structure market and keeps the best buy
 * and sell price of each item. Reading a structure market needs a character
 * allowed to dock there: the registered characters are tried in turn.
 */
class MarketRefresher
{
    private const SCOPE = 'esi-markets.structure_markets.v1';
    private const ENDPOINT = '/markets/structures/{structure_id}/';
    private const MAX_PAGES = 200;

    /** @return array{types:int, orders:int} */
    public function refresh(Market $market): array
    {
        $errors = [];
        foreach ($this->tokens() as $token) {
            try {
                [$best, $orders] = $this->download($market, $token);
                $this->store($market, $best);
                $market->update(['refreshed_at' => now(), 'types_count' => count($best), 'last_error' => null]);

                return ['types' => count($best), 'orders' => $orders];
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $message = $errors ? 'No character can read this market: ' . end($errors) : 'No character with the ' . self::SCOPE . ' scope.';
        $market->update(['last_error' => mb_substr($message, 0, 255)]);
        throw new RuntimeException($message);
    }

    /** @return \Illuminate\Support\Collection<int, RefreshToken> */
    private function tokens()
    {
        return RefreshToken::all()->filter(fn (RefreshToken $t) => in_array(self::SCOPE, $t->getScopes(), true))->values();
    }

    /** @return array{0: array<int, array{buy:?float, sell:?float}>, 1: int} */
    private function download(Market $market, RefreshToken $token): array
    {
        $best = [];
        $orders = 0;
        $page = 1;
        $pages = 1;
        do {
            $esi = app()->make(EsiClient::class);
            $esi->setAuthentication($token);
            $esi->page($page);
            try {
                $response = $esi->invoke('get', self::ENDPOINT, ['structure_id' => $market->structure_id]);
            } finally {
                $this->saveToken($esi, $token);
            }
            $pages = min(self::MAX_PAGES, (int) ($response->getPagesCount() ?? 1));
            foreach ($response->getBody() as $order) {
                $orders++;
                $typeId = (int) $order->type_id;
                $price = (float) $order->price;
                $best[$typeId] ??= ['buy' => null, 'sell' => null];
                if ($order->is_buy_order) {
                    $best[$typeId]['buy'] = max($best[$typeId]['buy'] ?? 0.0, $price);
                } else {
                    $best[$typeId]['sell'] = min($best[$typeId]['sell'] ?? INF, $price);
                }
            }
        } while (++$page <= $pages);

        return [$best, $orders];
    }

    // Same bookkeeping as the SeAT jobs: SSO may have rotated the refresh
    // token while authenticating.
    private function saveToken(EsiClient $esi, RefreshToken $token): void
    {
        if (! $esi->isAuthenticated())
            return;
        $auth = $esi->getAuthentication();
        if (! empty($auth->getRefreshToken()))
            $token->refresh_token = $auth->getRefreshToken();
        $token->token = $auth->getAccessToken() ?? '-';
        $token->expires_on = $auth->getExpiresOn();
        $token->save();
    }

    private function store(Market $market, array $best): void
    {
        DB::transaction(function () use ($market, $best) {
            DB::table('indus_planner_market_prices')->where('market_id', $market->id)->delete();
            $rows = [];
            foreach ($best as $typeId => $price) {
                $rows[] = ['market_id' => $market->id, 'type_id' => $typeId, 'buy' => $price['buy'], 'sell' => $price['sell']];
            }
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('indus_planner_market_prices')->insert($chunk);
            }
        });
    }
}
