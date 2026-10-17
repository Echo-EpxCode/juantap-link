---
name: Verification standards
description: Apply when discussing tests, builds, linting, static analysis, and validation.
applyTo: "**/*"
---

Inspect existing scripts and tools before proposing checks. State what behavior a check covers and whether it writes generated files, installs packages, accesses a network service, or mutates data.

Do not add or run tests/checks unless the user asks or authorizes them. Use existing project checks when appropriate. Record the exact check, actual outcome, and exit code when available; distinguish passed, failed, blocked, and not run. Inspect the resulting status/diff when authorized, and never claim browser, integration, database, or deployment testing without evidence.
