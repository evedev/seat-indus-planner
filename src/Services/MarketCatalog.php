<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use EveDev\Seat\IndusPlanner\Models\Market;

/**
 * Price sources offered to the user: Jita (orders downloaded by SeAT) and
 * the player structure markets added in the Industry setup. The choice is
 * shared by both tools and kept in the user's SeAT profile settings.
 */
class MarketCatalog
{
    public const JITA = 'jita';

    private const SETTING = 'indus_planner_market';

    /** @return array<int, array{value:string, label:string, disabled:bool}> */
    public function options(): array
    {
        $options = [['value' => self::JITA, 'label' => 'Jita', 'disabled' => false]];
        foreach (Market::orderBy('name')->get() as $market) {
            $options[] = ['value' => (string) $market->id, 'label' => $market->name, 'disabled' => $market->types_count === 0];
        }

        return $options;
    }

    /** Market chosen by the user, Jita when it no longer exists or has no prices yet. */
    public function resolve($value): ?Market
    {
        if ($value === null || $value === '' || $value === self::JITA || ! ctype_digit((string) $value))
            return null;

        return Market::where('id', (int) $value)->where('types_count', '>', 0)->first();
    }

    public function current(): ?Market
    {
        return $this->resolve(setting(self::SETTING));
    }

    public function currentValue(): string
    {
        $market = $this->current();

        return $market ? (string) $market->id : self::JITA;
    }

    public function remember(?Market $market): void
    {
        $value = $market ? (string) $market->id : self::JITA;
        if ((string) setting(self::SETTING) !== $value)
            setting([self::SETTING, $value]);
    }

    /** @return array{id:?int, name:string, short:string} */
    public static function describe(?Market $market): array
    {
        return $market
            ? ['id' => $market->id, 'name' => $market->name, 'short' => $market->shortName()]
            : ['id' => null, 'name' => 'Jita', 'short' => 'Jita'];
    }
}
