# Use this setup in VS Code

## Start in EP-Webcraft

1. In VS Code, choose **File → Open Folder** and open `EP-Webcraft` when your task might touch more than one folder. This workspace root has its own `AGENTS.md` and `.github` agent profiles.
2. If you are working only on the website template, you can open `EP-Webcraft/master-template` directly. Its `AGENTS.md` and frontend instructions describe that project's actual HTML/JavaScript/Tailwind setup.
3. In Chat, select the **Copilot** harness if you want to use the personal customizations installed under `~/.copilot/`. Other harnesses may need the skill installed in their own supported user location.
4. Open **Chat: Open Customizations** from the Command Palette. Confirm `permission-first-sdlc`, the personal agent profiles, and the EP-Webcraft workspace instructions are discovered. Use Chat diagnostics if any are missing.

## Work through a task

1. Start an exploratory task with **Planner** and ask for evidence and a plan only. Example: “Inspect the mobile navigation in `master-template`. Explain its current behavior and propose a small fix. Plan only; do not edit or run commands.”
2. For implementation, select **Developer**. State the exact files and expected behavior. A clear direct request to implement a bounded change is authorization; you do not need to approve it twice. If you already approved a Planner's exact plan, tell Developer to implement that plan.
3. Review the VS Code diff and Git panel. Check that only intended files changed and that existing work remains intact.
4. Select **Verifier** when you want checks. Ask it to identify existing checks and their side effects first. Do not run checks unless you requested or authorized them.
5. Select **Reviewer** for an independent critique of the actual diff. It should return findings without editing.
6. Ask for a short report of changed files, checks actually run, outcomes, and limitations. Keep dependency changes, database/data mutations, Git history/remote actions, uploads, and deployments separately authorized.

You can invoke the reusable skill directly by typing `/permission-first-sdlc` in Chat, then adding the task. The skill can also load automatically when the task matches its description.

## EP-Webcraft project notes

- EP-Webcraft is one Git repository. Check status from the EP-Webcraft root; do not create nested repositories for client sites or the template.
- The old `master-template` history is preserved in the root Git database under `refs/archive/master-template/heads/main`, and its original Git metadata is archived under `.git/history-backups/master-template-original`. The previous `origin` remote is not configured for the combined workspace; configure a new remote only after choosing the combined repository destination.
- In `master-template`, `npm run build` writes `dist/app.css`; `npm run dev` writes that file and keeps watching. Ask before running either if the user hasn't requested or authorized the check.
- The files in `Testing_local_agent/` are a copy of the reusable setup and documentation. The active EP-Webcraft instructions are in the workspace root and `master-template/`; the nested copy is a reference package.

## Use in another project

The personal skill, personal instructions, and four agents are installed in the current Windows user's Copilot folders, so they can be reused in other VS Code workspaces using the Copilot harness. Each new project still needs its own confirmed `AGENTS.md` or workspace instructions for its stack and architecture. Keep the workflow skill generic; do not copy EP-Webcraft's project rules into unrelated projects.
