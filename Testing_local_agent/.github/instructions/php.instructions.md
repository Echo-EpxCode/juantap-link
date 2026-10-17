---
name: PHP standards
description: Apply to PHP source and Composer files when the project actually uses PHP.
applyTo: "**/*.php,**/composer.json,**/composer.lock"
---

Read the project's PHP/Composer configuration before relying on a PHP version, framework, runtime, or tool. Follow existing architecture and compatible PSR conventions. Prefer clear names, small functions, explicit errors, parameterized database access, input validation, and context-appropriate output encoding.

Do not install/update Composer dependencies or run scripts that alter project state unless the user has requested or authorized that operation. Do not add a PHP framework just because one is common.
