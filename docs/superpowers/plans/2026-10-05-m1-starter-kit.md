# M1 — Install the Laravel React Starter Kit

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the official Laravel React starter kit to the repository, without changes.

**Architecture:** The Laravel installer generates the starter kit in a temporary
directory. Then the files are copied into the repository. The generated code goes in one
commit, so that a reviewer can skip it. All later changes to the starter kit come in
separate PRs (see M1 in `milestones.md`).

**Tech Stack:** Laravel 13, Inertia 3, React 19, TypeScript, Tailwind, shadcn/ui,
Fortify, Wayfinder, Pest, Pint, Larastan, Laravel Boost.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 2 and 3).

## Global Constraints

- PHP 8.5, Composer 2.9, Node 24, npm 11 (the versions installed on the host).
- Use the Laravel installer with the flags `--react`, `--pest`, `--npm` and `--boost`.
  These are official installer options. Boost is part of the default installation. The
  flag makes sure that the installer does not skip it.
- Keep the default database of the starter kit (SQLite). PostgreSQL comes in a later PR.
- Do not change any generated file by hand in this PR.
- Do not commit `.env`, `vendor/`, `node_modules/`, `public/build/` or
  `database/database.sqlite`. The `.gitignore` of the starter kit ignores them.
- Do not commit `.codex/config.toml`. Boost writes it with absolute paths of the machine
  that runs the installer. The `.gitignore` of the starter kit does not ignore it. A
  later PR adds it to `.gitignore`.

## Review Focus

1. **Files that already exist in the repository.** The `.gitignore` and the `README.md`
   of the starter kit replace ours. The new `.gitignore` still ignores `/.idea`. The
   installer removes the `README.md`, so it comes from the `laravel/react-starter-kit`
   repository.
2. **Our documents.** `docs/` and `HANDOFF.md` must stay unchanged after the copy.
3. **Secrets and machine files.** `.env` (with `APP_KEY`) and `.codex/config.toml`
   must not be in the commit.
4. **The GitHub workflow of the starter kit.** `.github/workflows/tests.yml` runs on
   push and on pull requests. It must pass on this PR.
5. **Lock files.** `composer.lock` and `package-lock.json` must be in the commit, so
   that each install gets the same versions.

---

### Task 1: Generate the starter kit and add it to the repository

**Files:**

- Create: all the files of the starter kit, at the root of the repository.
- Modify: `.gitignore` and `README.md` (replaced by the starter kit versions).

**Interfaces:**

- Consumes: nothing.
- Produces: a Laravel app at the repository root. Later PRs use `composer.json`,
  `package.json`, `.env.example`, `database/migrations/`, `app/Models/User.php` and
  `tests/`.

- [ ] **Step 1: Generate the starter kit in the scratchpad**

```bash
cd "$SCRATCHPAD"
laravel new event-booking --react --pest --npm --boost --no-interaction
```

Expected: the installer ends with `"success":true`. `composer.json` contains
`laravel/boost`. `node_modules/` and `public/build/` exist.

- [ ] **Step 2: Run the tests of the starter kit in the scratchpad**

```bash
cd "$SCRATCHPAD/event-booking"
php artisan test
```

Expected: all tests pass.

- [ ] **Step 3: Copy the files into the repository**

Copy the files that git tracks, except `.codex/`. Then get the `README.md` of the
starter kit.

```bash
cd "$SCRATCHPAD/event-booking"
git init -q && git add -A
git ls-files | grep -v '^\.codex/' | tr '\n' '\0' | rsync -a --from0 --files-from=- ./ "$REPO"/
gh api repos/laravel/react-starter-kit/contents/README.md --jq .content | base64 -d > "$REPO/README.md"
```

Expected: `git -C "$REPO" status` shows the new files, and changes only in
`.gitignore` and `README.md`. `docs/` and `HANDOFF.md` have no changes.

- [ ] **Step 4: Make sure that no ignored, secret or machine file is staged**

```bash
cd "$REPO"
git add -A
git restore --staged docs/superpowers/plans/
git diff --cached --name-only | grep -E '^(\.env$|vendor/|node_modules/|public/build/|database/database\.sqlite$|\.codex/)' || echo "clean"
```

Expected: `clean`.

- [ ] **Step 5: Install the dependencies in the repository and run the checks**

```bash
cd "$REPO"
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm ci
npm run build
php artisan test
```

Expected: all tests pass. The build ends without errors.

- [ ] **Step 6: Make sure that the app starts**

```bash
php artisan serve --port=8000 &
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/login
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/register
kill %1
```

Expected: `200` for each URL.

- [ ] **Step 7: Commit the starter kit**

```bash
git commit -m "chore: install Laravel React starter kit"
```

The commit has only the files of the starter kit.

- [ ] **Step 8: Install the local Boost files**

```bash
echo "/.codex/" >> .git/info/exclude
php artisan boost:install --no-interaction
```

Expected: `AGENTS.md`, `boost.json`, `.mcp.json`, `.claude/` and `.agents/` exist on the
local machine. `git status` shows no new files. Each developer runs this command on
their own machine.

### Task 2: Update the handoff

**Files:**

- Modify: `HANDOFF.md`

- [ ] **Step 1: Update "Where we are" and "Next steps"**

"Where we are": M1 started. The starter kit is installed (Laravel 13, Fortify,
Wayfinder, Pest, Boost). "Next steps": the next M1 PR, our changes to the starter kit.

- [ ] **Step 2: Commit**

```bash
git add HANDOFF.md docs/superpowers/plans/2026-10-05-m1-starter-kit.md
git commit -m "docs: add starter kit plan and update handoff"
```
