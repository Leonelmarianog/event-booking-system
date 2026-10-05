# M1 — Starter Kit Cleanup

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove two problems that the starter kit leaves, and update the M1 scope in
`milestones.md`.

**Architecture:** No application code changes. One ignore rule, one file removal, and a
documentation update.

**Tech Stack:** Git, npm, Laravel Boost.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (section 2).

## Global Constraints

- The project uses npm. `package-lock.json` is the only JavaScript lock file.
- `composer ci:check` must pass before the changes go to review.

## Review Focus

1. **`.npmrc` stays.** It has `ignore-scripts=true`, which npm also uses. Only the pnpm
   workspace file goes.
2. **No Boost file is tracked.** After the change, `git status --ignored` lists
   `.codex/` as ignored by `.gitignore`.
3. **The milestones match the decisions.** `is_admin` moves to M3 and `anonymized_at`
   moves to M6. The quality tools come with the starter kit.

---

### Task 1: Ignore the Codex configuration of Boost

**Files:**

- Modify: `.gitignore`

- [ ] **Step 1: Add the rule next to the other AI tool rules**

Add `/.codex` after the line `/.claude` in `.gitignore`.

- [ ] **Step 2: Make sure that git ignores `.codex/`**

```bash
git check-ignore -v .codex/config.toml
```

Expected: `.gitignore:<line>:/.codex	.codex/config.toml`.

- [ ] **Step 3: Commit**

```bash
git add .gitignore
git commit -m "chore: ignore Boost Codex configuration"
```

### Task 2: Remove the pnpm workspace file

**Files:**

- Delete: `pnpm-workspace.yaml`

- [ ] **Step 1: Delete the file and run the checks**

```bash
git rm pnpm-workspace.yaml
npm ci
npm run build
composer ci:check
```

Expected: the build and all checks pass.

- [ ] **Step 2: Commit**

```bash
git commit -m "chore: remove pnpm workspace file"
```

### Task 3: Update the M1 scope and the handoff

**Files:**

- Modify: `docs/superpowers/plans/milestones.md`
- Modify: `HANDOFF.md`

- [ ] **Step 1: Update `milestones.md`**

- M1 scope: remove the `users` columns. Replace the quality tools line with "The
  quality tools of the starter kit (Pest, Pint, Larastan, `vp check`, `tsc --noEmit`)
  run in CI."
- M3 scope: add "The column `is_admin` on `users`."
- M6 scope: add "The column `anonymized_at` on `users`."

- [ ] **Step 2: Update `HANDOFF.md`**

"Next steps": the order of the next M1 PRs (local stack with PostgreSQL, then Redis
and the removal of the unused migrations, then the `Makefile`).

- [ ] **Step 3: Format and commit**

```bash
npx vp fmt docs/superpowers/plans HANDOFF.md
composer ci:check
git add docs/superpowers/plans HANDOFF.md
git commit -m "docs: update M1 scope and handoff"
```
