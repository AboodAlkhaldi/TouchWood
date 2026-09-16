# TouchWood Platform

`docs/HANDOFF.md` is the source of truth. Read it before changing anything.

- §2 rules and the §16 rejection table are decided. Do not re-propose what they reject.
- §15 items are open. Ask the owner when you reach one; never invent an answer.
- The owner's latest word overrides the handoff; update the handoff when that happens.

## How work proceeds

1. A module is specified in `docs/modules/{name}.md` (the nine sections in handoff §18)
   and reviewed by the owner **before** its first line of code.
2. Build order is handoff §17. Platform, then Access, then B2B, then Feedback.
3. `composer check` must pass before a commit: pint → phpstan → deptrac → pest.

## Layout

- Domain code lives in `src/`, never `app/`. `app/` is Laravel bootstrap only.
- `src/Modules/{Name}/{Public,Domain,Application,Infrastructure,Presentation}` — see
  `docs/STRUCTURE.md`. Other modules may import only `Public/`.
- `src/Shared/` is the kernel, ~20 classes hard ceiling.
- Tests: `tests/Modules/{Name}/{Unit,Integration,Feature}`, `tests/Architecture`.
  Integration and Feature tests run against PostgreSQL (`touchwood_test`), never SQLite.

## Local environment (Windows)

- `docker compose up -d --wait` starts Postgres 17 on port 5433 and Redis on 6379.
- Redis client is `predis` (no phpredis on Windows).
