# Setup and reuse guide

## Choose the right scope

| Content | Recommended scope | Example |
|---|---|---|
| Personal teaching preferences and general working style | User instructions | `~/.copilot/copilot-instructions.md` for the Copilot harness |
| Repeatable workflow used across projects | User skill | `~/.copilot/skills/permission-first-sdlc/SKILL.md` |
| Project architecture, stack, scripts, and boundaries | Workspace instructions | `AGENTS.md` and/or `.github/copilot-instructions.md` at that project root |
| Rules for particular languages or files | Targeted instructions | `.github/instructions/frontend.instructions.md` |
| Planner, Developer, Reviewer, and Verifier roles | User agents for reuse, workspace agents for a shared team workflow | `~/.copilot/agents/` or `.github/agents/` |

The exact locations depend on the selected VS Code harness. Copilot supports personal skills under `~/.copilot/skills/`, personal agents under `~/.copilot/agents/`, and personal instructions under `~/.copilot/instructions/` or `~/.copilot/copilot-instructions.md`. Other harnesses can use different locations. Use the Agent Customizations editor to create user-level customizations when possible.

## Install into a project

1. Open the intended project folder in VS Code and select the harness you plan to use.
2. Read existing `AGENTS.md`, `.agents/`, `.github/`, `.vscode/`, manifests, and project documentation. Check Git status in each relevant repository.
3. Add only the project-specific instruction files that fit the project. Merge existing files instead of replacing them.
4. Do not copy user-level preferences into each project. Keep the reusable skill and personal roles at user scope when you want them available everywhere.
5. In Chat, open **Chat: Open Customizations** and inspect the selected harness's discovered agents, instructions, and skills. Use its diagnostics if an item is missing.
6. Ask the Planner for a read-only diagnosis and scoped plan. When you directly request a bounded implementation, the Developer can act within that request; do not require a second approval for the same scope.
7. Review the diff. Run existing checks only when requested or otherwise authorized, and account for their side effects (for example, a build may rewrite generated files).
8. Use the Verifier and Reviewer for their focused roles. Treat their results as evidence to assess, not as guarantees.
9. Keep dependency changes, database/data changes, Git history/remote operations, uploads, and deployment as separate, explicit approvals unless the user has already authorized that exact operation.

## VS Code permissions and trust

- Select a harness supported by your installed VS Code version and check that harness's current customization and permission documentation.
- Review terminal/tool approval prompts and command arguments. Do not enable broad auto-approval or a bypass for convenience.
- A workspace trust decision can permit local code or hooks to run. Trust only workspaces you understand.
- Instruction files and custom agent profiles guide model behavior; they are not security boundaries. Rely on the selected harness's actual tool permissions and sandbox status for execution controls.
- Do not assume Local-harness settings, hooks, or sandbox behavior apply to Copilot, Claude, or Codex sessions.

## Rollback

Remove only the personal or workspace customization files introduced by this setup. Preserve user edits and unrelated project files. Review Git status and the diff before undoing anything; do not use broad reset or clean commands as a shortcut.
