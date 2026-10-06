# M2 — Build, Check and Scan the Runtime Image in CI

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each PR and each push to `main` builds the `runtime` image, checks that it
works in each of the four roles, and scans it with Trivy. It is the last PR of M2.

**Architecture:** A third job, `image`, in `.github/workflows/ci.yml`. It builds the
image with Buildx and the GitHub Actions cache, and loads it into the local Docker of
the runner. The script `scripts/check-image-roles.sh` starts each role against
PostgreSQL and Redis service containers. Trivy writes a full report to the job summary,
then fails the job on HIGH and CRITICAL vulnerabilities that have a fix.

**Tech Stack:** GitHub Actions, `docker/setup-buildx-action`,
`docker/build-push-action`, `aquasecurity/trivy-action`, Bash.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (section 11).

## Global Constraints

- The image is not pushed to a registry.
- One job does the build, the role check and the scan. The image stays on the runner,
  so no job must save and load it.
- The role check uses only environment variables. The mails go to the log
  (`MAIL_MAILER=log`), so CI needs no Mailpit.
- The Trivy gate fails on HIGH and CRITICAL vulnerabilities with a fix
  (`ignore-unfixed`). The report in the job summary shows all severities and secrets.
- Trivy cannot see the npm packages inside the SSR bundle. `npm audit` for these
  packages comes in a separate PR.
- The actions have exact commit SHAs.

## Review Focus

1. **Three results.** The PR shows `ci / lint`, `ci / test` and `ci / image`.
2. **Role check.** The script fails with a clear message and the container logs when a
   role does not work.
3. **Trivy.** The summary of the run shows the Trivy report.

---

### Task 1: Role check script

**Files:**

- Create: `scripts/check-image-roles.sh`

- [ ] **Step 1: Write the script**

Usage: `scripts/check-image-roles.sh <image> <env-file>`. `DOCKER_NETWORK` selects the
network (default `host`). The script checks:

- `migrate` ends with exit code 0.
- `web` becomes healthy, and `/login` contains `data-server-rendered="true"`.
- `worker` runs a queued mail (`Mailable ... DONE` in its log).
- `scheduler` still runs after 10 seconds.

On a failure, it prints the reason and the last log lines of each container. It always
removes its containers.

`scripts/` is not in the allowlist of `.dockerignore`, so the script is not in the
image.

- [ ] **Step 2: Verify locally**

With the local stack running (`make up`), write an env file with the variables of Task 4
of the runtime image plan and `MAIL_MAILER=log`, then run:
`DOCKER_NETWORK=event-booking_default scripts/check-image-roles.sh event-booking:runtime <env-file>`
Expected: "All roles work.", exit code 0.

With `DB_PASSWORD=wrong` in the env file:
Expected: "FAIL: migrate did not end with exit code 0", exit code 1.

### Task 2: The `image` job

**Files:**

- Modify: `.github/workflows/ci.yml`

- [ ] **Step 1: Add the job**

Services: `postgres` (database `event_booking`) and `redis`, with health checks.

Steps:

1. Checkout, then `docker/setup-buildx-action`.
2. `docker/build-push-action`: `target: runtime`, `load: true`, `push: false`,
   `cache-from: type=gha`, `cache-to: type=gha,mode=max`.
3. Write `role-check.env` (a new random `APP_KEY`, `DB_HOST=127.0.0.1`,
   `REDIS_HOST=127.0.0.1`, the Redis drivers, `MAIL_MAILER=log`), then run
   `scripts/check-image-roles.sh`.
4. Trivy report: `scanners: vuln,secret`, all severities, `exit-code: '0'`, output to
   `trivy.txt`. A step writes `trivy.txt` to `$GITHUB_STEP_SUMMARY`.
5. Trivy gate: `severity: HIGH,CRITICAL`, `ignore-unfixed: true`, `exit-code: '1'`,
   `skip-setup-trivy: true`.

- [ ] **Step 2: Verify**

Run: `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest`
Expected: no output, exit code 0.

Run Trivy locally:
`docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:latest image --severity HIGH,CRITICAL --ignore-unfixed event-booking:runtime`
Expected: no HIGH or CRITICAL vulnerabilities.

- [ ] **Step 3: Commit**

```bash
git add scripts/check-image-roles.sh .github/workflows/ci.yml
git commit -m "ci: build, check and scan the runtime image"
```

### Task 3: Documents

**Files:**

- Modify: `docs/superpowers/specs/2026-09-28-event-booking-design.md`
- Modify: `HANDOFF.md`

- [ ] **Step 1: Update section 11 of the spec**

The `build` and `scan` items become one `image` job with the role check. The Trivy rule:
HIGH and CRITICAL with a fix.

- [ ] **Step 2: Update `HANDOFF.md`**

M2 is complete after this PR. The next steps: `npm audit` PR, then M3.

- [ ] **Step 3: Format and commit**

```bash
npm run check:fix
git add docs/ HANDOFF.md
git commit -m "docs: add image CI plan and update handoff"
```

### Task 4: Check the run on the PR

- [ ] **Step 1: After the push, check the run**

Expected: `ci / image` passes. The summary shows the Trivy report. The log of "Check the
four roles" ends with "All roles work.".
