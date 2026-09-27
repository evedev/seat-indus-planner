# seat-indus-planner

Industry tools for [SeAT 5](https://github.com/eveseat/seat), built on the data
SeAT already synchronises (SDE, market, skills, assets, blueprints, corporation
structures).

- **Industry setup**: the industrial structures to use (imported from your
  alliance corporations, or added by hand) and the asset holders whose stock is
  taken into account (characters and corporation hangar divisions).
- **Reactions**: reaction chain simulation.
- **Production**: manufacturing order simulation.
- Both tools show the complete **production tree** (ranks, runs,
  overproduction, costs, owned blueprints) and a **production plan**: jobs to
  start and materials to buy, with stock deduction and an in-game Multibuy
  export.
- **My plans**: save a tree and its plan to track a long production over
  several days; progress is entered by hand or detected from the industry jobs
  synchronised by SeAT.

The interface is in English by default, with a French translation. SeAT picks
the language set in each user's profile.

## Requirements

- SeAT 5 (Docker or bare metal).
- For structure import: corporations registered with a Director or Station
  Manager token (structures and assets). Structures can also be added by hand.
- Market data synchronised by SeAT (Jita orders and CCP adjusted prices).

## Installation

### Docker

Add the package to the `SEAT_PLUGINS` variable of your SeAT `.env` (comma
separated if you already use other plugins):

```
SEAT_PLUGINS=evedev/seat-indus-planner
```

Then restart the stack:

```bash
docker compose up -d
```

On startup SeAT installs the package, runs its migrations, downloads the three
SDE tables it needs (`industryActivity`, `industryActivityMaterials`,
`industryActivityProducts`) and installs its scheduled commands.

### Bare metal

```bash
composer require evedev/seat-indus-planner
php artisan migrate
php artisan eve:update:sde
php artisan db:seed --class="Seat\Services\Database\Seeders\PluginDatabaseSeeder"
```

### Optional settings (`.env`)

| Variable | Default | Effect |
|---|---|---|
| `INDUS_PLANNER_CONTACT` | `unknown` | contact sent in the User-Agent of public ESI calls |
| `INDUS_PLANNER_PRICE_SYSTEM` | `30000142` (Jita) | reference system for market prices |

## Permissions

- Reactions, Production and My plans are open to every account.
- `indus-planner.manage`: manage structures (add a manual structure, edit rigs
  and facility tax, run the synchronisation). A SeAT administrator always has
  it. Grant it in Settings › Access Management.

## Data sources

| Need | SeAT source |
|---|---|
| Blueprints, reaction formulas | SDE: `industryActivity*` (added by the plugin) |
| Types, groups, volumes, rigs and their bonuses | SDE: `invTypes`, `invGroups`, `dgmTypeAttributes` |
| Corporation industrial structures | `corporation_structures` (Station Manager / Director token), names from `universe_structures` |
| Fitted rigs | `corporation_assets`, `RigSlot*` flags (Director token) |
| Jita buy / sell prices | `market_orders`, filtered on the reference system |
| Adjusted prices (EIV) | `market_prices.adjusted_price` |
| Skills | `character_skills` |
| Stock | `character_assets`, `corporation_assets` (`CorpSAG1..7` divisions) |
| Owned blueprints | `character_blueprints`, `corporation_blueprints` |
| Hangar roles | `character_roles` (`Hangar_Query_N`, `Hangar_Take_N`, `Director`) |
| Industry jobs (saved plan progress) | `character_industry_jobs`, `corporation_industry_jobs` |
| System cost indices | public ESI `/industry/systems/` (scheduled command) |

## Scheduled commands

- `indus-planner:cost-indices` (hourly): system cost indices.
- `indus-planner:sync-structures` (hourly): industrial structures (Raitaru,
  Azbel, Sotiyo, Athanor, Tatara) and rigs read back from SeAT data, without any
  ESI call. Rigs entered by a manager are never overwritten; a structure that
  disappears from SeAT data is removed.

## Visibility rules

- **Offered structures**: the ones of the corporations of the user's alliances
  (and of their own corporations) known to SeAT, plus manual structures. Only
  the name, system and rigs are shown (no timers, no fuel).
- **Corporation hangars**: a division is only offered when one of the user's
  characters has the in-game `Hangar_Query_N` / `Hangar_Take_N` role for that
  division, or `Director`.
- **Saved plans** are private to their owner.

## Calculation notes

- A structure is only used for an activity it can run (a Tatara is never picked
  for manufacturing, a Raitaru never for a reaction). By default the structure
  with the best TE reduction is used, otherwise the best ME reduction,
  otherwise the first structure able to run the activity.
- The best owned blueprint (BPO first, otherwise the best BPC) prefills the
  product ME/TE and applies to manufactured components, together with the ME
  bonuses of their structure.
- Needs for the same item coming from different branches are consolidated
  before computing runs, so a shared component is not overestimated.

## Limitations

- The SDE downloaded by SeAT may lag behind the game: items added since are
  missing.
- ESI does not expose the structure facility tax: 3 % by default, editable by a
  manager.
- Without a Director token for a corporation, the rigs of its structures are
  unknown ("rigs unknown" badge): a manager can enter them.

## Tests

```bash
docker compose exec front vendor/bin/phpunit -c packages/evedev/seat-indus-planner/phpunit.xml
```

(Path for a development install where the package lives in `packages/`.)

## Support

seat-indus-planner is free and will stay free. If it saves you time, a simple
thank-you message is always appreciated. If you would like to do a bit more,
you can send something in game to the character **eve dev**, entirely optional.

I am open to every suggestion: ideas for improvements, bug reports and help
with debugging are all welcome, in the GitHub issues.

## License

Copyright (C) 2026 EveDev

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 2 of the License, or (at your option) any later
version. See [LICENSE](LICENSE).

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE.

This plugin extends [SeAT](https://github.com/eveseat/seat), itself licensed
under the GNU GPL v2 or later.

## CCP notice

EVE Online and the EVE logo are the registered trademarks of CCP hf. All rights
are reserved worldwide. All other trademarks are the property of their
respective owners. EVE Online, the EVE logo, EVE and all associated logos and
designs are the intellectual property of CCP hf. This plugin is not affiliated
with or endorsed by CCP hf., and CCP hf. is in no way responsible for its
content or functioning. Item icons and character portraits are served by the
CCP image server.
