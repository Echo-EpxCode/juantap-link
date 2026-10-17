# Windows and harness compatibility

The workflow files are Markdown and do not require a particular programming language runtime. Project work still depends on the tools actually present in the target project and on the selected VS Code agent harness.

VS Code's harnesses have different customization locations, command tools, trust behavior, and sandbox controls. Windows Local-harness sandbox support has changed; do not rely on older Local-only settings as a security boundary. Before using this package:

1. Check the installed VS Code version and the current official system requirements for your Windows release.
2. Check the current documentation for the selected harness and its permissions/sandbox status.
3. Review each requested terminal or external tool action and its arguments. Keep broad auto-approval/bypass controls disabled.
4. Use only workspaces you trust. Hooks or project scripts may execute code.
5. If no effective sandbox is available, understand that requiring confirmation reduces accidental actions but does not provide OS-enforced isolation.

Recheck these details after VS Code or harness updates. The instructions and profiles in this package guide an agent; they cannot guarantee the agent will obey them or enforce a tool restriction.
