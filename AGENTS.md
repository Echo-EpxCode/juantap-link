# EP-Webcraft workspace guidance

This is the EP-Webcraft Git repository. It contains `clients/`, `master-template/`, and `Testing_local_agent/`. Inspect the target project before making assumptions about a client site or how it relates to the template.

- Use the EP-Webcraft root for Git status and history. Do not initialize a nested repository inside `master-template/` or a client folder unless the user explicitly changes this one-repository setup.
- Preserve the existing project structure and each site's current stack. Do not assume a framework, runtime, database, or deployment target.
- Keep changes scoped to the requested site/project. Do not treat the template and client sites as interchangeable without evidence.
- For master-template specifics, follow [its project instructions](./master-template/AGENTS.md).
- Use the `permission-first-sdlc` skill for evidence-based work. Follow direct user authorization without asking for duplicate approval; request separate authorization for consequential operations outside the scope already granted.
- Do not add or run tests/checks unless requested or authorized. Report exact results and side effects when checks are run.
