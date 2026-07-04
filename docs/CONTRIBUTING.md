# Contributing Guide

Quick guidelines for contributing to this repository.

## Branching & PRs

- Create feature branches from `development` (e.g., `feature/add-report`).
- Open PRs against `development` with a clear title and description.

## Commits & style

- Use imperative commit messages: `Add X`, `Fix Y`, `Refactor Z`.
- Follow PSR-12 for PHP; run linters and formatters before opening PRs.

## Testing

- Add tests under `tests/` for new features when possible.
- Run test suite locally (if configured) and ensure passing CI.

## Database changes

- Add central migrations to `database/migrations/` and tenant migrations to `database/migrations/tenant/`.
- Keep migrations additive; document any destructive changes in the PR.

## Review checklist

- Does the code include tests or explain manual testing steps?
- Are migrations and seeders included for DB changes?
- Are environment changes (new env vars) documented in `PROJECT_OVERVIEW.md` or `.env.example`?

## CI/CD

- Keep CI focused on linting, tests, and running migrations in a dry-run for safety.
