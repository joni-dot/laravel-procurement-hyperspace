# Contributing

Thank you for helping. This page explains how we work, so your change can be merged without surprises.

## Before you start

- **Security problems** are not reported in issues. Follow [SECURITY.md](SECURITY.md).
- **Bigger changes:** open an issue first, so we can agree on the approach before you write the code.
- **Never commit secrets or real data.** This repository is public. Use the demo seeders and invented companies, suppliers and people only. Never commit real company, supplier or personal data, `.env` files, keys or tokens.

## Sign your commits (DCO)

We use the [Developer Certificate of Origin](https://developercertificate.org) (DCO) instead of a contributor licence agreement. Add a sign-off line to every commit:

```bash
git commit -s -m "fix(contracts): show the right end date"
```

The `-s` flag adds `Signed-off-by: Your Name <your@email>` to the commit message. With it, you certify that:

- you wrote the change, or have the right to submit it, and
- you agree that it is released under this project's licence (AGPL-3.0-only), and
- you understand that the contribution and your sign-off are public and kept permanently.

Read the full text at https://developercertificate.org. A maintainer checks for the sign-off before merging. To sign earlier commits, run `git rebase --signoff main` and force-push your branch.

## Branches and commits

- Create one branch per change from an up-to-date `main`, named `<type>/<short-name>`, for example `feat/audit-log` or `fix/reminder-date`.
- Write commit messages in the [Conventional Commits](https://www.conventionalcommits.org) format, `type(optional scope): short imperative summary`, for example `feat(suppliers): add archive filter`. Common types are `feat`, `fix`, `docs`, `refactor`, `test`, `chore` and `ci`.
- Keep commits small and logical. Split unrelated changes into separate commits.

## Running the app and the checks

You only need Docker and git. Every PHP and Node command runs through [Laravel Sail](https://laravel.com/docs/sail):

```bash
./vendor/bin/sail up -d                  # start everything
./vendor/bin/sail composer ci:check      # all checks; must pass before a pull request
./vendor/bin/sail composer lint          # fix PHP style
./vendor/bin/sail pnpm run check:fix     # fix frontend style and lint
tests/Browser/run.sh                     # real-browser tests, run from the host
```

`ci:check` runs the frontend checks, TypeScript, Pint, PHPStan and all tests. The README explains setup and the browser tests.

## What we expect in a change

- **Security first.** Check permissions on the server, never show user input as HTML, and add no inline scripts. Follow the security rules in the README. Data a role may not see is left out of the server's response, not just hidden in the page.
- **Performance.** Avoid repeated queries in lists, and test new filters against the demo data.
- **Readable code.** Use clear names and small functions. Comments explain _why_, not _what_.
- **Tests.** New behaviour comes with tests. A new page or user flow also needs a browser test in `tests/Browser`.
- **Records are kept.** Archive or deactivate instead of deleting, unless the README says otherwise.

## Pull requests

- Open a pull request against `main`. Say what changes, why, and how you tested it, and include a short note on security and performance.
- The **`ci`** check (the same `composer ci:check` plus the browser tests) must pass before a merge.
- A maintainer reviews and merges. Nobody pushes to `main` directly.

## Licence

By contributing you agree that your work is released under the [GNU Affero General Public License v3.0 only](LICENSE).
