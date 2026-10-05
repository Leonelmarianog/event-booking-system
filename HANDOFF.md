# Handoff — Event Booking Demo

Last updated: 2026-10-05

## Where we are

System design phase. No application code exists yet.

- The design spec is approved as a first version:
  `docs/superpowers/specs/2026-09-28-event-booking-design.md`
- Repository: `git@github.com:Leonelmarianog/event-booking-system.git` (public).
- We now write the detailed system design together with the owner, one section per PR.

## Next steps

1. Write the system design in `docs/design/`, one small PR per section:
   1. Glossary and business rules.
   2. C4 level 1 (system context).
   3. C4 level 2 (containers).
   4. ERD, with space for future features.
   5. Sequence diagrams, one per action, a few actions per PR.
   6. Future features: payments, PDF tickets, file uploads.
2. When the owner approves the design, write the implementation plan in
   `docs/superpowers/plans/`. Split the plan into small PRs.
3. The owner reviews the plan and selects how to execute it.

Do not scaffold the project, install dependencies or write app code before steps 1–3
are complete.

## Working rules (from the owner)

- Work on branches. Do not commit directly to `main`.
- Keep each PR small, with one topic, so that the owner can review it quickly.
- Use semantic commit messages and PR titles (`feat:`, `fix:`, `docs:`, `chore:`, `ci:`,
  `refactor:`, `test:`).
- Write PR descriptions in plain text, with the same structure every time.
  Each description tells clearly what the PR changes.
- Do not add "Generated with Claude Code" or "Co-Authored-By" lines to commits or PRs.
- The owner must own and understand the code. Design each part with the owner before
  you write code.
- Write all design documents in Simple English (ASD-STE100 rules).
- Write diagrams in Mermaid, inside Markdown files. GitHub shows them in the PR.

## Decisions already made (do not reopen)

| Topic | Decision |
|---|---|
| Goal | Portfolio demo. Shows development and DevOps practices. The app itself is secondary. |
| App | Event booking with limited seats (free tickets). |
| Stack | Laravel + Inertia + React (TypeScript), official React starter kit, session auth. |
| Structure | Plain Laravel layout, everything under `app/`. No repositories. |
| Business logic | Rich domain model: rules live in Eloquent models and enums. Actions in `app/Actions` control the flow (transactions, locks, dispatch). Controllers do HTTP only. |
| Runtime | Nginx + PHP-FPM in one container (supervisord). |
| Database | PostgreSQL. Redis for queue, cache, sessions and rate limits. |
| Deployment | One Docker image with roles `web`, `worker`, `scheduler`, `migrate`. The owner supplies all external services. Docker Compose for local development. |
| CI | GitHub Actions: lint, test, build and push to GHCR, Trivy scan, concurrency check. |
| Scope of v1 | Free tickets, no payments. Any user can organize and book. One `is_admin` flag for moderation. Max 4 seats per booking. One active booking per user per event. Email verification is off. |

## Local environment

Docker 27.5 with Compose v2.32, PHP 8.5.1, Composer 2.9.3 and Node 24.13 are installed
(WSL2, Linux).
