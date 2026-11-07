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

## Installing on a new server

When the user hands you this repo's URL on a fresh server and asks to set the
system up, follow this checklist. `DEPLOY.md` is the same procedure written for
a person (in Hebrew); the scripts do the work, so don't rebuild their steps by hand.
The user is the business owner, not a developer: talk to her in Hebrew, plainly.

1. **Ask first, in one AskUserQuestion:**
   - A new, empty system, or **moving an existing one with all its data**?
   - The site address (e.g. `crm.example.co.il`), and whether its DNS already points at this server.
   - For an empty system: the email of the first (admin) user.
   - For a move: where the old server is. You need a bundle made there by
     `./move-export.sh` (step 5). If you can't reach the old server, ask her to run it and copy the file over.
2. **Prerequisites:** `docker info` and `git --version` (install per DEPLOY.md §1 if missing).
   Check ports with `ss -ltnp`: `APP_PORT` (default 8090) must be free for the app. If it's taken,
   **before** `install.sh`: `cp .env.example .env` and set `APP_PORT` (and `CONTAINER_PREFIX` if
   containers named `crm_*` already exist, `docker ps -a`). install.sh keeps that `.env` and fills in
   the DB password and APP_KEY. Note who holds 80/443 for step 4: nothing, nginx, or something else.
3. **Install:** `git clone <url> ~/kapaim && cd ~/kapaim && ./install.sh <admin-email> https://<domain>`.
   It builds the containers, installs packages, builds CSS/JS, migrates, seeds the base data
   (`InstallSeeder`) and creates the admin. **It prints the admin password once — give it to the user.**
   Re-running it is safe.
4. **HTTPS:** a reverse proxy in front of `127.0.0.1:$APP_PORT`, as in DEPLOY.md §4.
   Ports 80/443 free → Caddy (option A). nginx already on the host (other sites) → add a
   server block + certbot (option B); never replace the existing sites. Either way keep
   the 100MB body limit (material uploads go up to 95MB) and pass `X-Forwarded-Proto`.
   DNS must point at this server before the certificate is issued. Check that
   `curl -sI https://<domain>/login` returns 200. If Cloudflare proxies the domain, its SSL mode must be **Full**.
5. **Moving data** (only for a move). On the **old** server, `./move-export.sh` stops its
   queue and scheduler, so nothing goes out twice, and writes `backups/kapaim-move_….tar.gz`.
   That file contains the database, `storage/app` and `APP_KEY`.
   Copy it over, then on the new server run `./move-import.sh <file>`. It backs up, swaps in
   the old `APP_KEY`, restores the DB and files, migrates and restarts.
   It ends with `php artisan app:move-summary`, which must report all files present and the SUMIT keys readable.
   For a rehearsal while the old server keeps working: `./move-export.sh --keep-running` there and
   `./move-import.sh <file> --trial` here, which leaves sending off on the new server.
   If the domain is the same as before, switch DNS only after the import.
   Users log in with their old passwords.
6. **Backups:** add the daily cron line from DEPLOY.md §7.
7. **Verify, then report:** `docker compose ps` shows app, nginx, postgres, queue and scheduler all up.
   The login page loads over HTTPS, and you can log in, or the user confirms she can.
   Tell her what's left for her to do in the app (DEPLOY.md §5): on a fresh install,
   business entities, SUMIT and Smove keys, and the catalog. Those settings live in the database,
   not in `.env`, and a move brings them over.

Never on a real server:
- `php artisan db:seed` with no class. That is the demo `DatabaseSeeder`, with users whose password is `password`.
- `migrate:fresh`, `migrate:reset`, `db:wipe`.
- Running the tests after `config:cache` without `config:clear` first. `RefreshDatabase`
  could then hit the real database.

Gotchas:
- **`.env` changes:** containers read `.env` only when created, so run `docker compose up -d --force-recreate`.
- **Route cache:** if routes were cached (`php artisan route:cache`), a newly pulled route doesn't exist
  until you re-cache or `optimize:clear`. `update.sh` runs `optimize:clear`.
- **Running as root:** `install.sh` hands the folder to uid 1000. PHP never runs as root.
- **Email:** all outgoing email goes through Smove (settings → integrations). `MAIL_MAILER=log` is intentional.
- **Updating later:** `./update.sh` (backup, pull, build, migrate, restart).
