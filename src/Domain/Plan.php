<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Production plan: jobs to run and materials to buy, derived from the tree
 * state (produce / buy mode chosen for each node).
 *
 * User-facing texts default to English; the SeAT layer passes translated
 * labels (see LABELS for the keys and placeholders).
 */
final class Plan
{
    public const MODE_PRODUCE = 'P';
    public const MODE_BUY = 'B';

    public const ACTIVITY_REACTION = 'reaction';
    public const ACTIVITY_MANUFACTURING = 'manufacturing';

    // At equal rank, reactions come before manufacturing.
    private const ACTIVITY_ORDER = [self::ACTIVITY_REACTION => 0, self::ACTIVITY_MANUFACTURING => 1];

    // Default user-facing labels. `:tier`, `:kind`, `:owner`, `:place`,
    // `:quantity` and `:count` are replaced.
    public const LABELS = [
        'unknown_group' => 'Other',
        'planetary_group' => 'Planetary products - tier :tier',
        'stock_unknown' => 'Assets not loaded: stock is unknown.',
        'stock_none' => 'No stock owned.',
        'stock_title' => 'Stock owned:',
        'stock_line' => ':kind :owner — :place: :quantity',
        'stock_more' => '… and :count more location(s)',
        'unknown_place' => 'Unknown location',
        'kind_character' => 'Character',
        'kind_corporation' => 'Corporation',
    ];

    // SDE groups of Planetary Interaction products, with their tier.
    public const PLANETARY_TIERS = [
        1031 => 0, // Planetary Resources
        1042 => 1, // Basic Commodities
        1034 => 2, // Refined Commodities
        1040 => 3, // Specialized Commodities
        1041 => 4, // Advanced Commodities
    ];

    private const MAX_PLACES_SHOWN = 12;
    private const THIN_SPACE = "\u{202F}";

    public static function planetaryGroupName(int $groupId, array $labels = []): ?string
    {
        $tier = self::PLANETARY_TIERS[$groupId] ?? null;

        return $tier === null ? null : self::label($labels, 'planetary_group', ['tier' => $tier]);
    }

    public static function unknownGroup(array $labels = []): string
    {
        return self::label($labels, 'unknown_group');
    }

    private static function label(array $labels, string $key, array $replace = []): string
    {
        $text = $labels[$key] ?? self::LABELS[$key];
        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * Jobs to run, from the deepest rank to the final product; an item that
     * appears several times is grouped on a single line.
     *
     * @param  array<int, array{item: TreeItem, rank: int, mode: string}>  $entries
     * @return array<int, array> job lines
     */
    public static function productionJobs(array $entries): array
    {
        $lines = [];
        foreach ($entries as $entry) {
            $item = $entry['item'];
            if ($entry['mode'] !== self::MODE_PRODUCE || $item->runs <= 0)
                continue;

            if (! isset($lines[$item->typeId])) {
                $lines[$item->typeId] = [
                    'type_id' => $item->typeId,
                    'name' => $item->name,
                    'rank' => $entry['rank'],
                    'activity' => $item->producedByReaction ? self::ACTIVITY_REACTION : self::ACTIVITY_MANUFACTURING,
                    'runs' => $item->runs,
                    'runs_per_job' => $item->runsPerJob ?: $item->runs,
                    'seconds' => $item->timeSeconds,
                    'qty_produced' => $item->qtyProduced,
                    'qty_needed' => $item->qtyTotal,
                    'surplus' => $item->surplus,
                    'job_cost' => $item->jobCost,
                    'structure_name' => $item->structureName,
                    'category' => $item->productCategory,
                ];
                continue;
            }

            $line = &$lines[$item->typeId];
            $line['rank'] = max($line['rank'], $entry['rank']);
            $line['runs'] += $item->runs;
            $line['seconds'] += $item->timeSeconds;
            $line['qty_produced'] += $item->qtyProduced;
            $line['qty_needed'] += $item->qtyTotal;
            $line['surplus'] += $item->surplus;
            $line['job_cost'] += $item->jobCost;
            unset($line);
        }

        $lines = array_values($lines);
        usort($lines, function ($a, $b) {
            return [-$a['rank'], self::ACTIVITY_ORDER[$a['activity']], mb_strtolower($a['name'])]
                <=> [-$b['rank'], self::ACTIVITY_ORDER[$b['activity']], mb_strtolower($b['name'])];
        });

        foreach ($lines as &$line) {
            $line['is_final'] = $line['rank'] === 0;
        }
        unset($line);

        return $lines;
    }

    /**
     * Materials to buy: nodes in "buy" mode (final product excluded).
     *
     * @param  array<int, array{item: TreeItem, rank: int, mode: string}>  $entries
     * @param  array<int, array>  $prices  {type_id: {sell, sell_fallback, volume, ...}}
     * @param  array<int,int>|null  $stock  {type_id: owned quantity}
     * @param  callable(int): ?string|null  $groupNameOf
     * @param  array<int, array<int, array>>|null  $stockPlaces  {type_id: [StockPlace]}
     * @param  array<string, string>  $labels  translated labels (see LABELS)
     * @return array<int, array> purchase lines
     */
    public static function purchases(array $entries, array $prices, ?array $stock = null, ?callable $groupNameOf = null, ?array $stockPlaces = null, array $labels = []): array
    {
        $unknownGroup = self::unknownGroup($labels);
        $stock = $stock ?? [];
        $lines = [];
        foreach ($entries as $entry) {
            $item = $entry['item'];
            if ($entry['mode'] !== self::MODE_BUY || $entry['rank'] === 0 || $item->qtyTotal <= 0)
                continue;

            if (isset($lines[$item->typeId])) {
                $lines[$item->typeId]['quantity'] += $item->qtyTotal;
                continue;
            }

            $market = $prices[$item->typeId] ?? [];
            $groupName = self::planetaryGroupName($item->groupId, $labels)
                ?? ($groupNameOf ? $groupNameOf($item->groupId) : null)
                ?? $unknownGroup;

            $lines[$item->typeId] = [
                'type_id' => $item->typeId,
                'name' => $item->name,
                'quantity' => $item->qtyTotal,
                'unit_volume' => $item->volume ?: (float) ($market['volume'] ?? 0.0),
                'unit_price' => (float) ($market['sell'] ?? 0.0),
                'price_fallback' => (bool) ($market['sell_fallback'] ?? false),
                'in_stock' => (int) ($stock[$item->typeId] ?? 0),
                'group_id' => $item->groupId,
                'group_name' => $groupName,
                'places' => array_values(($stockPlaces ?? [])[$item->typeId] ?? []),
            ];
        }

        $lines = array_values($lines);
        foreach ($lines as &$line) {
            $line['total_volume'] = $line['unit_volume'] * $line['quantity'];
            $line['total_price'] = $line['unit_price'] * $line['quantity'];
            $line['to_buy'] = max(0, $line['quantity'] - $line['in_stock']);
        }
        unset($line);

        // "Other" closes the list: it is a catch-all, not a family.
        usort($lines, function ($a, $b) use ($unknownGroup) {
            return [$a['group_name'] === $unknownGroup, mb_strtolower($a['group_name']), mb_strtolower($a['name'])]
                <=> [$b['group_name'] === $unknownGroup, mb_strtolower($b['group_name']), mb_strtolower($b['name'])];
        });

        return $lines;
    }

    /**
     * Describes where a stock is.
     *
     * @param  array<int, array{owner:string, owner_kind:string, place:string, system:string, quantity:int}>  $places
     * @param  array<string, string>  $labels  translated labels (see LABELS)
     */
    public static function stockTooltip(array $places, bool $known = true, array $labels = []): string
    {
        if (! $known)
            return self::label($labels, 'stock_unknown');

        usort($places, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);
        if (empty($places))
            return self::label($labels, 'stock_none');

        $rows = [];
        foreach (array_slice($places, 0, self::MAX_PLACES_SHOWN) as $stock) {
            $where = $stock['place'] ?: self::label($labels, 'unknown_place');
            if ($stock['system'] && ! str_contains($where, $stock['system']))
                $where .= " ({$stock['system']})";
            $kind = in_array($stock['owner_kind'], ['character', 'corporation'], true)
                ? self::label($labels, 'kind_' . $stock['owner_kind'])
                : $stock['owner_kind'];
            $rows[] = self::label($labels, 'stock_line', [
                'kind' => $kind,
                'owner' => $stock['owner'],
                'place' => $where,
                'quantity' => str_replace(',', self::THIN_SPACE, number_format($stock['quantity'])),
            ]);
        }

        $hidden = count($places) - self::MAX_PLACES_SHOWN;
        if ($hidden > 0)
            $rows[] = self::label($labels, 'stock_more', ['count' => $hidden]);

        return self::label($labels, 'stock_title') . "\n" . implode("\n", $rows);
    }

    /**
     * EVE Multibuy format: "name<TAB>quantity", one material per line. Only
     * the missing quantity is listed when the stock is known.
     */
    public static function multibuyText(array $lines, bool $stockKnown): string
    {
        $rows = [];
        foreach ($lines as $line) {
            $quantity = $stockKnown ? max(0, $line['quantity'] - $line['in_stock']) : $line['quantity'];
            if ($quantity > 0)
                $rows[] = "{$line['name']}\t{$quantity}";
        }

        return implode("\n", $rows);
    }
}
