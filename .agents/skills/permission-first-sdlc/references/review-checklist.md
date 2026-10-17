# Review checklist

## Scope and correctness
- [ ] Change matches the user's request and project conventions.
- [ ] Existing user work is preserved; no unrelated rewrite or dependency was added.
- [ ] Root cause, edge cases, and failure paths were considered.

## Security, privacy, and accessibility
- [ ] No secrets, credentials, private data, or unnecessary personal data were added.
- [ ] Input is validated and output encoded at appropriate boundaries.
- [ ] Database operations are parameterized where appropriate.
- [ ] Semantic and accessible behavior is considered for user-facing UI.

## Verification and reporting
- [ ] Any checks were requested/authorized, appropriate, and reported accurately.
- [ ] Generated or accidental files are identified where status/diff inspection was authorized.
- [ ] Findings distinguish confirmed defects from suggestions and limitations.
