---
name: Frontend standards
description: Apply to frontend source files; inspect the project's actual framework, styling, build, and browser support first.
applyTo: "**/*.{html,htm,css,scss,sass,js,mjs,cjs,ts,tsx,jsx,vue,svelte}"
---

Follow the frontend approach already present in this project. Inspect its manifests and configuration before assuming a framework, icon library, CSS system, or build tool.

Prefer semantic HTML, accessible labels and names, keyboard operation, visible focus, sufficient contrast, responsive layouts, and progressive enhancement where applicable. Preserve existing patterns and avoid unnecessary dependencies or mixing icon systems. Validate untrusted input at appropriate boundaries; client-side checks are not a security boundary.

Follow the `permission-first-sdlc` skill. Run browser or automated checks only when requested or authorized, and never claim a browser check that did not occur.
