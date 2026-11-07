# Contributing to Linvara

Thanks for taking the time. Bug reports, fixes, translations of the plugin's
own interface and documentation improvements are all welcome. This guide
covers how to set up, the conventions the code follows, and how a change gets
merged.

Before a bigger change, please open an issue first to agree on the approach;
it saves you from building something that does not fit.

## Set up

You need PHP 7.4+, Composer, Node.js 20+, WP-CLI, and a local WordPress 6.9+
site with MySQL or MariaDB (InnoDB). Any local setup works: wp-env, Local,
DDEV, or your own Docker stack.

```bash
cd wp-content/plugins
git clone https://github.com/aminesayagh/lingowp.git
cd lingowp
composer install
npm ci
npm run build
```

Activate **Linvara** in that site and go through the short setup once.
[ARCHITECTURE.md](ARCHITECTURE.md) explains where things live.

While working:

- `npm run start` rebuilds the admin app on every change.
- `npm run watch:switcher` does the same for the front-end switcher.
- Set `WP_DEBUG` and `WP_DEBUG_LOG` to `true`: a change should add nothing to
  `wp-content/debug.log`.

## Tests

| Command | What it checks | Needs WordPress |
|---|---|---|
| `composer test` | PHP unit checks (`tests/*.php`) | no |
| `npm test` | JS unit checks (`tests/*.mjs`) | no |
| `npm run lint:js` | ESLint, including the admin app's data-fetching rule | no |
| `composer compat:php` | PHP 7.4+ compatibility (PHPCompatibility) | no |
| `composer security` | WPCS security sniffs, dependency audit, gitleaks, Semgrep (needs Docker) | no |
| `composer test:db` | Integration tests (`tests/db/*_it.php`) against a real site | yes |

**Integration tests** run inside your WordPress through WP-CLI. They open a
database transaction and roll it back, and they refuse to run unless
`WP_ENVIRONMENT_TYPE` is not `production` and the plugin's schema is
current. Still, point them at a development site only.

`composer test:db` calls `./wp`, a small wrapper for the maintainer's Docker
stack (WP-CLI in a Compose service named `wordpress`). With any other setup,
run a test file with your own WP-CLI from the plugin folder:

```bash
wp eval-file tests/db/reconcile_it.php
```

Each file prints `PASS` / `FAIL` lines and ends with `All N assertions
passed.` A bug fix should come with a test that fails without the fix.

## Conventions

**Code**

- Prefer a readable flow you can follow in one file over layers of
  indirection. No containers, factories or registries unless they solve a
  real problem here; `Setup\Plugin` wires plain objects by hand.
- Comments explain *why*, next to the rule they justify. Keep the style of the
  file you are editing.
- PHP follows the WordPress Coding Standards' security sniffs
  (`phpcs.xml.dist`). A deliberate exception carries a `phpcs:ignore` with its
  reason.

**WordPress.org rules a change must respect** (the plugin is distributed
there, and these are checked in review):

- Escape output where it is returned or echoed; sanitize input at the REST
  boundary; every REST route needs a capability check.
- Write files only under `wp-content/uploads/linvara/`, through
  `wp_upload_dir()`, with paths built from validated values.
- Never download or install code; never contact an external service without
  the user's explicit consent.
- Never lock features that run on the site behind a licence. Only the cloud
  service may be paid.

**Admin app** (`admin-ui/`)

- Every server call goes through a React Query hook in `admin-ui/queries/`.
  Reads are `useQuery` / `useInfiniteQuery`, writes are `useMutation` and
  invalidate the affected keys from `admin-ui/queryKeys.js`.
- Only `admin-ui/queries/` may import the raw client `admin-ui/api.js`;
  ESLint fails the build otherwise.

**Compatibility**

- Don't change how text is normalized or hashed (`Shared/Text/`): Linvara
  Cloud computes the same hash, and existing translations are found by it.
- Schema changes bump `TranslationMemorySchema::DB_VERSION`, and must keep
  existing translations.

## Propose a change

1. Fork the repository and branch from `dev`.
2. Keep each pull request to one topic. Run the checks above that apply.
3. Write commit messages in the style already used:
   `feat: …`, `fix: …`, `refactor: …`, `build: …`, `docs: …`, `test: …`,
   saying what changed for a user or a maintainer, not which file.
4. Open the pull request against **`dev`**, describing the problem, the
   change, and how you tested it. `dev` is merged into `master` for releases.

## Releases (maintainers)

`bin/deploy.sh` is the one release command. It runs every check above,
builds `dist/linvara-<version>.zip` (with the human-readable source of both
compiled bundles), then tests that exact archive: a clean activation with
`WP_DEBUG`, WordPress.org's Plugin Check, and a multisite lifecycle check.
The same archive is uploaded to WordPress.org and attached to the GitHub
release.

## Reporting security issues

Please don't open a public issue. See [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contribution is licensed under the
plugin's license, GPL-2.0-or-later.
