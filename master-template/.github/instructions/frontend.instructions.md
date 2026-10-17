---
name: Master-template frontend standards
description: Apply to frontend source files in the EP-Webcraft master-template project.
applyTo: "**/*.{html,htm,css,js,mjs,cjs}"
---

Follow the static HTML/JavaScript and Tailwind CSS approach already present in this repository. Inspect relevant source, configuration, and `package.json` before changing structure or assuming a tool is available.

Preserve semantic and accessible markup, responsive behavior, keyboard operation, and the existing design tokens in `src/css/theme.css`. Keep data/content changes in the current configuration patterns when applicable. Treat `dist/app.css` as generated output from the existing build scripts; do not edit it by hand unless the user specifically requests that.

Run the `build` or `dev` script only when requested or authorized because each writes `dist/app.css` (and `dev` continues watching files).
