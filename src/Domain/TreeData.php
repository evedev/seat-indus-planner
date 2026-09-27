<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Complete production tree.
 */
final class TreeData
{
    /**
     * @param  TreeItem[]  $rank1
     * @param  array<int, TreeItem[]>  $subItems  key: parent type_id
     * @param  int[]  $rank1InitiallyBuy  rank 1 type_ids initially shown in buy mode
     */
    public function __construct(
        public TreeItem $output,
        public array $rank1,
        public array $subItems = [],
        public array $rank1InitiallyBuy = [],
    ) {
    }

    /** @return TreeItem[] every node, root included */
    public function allItems(): array
    {
        $items = [$this->output, ...$this->rank1];
        foreach ($this->subItems as $children) {
            array_push($items, ...$children);
        }

        return $items;
    }

    public function toArray(): array
    {
        $sub = [];
        foreach ($this->subItems as $parentId => $children) {
            $sub[(string) $parentId] = array_map(fn (TreeItem $i) => $i->toArray(), $children);
        }

        return [
            'output' => $this->output->toArray(),
            'rank1' => array_map(fn (TreeItem $i) => $i->toArray(), $this->rank1),
            'sub_items' => (object) $sub,
            'rank1_initially_buy' => array_values($this->rank1InitiallyBuy),
        ];
    }
}
