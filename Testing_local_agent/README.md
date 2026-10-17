# Reusable VS Code agent workflow

This folder contains reusable agent roles, project instruction examples, and the `permission-first-sdlc` skill. Keep personal preferences and the workflow skill separate from each project's stack and architecture rules.

## Recommended use

1. Install `permission-first-sdlc` as a **User Skill** in VS Code's Agent Customizations editor, or copy its folder to a supported personal skill location such as `~/.copilot/skills/permission-first-sdlc/` for the Copilot harness.
2. Put your general teaching and collaboration preferences in personal instructions, not in a project's instructions.
3. Add only confirmed project facts to that project's `AGENTS.md` and/or `.github/copilot-instructions.md`.
4. Use the Planner for diagnosis and planning, the Developer for authorized implementation, the Verifier for approved checks, and the Reviewer for a read-only critique.
5. In VS Code, open Agent Customizations for the selected harness and confirm discovery. Instructions and skills guide the model; they do not replace the harness's tool permissions or sandbox controls.

See [docs/SETUP.md](docs/SETUP.md) for installation details and [docs/USE-IN-VSCODE.md](docs/USE-IN-VSCODE.md) for the step-by-step workflow. VS Code's supported locations vary by harness; follow the current official documentation before installing customizations.

## Contents

- `.github/agents/` — optional role profiles.
- `.github/instructions/` — stack-specific instruction examples; include only those that match a project.
- `.agents/skills/permission-first-sdlc/` — portable, risk-aware workflow and checklists (also discovered by current Copilot harnesses).
- `examples/personal-copilot-instructions.md` — optional starting point for user-level preferences; it is not active as a workspace instruction here.
- `docs/` — setup, validation, and platform notes.
- `agent-acceptance-test/` — a separate disposable test repository, excluded from this package's Git history.

The root `index.html` is a separate demonstration page, not part of the customization kit.

Do not copy every example into every project. Choose the narrowest scope that matches the customization: personal for reusable preferences/workflows, workspace for project facts, and targeted instructions for file-specific rules.
