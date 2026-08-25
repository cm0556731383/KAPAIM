# kapaim CRM

Laravel 13 + Livewire + Tailwind CSS v4 + PostgreSQL, run via Docker Compose.

Planning documents (source of truth — read before changing behavior):
- `docs/prd.md` — product requirements (FR/US numbered)
- `docs/erd.md` — database entities/relationships
- `docs/stack.md` — technical stack reference
- `docs/brand-guidelines.md` + `assets/` — כפיים design system (design tokens, RTL Hebrew)
- `docs/build-plan/` — ordered build stages (00 infrastructure → 19 hardening/QA); each stage file has scope, DB entities, business rules, dependencies, and Definition of Done
- `docs/storyboard/` — HTML mockups of each screen

Local dev: `docker compose up -d`, app served at `http://127.0.0.1:8090` (8080 was already taken by another process on this host).
