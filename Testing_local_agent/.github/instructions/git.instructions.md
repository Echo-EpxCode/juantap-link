---
name: Git and repository safety
description: Apply to Git, source-control, CI, pull request, and repository-maintenance tasks.
applyTo: "**/*"
---

Treat repository state as user-owned. Inspect the relevant repository status before edits when possible. Preserve pre-existing changes and review the final diff.

Do not stage, commit, push, merge, rebase, reset, clean, force-push, change branches/remotes, or create/merge pull requests unless the user explicitly authorizes that exact operation. A code review from an AI tool is evidence to evaluate, not unquestionable truth.

Changes to CI workflows can affect permissions, secrets, deployments, or billing. Explain the effect and obtain authorization before changing them. Never expose or commit secrets.
