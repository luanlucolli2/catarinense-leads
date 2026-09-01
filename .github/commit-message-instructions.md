# Commit message instructions

Generate exactly one commit message line.

Use Conventional Commits in this format:

`type(module): concise description`

Rules:

- Write every commit message in English.
- Use lowercase only.
- Do not add a body, bullet points, explanations, emojis, periods, or quotes.
- Keep the message under 72 characters when possible.
- Use an imperative verb in the description.
- Describe the result of the change, not the implementation process.
- Never use vague descriptions such as `update files`, `fix stuff`, `changes`, or `improvements`.

Allowed types:

- `feat`: add a new user-facing capability or business feature.
- `fix`: correct broken or incorrect behavior.
- `refactor`: restructure code without changing expected behavior.
- `perf`: improve performance, memory usage, query efficiency, or processing throughput.
- `test`: add or update automated tests.
- `docs`: update documentation.
- `build`: change build, Docker, Composer, npm, Vite, or deployment configuration.
- `ci`: change GitHub Actions or continuous integration configuration.
- `chore`: maintenance, cleanup, dependencies, tooling, generated files, or non-functional changes.
- `style`: formatting-only changes with no behavior change.

Always include the affected business module as the scope.

Preferred module scopes:

- `leads`
- `clt`
- `fgts-off`
- `lemit`
- `mercantil`
- `presenca`
- `uy3`
- `v8`
- `v8-fgts`
- `vendeai`
- `c6`
- `inovachat`
- `ura`
- `auth`
- `api`
- `frontend`
- `database`
- `docker`
- `repo`

Scope selection rules:

- For changes inside `backend/app/Modules/<Module>`, use that module name as the scope.
- For changes in both frontend and backend related to one business module, use the business module as the scope.
- For shared Laravel controllers, services, middleware, routes, or configuration, use the closest domain scope such as `api`, `auth`, `c6`, `inovachat`, or `ura`.
- For migrations, seeders, database indexes, and schema changes, use `database` unless the migration clearly belongs to one business module.
- For changes limited to `frontend` with no specific business domain, use `frontend`.
- For Dockerfiles, Docker Compose, Supervisor, PHP configuration, or infrastructure files, use `docker`.
- For root repository files, Git settings, editor configuration, or general maintenance, use `repo`.
- When a commit truly changes unrelated modules, use the module with the primary user-facing impact. Do not use multiple scopes.

Examples:

- `feat(lemit): add phone availability filter`
- `fix(v8-fgts): prevent duplicate job item processing`
- `perf(leads): optimize listing query indexes`
- `refactor(clt): isolate credit policy validation`
- `test(uy3): cover webhook payload normalization`
- `docs(api): document lemit pool endpoint`
- `build(docker): update php production image`
- `chore(repo): remove obsolete generated files`
- `fix(frontend): preserve filters after page refresh`
- `feat(c6): add authorization status polling`
