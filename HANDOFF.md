# Handoff — Event Booking Demo

Last updated: 2026-10-05

## Where we are

Milestone M1 (Foundation) has started. The design is complete.

- The Laravel React starter kit is installed without changes: Laravel 13, Inertia 3,
  React 19, Fortify, Wayfinder, Pest, Pint, Larastan, Laravel Boost. The database is
  still SQLite. The `README.md` is the one of the starter kit.
- Boost files are local to each machine (`AGENTS.md`, `boost.json`, `.mcp.json`,
  `.claude/`, `.agents/`, `.codex/`). Run `php artisan boost:install --no-interaction`
  after a fresh clone. Use the Boost guidelines and skills to write Laravel code.
- `.codex/` is excluded only in `.git/info/exclude` on this machine. The next PR adds
  `/.codex` to `.gitignore`.
- The starter kit includes `.github/workflows/tests.yml`. It runs the tests on each PR.
- The design spec is approved as a first version:
  `docs/superpowers/specs/2026-09-28-event-booking-design.md`
- Repository: `git@github.com:Leonelmarianog/event-booking-system.git` (public).
- The system design is in `docs/design/`. The milestones are in
  `docs/superpowers/plans/milestones.md`.

### Design progress

| #   | Section                                                                                                                             | Status                                                            |
| --- | ----------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| 1   | Glossary                                                                                                                            | Done                                                              |
| 2   | Business rules                                                                                                                      | Done                                                              |
| 3   | C4 level 1: system context                                                                                                          | Done                                                              |
| 4   | C4 level 2: containers                                                                                                              | Done                                                              |
| 5   | ERD, with space for future features                                                                                                 | Done                                                              |
| 6   | Sequence diagrams                                                                                                                   | Moved to implementation: one `README.md` in each Action directory |
| 7   | Future features, in three PRs: (a) payments and refunds, (b) PDF tickets and cover image, (c) event change emails and announcements | (a) and (b) done. (c) in review (PR #10)                          |

## Decisions from the design sessions

- Visitors can see published events. Booking requires a login.
- Nobody can edit or cancel a started event.
- Attendees can see a cancelled event.
- The organizer can change the start time to a time in the future. No notification in v1.
- No "past" event status.
- The reminder for an event goes out one time only (`reminder_sent_at`).
- Admins can see draft and cancelled events of all users.
- C4 diagrams show future external systems and connections with dashed lines.
- C4 diagrams use Mermaid flowcharts in C4 style, not the Mermaid C4 syntax.
- C4 level 2: the Web Frontend is a separate container. Migrate is shown as a one-off
  task. Redis is one container with four uses. Vite and Mailpit are not shown.
- ERD: one diagram with v1 and future tables. Future tables end with "(future)".
  Laravel tables `password_reset_tokens` and `failed_jobs` are in the diagram.
  No `sessions`, `cache`, `cache_locks`, `jobs` or `job_batches` tables (Redis).
- PDF tickets (future): one `tickets` row per seat, each with a check-in code.
  One PDF per booking.
- Payments (future): one price per event, in cents, with a currency code. New booking
  status `pending_payment`. A `payments` table with one row per payment attempt.
- File uploads (future): only the event cover image. No `files` table. The path is in
  `events.cover_image_path`. The PDF path is in `bookings.tickets_pdf_path`.
- Every use case is an Action, for reads and for writes. Controllers call one Action
  and contain no queries.
- Each Action has its own directory directly under `app/Actions/` (no group folders).
  The directory holds the class and a `README.md` with the sequence diagrams.
- The Action README is written in the same PR as the Action code, never before.
  One diagram for each outcome. No `alt` blocks. No diagrams for future features.
- Payments (future): Stripe Checkout in test mode. Seats held 30 minutes (the minimum
  time of a Stripe Checkout page). The price changes only while the event is a draft.
  A late payment gets an automatic refund.
- Refunds (future): full refund when the event is cancelled, or when the attendee
  cancels 7 days or more before the start. Later cancellations free the seats with no
  refund. Refunds are tracked as `refund_pending`, then `refunded` or `refund_failed`.
  Admins see failed refunds.
- PDF tickets (future): the Worker makes the PDF when the booking is confirmed.
  Attached to the confirmation email and downloadable from "My bookings" (attendee
  only). One page per seat with a QR code. On cancel, the tickets are cancelled and
  the PDF is deleted. Ticket check-in is out of scope.
- Cover image (future): JPEG, PNG or WebP, max 2 MB. The organizer can
  replace or remove it until the event starts or is cancelled. No resize.
- Event change emails (future, agreed, PR (c)): a change of start time or venue emails
  all attendees with a confirmed booking, after the commit.
- Announcements (future, agreed, PR (c)): the organizer writes a subject and a
  plain-text body. Emailed to attendees with a confirmed booking, and shown on the event
  page to the organizer, the attendees and admins. Only for published events, until
  24 hours after the start. Max 3 for each event each day. No approval step.
- Announcements cannot be edited or deleted. An admin can hide one (`hidden_at`,
  `hidden_by_id`). Hidden announcements are not shown to attendees.
- The organizer can delete a draft event. No other deletes. No soft deletes (BR-E17).
- Account deletion: blocked while the user has upcoming published events or confirmed
  bookings. Otherwise the system deletes the drafts and anonymizes the user row
  (BR-U1 to BR-U5, `users.anonymized_at`).

## Next steps

1. Next M1 PR: our changes to the starter kit. PostgreSQL and Redis, removal of the
   unused migrations (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`), and
   the columns `is_admin` and `anonymized_at` on `users`. Also add `/.codex` to
   `.gitignore`, and remove `pnpm-workspace.yaml` (the project uses npm). Write its
   plan first and get the owner's approval.
2. Then the rest of M1: Docker image (`dev` target), `compose.yaml`, quality tools,
   `Makefile`.

Execution: Claude writes each PR. The owner reviews. Tests come first, and each test
name gives its rule ID.

Do not scaffold the project, install dependencies or write app code before the owner
approves the design and the plan.

## Working rules (from the owner)

- Work on branches. Do not commit directly to `main`.
- Keep each PR small, with one topic, so that the owner can review it quickly.
- Use semantic commit messages and PR titles (`feat:`, `fix:`, `docs:`, `chore:`, `ci:`,
  `refactor:`, `test:`).
- Write PR descriptions in plain text, with the same structure every time.
  Each description tells clearly what the PR changes.
- Do not add "Generated with Claude Code" or "Co-Authored-By" lines to commits or PRs.
- Do not push or open a PR before the owner approves the local changes. Commit on a
  local branch, show the changes, and wait.
- Each `git push` asks the owner for a code. Tell the owner before each push.
- The owner must own and understand the code. Design each part with the owner before
  you write code.
- Write all design documents in Simple English (ASD-STE100 rules).
- Format Markdown with `npm run check:fix` before you commit. CI runs `vp check`, and it
  checks the Markdown files too.
- Write diagrams in Mermaid, inside Markdown files. GitHub shows them in the PR.
  Render each diagram locally (`npx -y @mermaid-js/mermaid-cli`) before you push it.
- Ask open questions in chat. Do not put open questions in a PR or a document.
- Work on one PR at a time. Always branch from an up-to-date `main`. Do not stack PRs.
- Update this handoff in the same PR as the work. Then stop.
- Wait for the owner. The owner reviews, merges, and then asks you to sync.
- When you sync, pull `main` and make sure that the merged content is on `main`.
  Then start a new branch.

## Decisions already made (do not reopen)

| Topic          | Decision                                                                                                                                                                                                                                |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Goal           | Portfolio demo. Shows development and DevOps practices. The app itself is secondary.                                                                                                                                                    |
| App            | Event booking with limited seats (free tickets).                                                                                                                                                                                        |
| Stack          | Laravel + Inertia + React (TypeScript), official React starter kit, session auth.                                                                                                                                                       |
| Structure      | Plain Laravel layout, everything under `app/`. No repositories.                                                                                                                                                                         |
| Business logic | Rich domain model: rules live in Eloquent models and enums. Each use case, read or write, is one Action in its own directory `app/Actions/<Name>/`. Actions control the flow (transactions, locks, dispatch). Controllers do HTTP only. |
| Runtime        | Nginx + PHP-FPM in one container (supervisord).                                                                                                                                                                                         |
| Database       | PostgreSQL. Redis for queue, cache, sessions and rate limits.                                                                                                                                                                           |
| Deployment     | One Docker image with roles `web`, `worker`, `scheduler`, `migrate`. The owner supplies all external services. Docker Compose for local development.                                                                                    |
| CI             | GitHub Actions: lint, test, build and push to GHCR, Trivy scan, concurrency check.                                                                                                                                                      |
| Scope of v1    | Free tickets, no payments. Any user can organize and book. One `is_admin` flag for moderation. Max 4 seats per booking. One active booking per user per event. Email verification is off.                                               |

## Local environment

Docker 27.5 with Compose v2.32, PHP 8.5.1, Composer 2.9.3 and Node 24.13 are installed
(WSL2, Linux).
