<?php

/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace EveDev\Seat\IndusPlanner\Services;

use Illuminate\Support\Facades\DB;
use Seat\Web\Models\User;

/**
 * What the signed-in user can see: their characters, their corporations and
 * alliances, and the hangar divisions their in-game roles allow.
 */
class UserContext
{
    private ?array $characters = null;
    private ?array $affiliations = null;

    public function __construct(public User $user)
    {
    }

    /** @return array<int, string> {character_id: name}, sorted by name */
    public function characters(): array
    {
        if ($this->characters === null) {
            $this->characters = $this->user->characters()->pluck('name', 'character_infos.character_id')->all();
            asort($this->characters, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $this->characters;
    }

    /** @return array<int, object{character_id:int, corporation_id:int, alliance_id:?int}> */
    private function affiliations(): array
    {
        if ($this->affiliations === null) {
            $this->affiliations = DB::table('character_affiliations')
                ->whereIn('character_id', array_keys($this->characters()))
                ->get(['character_id', 'corporation_id', 'alliance_id'])
                ->keyBy('character_id')
                ->all();
        }

        return $this->affiliations;
    }

    /** @return array<int, string> {corporation_id: name} of their characters' corporations */
    public function corporations(): array
    {
        $ids = array_unique(array_map(fn ($a) => (int) $a->corporation_id, $this->affiliations()));
        if (empty($ids))
            return [];

        $names = DB::table('corporation_infos')->whereIn('corporation_id', $ids)->pluck('name', 'corporation_id')->all();
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $names[$id] ?? DB::table('universe_names')->where('entity_id', $id)->value('name') ?? (string) $id;
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /** @return int[] their characters' alliances */
    public function allianceIds(): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($a) => (int) $a->alliance_id, $this->affiliations()))));
    }

    /**
     * Corporations whose structures are offered to the user: the ones of
     * their alliances known to SeAT, plus their own corporations.
     *
     * @return int[]
     */
    public function structureCorporationIds(): array
    {
        $ids = array_keys($this->corporations());
        $alliances = $this->allianceIds();
        if (! empty($alliances)) {
            $ids = array_merge($ids, DB::table('corporation_infos')->whereIn('alliance_id', $alliances)->pluck('corporation_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Hangar divisions (1 to 7) allowed by the in-game roles of the user's
     * characters, by corporation: Director gives all of them, Hangar_Query_N
     * or Hangar_Take_N give division N.
     *
     * @return array<int, int[]> {corporation_id: [divisions]}
     */
    public function allowedDivisions(): array
    {
        $byCharacter = DB::table('character_roles')
            ->whereIn('character_id', array_keys($this->characters()))
            ->get(['character_id', 'role'])
            ->groupBy('character_id');

        $allowed = [];
        foreach ($this->affiliations() as $characterId => $affiliation) {
            $corporationId = (int) $affiliation->corporation_id;
            foreach ($byCharacter->get($characterId, collect()) as $row) {
                if ($row->role === 'Director') {
                    $allowed[$corporationId] = range(1, 7);
                    break;
                }
                if (preg_match('/^Hangar_(?:Query|Take)_([1-7])$/', $row->role, $m))
                    $allowed[$corporationId][] = (int) $m[1];
            }
        }

        foreach ($allowed as $corporationId => $divisions) {
            $divisions = array_values(array_unique($divisions));
            sort($divisions);
            $allowed[$corporationId] = $divisions;
        }

        return $allowed;
    }

    /** @return array<int, array<int, string>> {corporation_id: {division: name}} */
    public function divisionNames(array $corporationIds): array
    {
        $out = [];
        foreach (DB::table('corporation_divisions')->whereIn('corporation_id', $corporationIds)->where('type', 'hangar')->get() as $row) {
            $out[(int) $row->corporation_id][(int) $row->division] = (string) $row->name;
        }

        return $out;
    }
}
