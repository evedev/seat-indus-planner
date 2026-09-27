<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Domain;

/**
 * Production tree node.
 */
final class TreeItem
{
    public function __construct(
        public int $typeId,
        public string $name,
        public int $groupId,
        public int $qtyBase,        // base quantity per run
        public int $qtyActual,      // after ME reduction
        public int $qtyTotal,       // quantity needed = qty_actual x parent runs
        public bool $isReactionOutput = false, // the item itself can be produced
        public bool $producedByReaction = false,
        public int $runs = 0,
        public int $qtyProduced = 0, // runs x output per run
        public int $surplus = 0,     // overproduction
        public float $jobCost = 0.0,
        public float $volume = 0.0,
        public string $productCategory = '',
        public int $timeSeconds = 0,
        public string $structureName = '',
    ) {
    }

    public function with(array $changes): self
    {
        $clone = clone $this;
        foreach ($changes as $key => $value) {
            $clone->{$key} = $value;
        }

        return $clone;
    }

    public function toArray(): array
    {
        return [
            'type_id' => $this->typeId,
            'name' => $this->name,
            'group_id' => $this->groupId,
            'qty_base' => $this->qtyBase,
            'qty_actual' => $this->qtyActual,
            'qty_total' => $this->qtyTotal,
            'is_reaction_output' => $this->isReactionOutput,
            'produced_by_reaction' => $this->producedByReaction,
            'runs' => $this->runs,
            'qty_produced' => $this->qtyProduced,
            'surplus' => $this->surplus,
            'job_cost' => $this->jobCost,
            'volume' => $this->volume,
            'product_category' => $this->productCategory,
            'time_seconds' => $this->timeSeconds,
            'structure_name' => $this->structureName,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            typeId: (int) $data['type_id'],
            name: (string) ($data['name'] ?? ''),
            groupId: (int) ($data['group_id'] ?? 0),
            qtyBase: (int) ($data['qty_base'] ?? 0),
            qtyActual: (int) ($data['qty_actual'] ?? 0),
            qtyTotal: (int) ($data['qty_total'] ?? 0),
            isReactionOutput: (bool) ($data['is_reaction_output'] ?? false),
            producedByReaction: (bool) ($data['produced_by_reaction'] ?? false),
            runs: (int) ($data['runs'] ?? 0),
            qtyProduced: (int) ($data['qty_produced'] ?? 0),
            surplus: (int) ($data['surplus'] ?? 0),
            jobCost: (float) ($data['job_cost'] ?? 0.0),
            volume: (float) ($data['volume'] ?? 0.0),
            productCategory: (string) ($data['product_category'] ?? ''),
            timeSeconds: (int) ($data['time_seconds'] ?? 0),
            structureName: (string) ($data['structure_name'] ?? ''),
        );
    }
}
