# Master-template project guidance

This project is part of the EP-Webcraft root Git repository. It is a static HTML/JavaScript site that currently uses Tailwind CSS 4 through the CLI. Confirm the current files and `package.json` before relying on this summary.

- Preserve the existing architecture and avoid adding a framework or dependency unless the user asks and authorizes it.
- Relevant source areas include `index.html`, `src/`, and `config/`. Inspect how the current page consumes sections, components, and configuration before changing their shape.
- `src/css/theme.css` is the Tailwind input. The existing `build` and `dev` scripts write `dist/app.css`; `dev` watches for changes. Do not run them unless requested or authorized, and review generated-file changes afterward.
- Check Git status from the EP-Webcraft workspace root and preserve existing edits.
- Follow the `permission-first-sdlc` skill. Do not request duplicate approval for a clear change the user directly requested; obtain separate authorization for consequential actions outside that scope.
- Do not add or run tests/checks unless requested or authorized. Be precise about what was checked and whether it generated files.
