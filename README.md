<p align="center">
  <a href="https://www.bulsca.co.uk"><img src="storage/app/public/logo/blogo.png" width="180" alt="BULSCA logo"></a>
</p>

# BULSCA website API and administration

[![Release tests](https://github.com/BULSCA/bulsca-site/actions/workflows/api-tests.yml/badge.svg?branch=release%2Fpublic-site-v1&event=push)](https://github.com/BULSCA/bulsca-site/actions/workflows/api-tests.yml?query=branch%3Arelease%2Fpublic-site-v1)
[![Line coverage snapshot: 25.81%](docs/badges/line-coverage.svg)](https://github.com/BULSCA/bulsca-site/actions/runs/40870156982)
[![API coverage snapshot: 98.05%](docs/badges/api-coverage.svg)](https://github.com/BULSCA/bulsca-site/actions/runs/40870156982)

Laravel backend for BULSCA's website. It supplies the public Vue site's versioned API and retains the existing administration for news, clubs, committees, competitions, results and resources.

The public frontend lives in [BULSCA/bulsca-frontend](https://github.com/BULSCA/bulsca-frontend). The first release keeps website, API and administration at `www.bulsca.co.uk`, `api.bulsca.co.uk` and `portal.bulsca.co.uk`, with Podman deployment instructions below.

## Tests and coverage

The live CI badge follows `release/public-site-v1` and requires the PHP unit/feature suite, real Laravel/Vue API integration and container configuration checks. For an unmerged PR, open its **Checks** tab to inspect that candidate's coverage.

<!-- coverage-snapshot:start -->
Measured on **29 October 2026** in [CI run 40870156982](https://github.com/BULSCA/bulsca-site/actions/runs/40870156982) for commit [`66f58b0`](https://github.com/BULSCA/bulsca-site/commit/66f58b03c22dba33b6169f2a6589216085664509). These are snapshot percentages; the CI badge and PR Checks link to current reports.

| Category | Line coverage | Application code included |
| --- | ---: | --- |
| Public API | 98.05% | Versioned API controllers and response resources |
| Middleware and validation | 39.57% | Middleware, requests, exceptions and shared sanitisation/DTO helpers |
| Services | 41.38% | Public resource access, provider integrations and token storage |
| Models and domain | 30.45% | Database models and competition/league logic |
| Legacy web and authentication | 5.76% | Existing website, administration and authentication controllers |
| Console and scheduling | 11.49% | Artisan commands and the scheduler |
| Other application code | 27.17% | Providers, mail, notifications and remaining application code |
| **Total** | **25.81%** | All application code, including uncovered legacy features |
<!-- coverage-snapshot:end -->

Each CI run publishes current category measurements in its **Backend checks and full PHPUnit suite** summary.

Download **backend-coverage** from that run and open `html/index.html` for file/line detail. Clover XML and category summaries are included; coverage artifacts are retained for 30 days. Coverage summaries are generated for each tested commit.

To collect coverage locally, use PHP 8.3 with PCOV and the isolated test database described below:

```sh
mkdir -p test-results/coverage
vendor/bin/phpunit --coverage-clover test-results/coverage/clover.xml --coverage-html test-results/coverage/html
python3 tools/coverage_summary.py test-results/coverage/clover.xml
```

All `app/` source files are included, including untested legacy features. Categories group source files, not test suites. PCOV reports line coverage; it does not supply branch/path coverage. The separate browser integration suite is not added to these percentages. Missing or invalid reports fail CI; coverage measurement is required without a minimum-percentage gate. Local reports stay in ignored `test-results/`.

## Backend pull request checks

`.github/workflows/api-tests.yml` runs Composer validation/install, PHP syntax and the full PHPUnit suite on PHP 8.3 with isolated MySQL 8.4 services. `Required CI` also requires public API integration and container configuration checks, and fails when a prerequisite fails, is cancelled or is skipped. JUnit results are retained for seven days. PR creation/updates/drafts/retargeting, default/release pushes and merge queues are covered.

For local tests, use `phpunit.xml` with a dedicated local MySQL database ending in `_test`, `APP_ENV=testing`, `DB_URL` empty and test-only credentials. The guard rejects nonlocal hosts, unsafe database names and a database matching the application `.env` before migrations run. Run `composer validate --strict --no-check-publish`, `composer install` and `vendor/bin/phpunit`; CI syntax checks cover app/tests/database/routes/config. Never supply application database credentials to the test command.

GitHub rulesets require PRs and an up-to-date `Required CI` from GitHub Actions on the default branch and `release/` branches (including nested release names), with no bypass actors or mandatory human approvals. Force pushes and deletion are blocked. These settings live in GitHub, independently of workflow files. Existing PRs without the workflow need updating before merge. CODEX development branches remain pushable. No assets/containers are built and nothing is deployed by these checks.

## Public website API and launch handover

The public Vue site now consumes `/api/v1` for clubs/committee profiles, competition calendars/details, archived championship years, A/B/historical overall standings, resource categories/search/downloads, SERCs/casualties, homepage presentation/notices/social updates, contact submissions and article interactions. `docs/api/openapi.json` owns the wire contract. `tools/generate_api_types.py` generates the public frontend types; use `--check` to detect drift. Public responses expose allowlisted fields. Rich content is sanitized with the same allowlist as articles. GET requests do not create competition info or increment content counters.

Resources remain on the existing filesystem. Only resources linked from published pages, SERCs, competitions or archived championship content are listed/accessed by the new API. Access returns an absolute download URL; storage paths never reach the browser. File delivery uses an attachment response with nosniff. Historical `/resources/view/{id}` links remain available. `resources/data/championships.json` preserves the published archival text/links; additional years can be added without another Blade route. `config/public_site.php` holds presentation/campaign settings and preserves the published Champs 2027 announcement. Existing global-banner administration is exposed through `/notice`. Existing league calculations and their administration remain authoritative.

For contact submissions configure `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` and the existing mail transport/from address. `CONTACT_FORM_ENABLED=false` disables online submissions while leaving department email links available. Captcha verification and email are never performed by the browser. Failed/uncertain sends are not retried automatically. Configure a writable non-database `PUBLIC_API_CACHE_STORE` (file by default) for interaction deduplication and cached social updates. Social provider failures retain marked cached results for up to 24 hours, back off for a minute and log safe context. Article view/rating events use UUID event IDs, 24-hour deduplication and atomic counters without changing publication timestamps. Contact, view and rating rate limits use separate buckets.

Deployment checks belong to the owner. The paired `release/public-site-v1` branches contain the previous implementation stacks; review container work through `CODEX/` PRs targeting those releases. Default branches remain unchanged and nothing is merged automatically. The new portal/authentication migration is deferred. Verify real records/archives, media, PDF downloads, captcha/mail delivery and management links. Current privacy/cookie/terms gaps require owner-approved text; none has been invented. Builds, production migrations, database/file transfers and cutover are operator actions described below.

### Real API integration checks

The frontend's `scripts/ci-api-integration.sh` starts a Vue dev server, Chrome and Laravel against seeded isolated SQL data. `tests/Support/public-api-bootstrap.php` refuses unsafe environments/databases before seed/reset or HTTP handling, uses temporary cache/files, and fakes external HTTP/mail. The test-only router can inject an actual Laravel failure; no fault injection exists in product routes. The suite checks public pages at six widths, real downloads/contact/ratings, safe failures and recovery. Each repository's CI tests its PR against a pinned counterpart commit; update that reference when coordinating contract changes. Browser reports/screenshots, safe diagnostics and server logs are retained as CI artifacts. Nothing is built or deployed.

## Podman release deployment (operator-run)

Use sibling `bulsca-site` and `bulsca-frontend` checkouts of the reviewed release commits on a Linux host with rootless Podman 5+, `podman-compose` 1.5.0 and an existing HTTPS reverse proxy. `release/public-site-v1` is the target in both repositories; only release branches omit the `CODEX/` prefix. Review the focused commits in each implementation PR and merge them into the releases yourself. Neither default branch is a release target.

`Containerfile` has `runtime` (PHP 8.3) and `web` (unprivileged Nginx) targets. Compose adds the compiled Vue frontend, MySQL 8.4 and one Laravel scheduler. Backend/web images must use the same backend commit. The application account is distinct from database root. Compose publishes only `127.0.0.1:8080` (Vue) and `127.0.0.1:8081` (backend web); PHP and MySQL remain on the container network. The frontend can serve its shell while the backend is unavailable, without retrying proxied writes.

### Configuration and images

Copy `containers/release.env.example` to `.env.release`, set mode 600, and fill in the two exact Git commits as image tags plus your credentials. Preserve the existing `APP_KEY`. Single-quote values containing `$`, `#` or spaces in this dotenv file. Never commit it or save expanded `compose config` output containing production secrets. `containers/compose.sh` selects the Podman Compose provider and this env file; `RELEASE_ENV_FILE` can select a separate staging configuration.

`APP_URL` is the portal origin; `PUBLIC_API_URL` is the API origin. Sessions are host-scoped, secure, HttpOnly and SameSite=Lax. Register `https://portal.bulsca.co.uk/auth/sso/callback` with the current identity provider. Configure Turnstile for the website hostname and SMTP before enabling contact submissions. Test with `CONTACT_FORM_ENABLED=true` on staging first; the example leaves production submissions disabled until accepted. Social integration is optional. Its initial token comes from the environment; weekly refresh stores subsequent tokens encrypted under `storage/app/private/meta-token.enc`. Both readers and the scheduler use this file, and refresh failures retain the previous token. Replacing an expired/corrupt token requires stopping the scheduler, setting a fresh initial token and removing the old encrypted token file before restarting. Never publish or serve this private directory.

The following commands build images and are for the owner to run later:

```sh
containers/compose.sh build frontend backend backend-web
containers/compose.sh pull database
```

Vite values are fixed during the frontend image build. The image always uses live data, relative `/api/v1` consumers and the configured portal origin. Rebuild it when those settings change. No `.env`, uploaded files, local dependencies, test seeders or fault-injection routers are copied into production images. Runtime startup regenerates configuration using supplied secrets; it never generates keys, migrates or seeds a database.

### Existing data and MySQL 8.4 rehearsal

Before moving data, record the source engine/version (`SELECT VERSION()`), pending migrations, table engines, database size and uploaded-file locations. Export the application database only, including routines/triggers/events, using a source-compatible logical dump tool. Retain the existing database and a verified off-host backup. Do not mount its physical data directory into MySQL 8.4 or import database system users. Follow the [supported MySQL upgrade path](https://dev.mysql.com/doc/refman/8.4/en/upgrade-paths.html); older MySQL versions may need intermediate upgrades, and MariaDB exports require a separately verified compatibility rehearsal.

Use fresh named volumes for the rehearsal. The defaults below must match `.env.release`; staging should use different volume/project names, loopback ports, hostnames and network subnet. Update `TRUSTED_PROXIES` to match that subnet and the actual proxy peer addresses.

```sh
podman volume create bulsca-mysql-v1
podman volume create bulsca-storage-v1
containers/compose.sh up -d --no-build database
containers/restore-db.sh /secure/path/source-application.sql.gz --confirm-empty-database
```

Restore refuses a nonempty target database and stops on SQL errors. A failed partial import requires another fresh volume. Keep application writers stopped during restoration. Compare table counts, representative row counts, relationships, league calculations and pending migrations with the source before continuing. Rehearse imports and any required migrations on a copy first; run `containers/compose.sh run --rm --no-deps backend php artisan migrate --force` only as an explicit reviewed deployment step. Never use `migrate:fresh` for a release database.

Create an archive of the **contents** of the old Laravel `storage` directory (including hidden files and all `app` files) and import it into the fresh storage volume with `podman volume import bulsca-storage-v1 /secure/path/storage.tar`. Align the final SQL/files snapshot while old application writers are stopped. Set ownership on this copied volume only:

```sh
containers/compose.sh run --rm --no-deps --user 0 --entrypoint chown backend -R 33:33 /var/www/html/storage
containers/compose.sh up -d --no-build
```

PHP and the scheduler mount writable shared storage; the web container mounts it read-only and exposes only `storage/app/public`. Named volumes use Podman's labeling; do not relabel or recursively chown the live source tree. External volumes survive Compose teardown. Back them up independently of images. `containers/backup-db.sh /secure/path/release.sql.gz` creates a compressed, mode-600 application dump, refuses existing destinations and removes partial output on failure. Also back up storage consistently, the existing key and the deployment configuration. Rehearse restoring these backups, not just creating them.

### HTTPS proxy and acceptance

Route `www.bulsca.co.uk` to loopback 8080 and both `api.bulsca.co.uk` and `portal.bulsca.co.uk` to loopback 8081. Preserve the hostname and overwrite client-supplied forwarding headers with the real client address, canonical host and HTTPS scheme. Keep the application ports bound to loopback. The internal web proxy forwards these headers through the configured trusted proxy chain; check real client IPs so visitors do not share one rate-limit bucket. Allow uploads up to 34 MiB through the host proxy. The API hostname serves public API/media/download routes; portal login and management routes are handled only at the portal hostname.

`/health/live` checks application boot without database/cache dependencies. `/health/ready` checks SQL connectivity, writable runtime paths and cache read/write, returning only `ok` or a safe `503 unavailable`. `/health/web` checks Nginx itself. Vue's `/health/live` stays healthy during backend outages. Health endpoints never call mail, captcha or social providers.

With Node 24 available, run the read-only acceptance tool after deployment:

```sh
PUBLIC_SITE_ORIGIN=https://www.bulsca.co.uk \
PUBLIC_API_ORIGIN=https://api.bulsca.co.uk \
PORTAL_ORIGIN=https://portal.bulsca.co.uk \
node tools/smoke_release.mjs
```

It verifies deep-link/static serving, missing assets, same-origin and direct API requests, readiness, portal routing and a real published document download. Empty resource lists explicitly report a skipped download check and require manual coverage. Manually check existing images, championship archives, real SSO/session/logout, representative management edits, league tables and captcha/mail delivery. Verify graceful public pages and server diagnostics during a staging backend/database outage, recovery, container replacement and a host reboot. Missing approved privacy/cookie/terms text remains a launch content gap.

### Reboot recovery and rollback

Adapt the checkout location in `containers/bulsca.service`, install it as a user systemd unit, enable it with `systemctl --user enable --now bulsca.service` and enable lingering for the deployment user. The service starts already-built images and stops containers without deleting volumes. Check `containers/compose.sh ps`, `containers/compose.sh logs --tail=100` and `systemctl --user status bulsca.service` during the reboot rehearsal. Diagnostics persist under `storage/logs`; PHP/Nginx process errors also appear in container logs.

Record the exact frontend/backend commits, resulting image IDs/digests (including the MySQL image), configuration identifier, source/target DB versions, backup locations and acceptance results before declaring the candidate ready. Keep previous images and configuration. Freeze writes for final data transfer/cutover and confirm acceptance before reopening them. To roll back application images, restore the previous paired tags/configuration and recreate services with `up -d --no-build`; verify health and smoke checks. Database/schema rollback requires the rehearsed backup and matching files/key, not an image downgrade on the new data directory. Once new writes are accepted, reconcile them before switching to an old database.

### Configuration checks without builds

`bash containers/check.sh` requires the sibling frontend, Node 24, Python/PyYAML, `podman-compose` 1.5.0, Podman, Nginx and ShellCheck. It verifies a checksum-pinned Hadolint binary, shell scripts, Compose normalization with fake secrets, real Nginx routing against temporary fixtures/fake FastCGI, and smoke-tool tests. CI runs these as `Container configuration`, required by `Required CI` in both repositories. No containers are started and no assets or images are built by these checks. Built-image and deployment verification remain owner-run gates.

## Organisation portal API

The standalone Vue portal in [admin-panel](https://github.com/BULSCA/admin-panel) uses this backend as the authority for people, memberships, committee appointments and permissions. The additive `portal_*` tables do not migrate or grant access from legacy `user_universities`, public committee biographies or Backpack roles. Review existing committees and appoint people explicitly. BULSCA is seeded as an association; clubs affiliate to it and can optionally link to an existing public university/club. One person can belong to several organisations.

### Authentik sign-in for the member portal

Register a confidential Authentik OAuth2/OpenID application with the exact callback `https://<portal-host>/auth/sso/callback`. Configure `AUTHENTIK_BASE_URL`, `AUTHENTIK_ISSUER` (the exact issuer in that application's discovery document), `AUTHENTIK_CLIENT_ID`, `AUTHENTIK_CLIENT_SECRET` and `AUTHENTIK_REDIRECT_URI` on Laravel. Use a server-side session driver. Set `SANCTUM_STATEFUL_DOMAINS` to the portal hostname, including the port in development, and set `AUTHENTIK_ENABLED=true` when ready to test. Laravel requests the `openid`, `profile` and `email` scopes.

Existing identities use the exact issuer and subject. First-time linking by email requires a verified claim and a unique matching account. Authentik's default email scope returns `email_verified=false` in recent versions. Check the claim against your verification process or explicitly map reviewed accounts before cutover. The [reconciliation guide](docs/authentik-reconciliation.md) describes the dry run and reviewed import. See [Authentik email verification](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/verify-email-address/).

The portal starts login at `/auth/sso?portal=1`, reads the signed-in identity at `GET /api/v1/me`, and obtains a CSRF cookie at `GET /sanctum/csrf-cookie` before writes. The identity response includes the backend user ID, current legacy roles, effective permissions and club IDs, plus organisation-scoped permissions for the portal. `POST /api/v1/portal/logout` clears the Laravel session and attempts to revoke the Authentik access token. Authentik may maintain its own browser session across applications. Legacy site sign-in and previously stored numeric SSO IDs remain available during migration.

After applying the normal migrations, have the first administrator sign in through Authentik. An operator can then run `php artisan portal:bootstrap-superadmin person@example.org` for that existing account. This explicit, repeatable command grants the existing global `super_admin` role, BULSCA membership and an initial manager appointment. It never creates an identity-provider account. Superadmins should add other BULSCA members and assign committee roles; signing in alone grants no membership or permissions. Subsequent users must also sign in once before a manager adds their registered email. Email invitations and identity-provider account provisioning are not included.

Committee appointments grant permissions only while their organisation, membership, appointment and committee term are active. Dates are inclusive in Europe/London. Club permissions are `branding.manage`, `members.manage`, `committees.manage` and `roles.manage`. BULSCA can additionally grant `clubs.manage`, which explicitly authorises management of affiliated clubs. Mere BULSCA membership conveys no club management. The protected Organisation manager role provides the organisation's full catalogue; custom roles can delegate only permissions the actor currently holds in that same organisation. Club roles cannot grant `clubs.manage` or global superadmin access. Appoint a replacement current manager before ending the last manager's appointment/membership/term. Deactivation revokes old appointments; reactivation requires explicit new appointments to restore powers. Historic roles cannot be deleted while appointments reference them.

Private endpoints are under `/api/v1/portal`, using the `web` session, authentication, CSRF protection, throttling and `private, no-store` responses:

| Endpoint | Operations |
| --- | --- |
| `/csrf`, `/me`, `/logout` | GET CSRF cookie, GET account/organisations, POST sign out |
| `/organisations` | GET central directory; POST affiliated club with known initial manager |
| `/organisations/{organisation}` | GET scoped member search/pagination, committees, roles and effective permissions |
| `…/members`, `…/members/{membership}` | POST existing member; PATCH membership active status |
| `…/committees`, `…/committees/{committee}` | POST committee; PATCH its name/dates |
| `…/roles`, `…/roles/{role}` | POST custom role; PATCH permissions/name; DELETE unused role |
| `…/appointments`, `…/appointments/{appointment}` | POST explicit committee appointment; DELETE revokes it |
| `…/branding` | POST multipart six-digit hex primary/secondary colours and optional PNG/JPEG/WebP logo (2 MB, 2048×2048 maximum) |

Successful responses use a `data` envelope; reads return permissions for interface controls, while every write independently checks authority under an organisation lock. Tenant-scoped validation and composite foreign keys prevent mixing members, roles or committees across organisations. Outsiders receive 404; members without a required permission receive 403. Ordinary directories show names and role titles; only member managers receive contact emails and inactive memberships. No public route exposes portal membership data. An active organisation linked to a legacy public club can override its public directory logo; colours remain available to the portal without changing the public API contract.

The portal must reverse-proxy `/api/`, `/auth/sso` (including its callback), `/sanctum/`, `/storage/` and `/img/` to this backend on the portal origin, preserving cookies and request methods. Do not proxy private requests through the public website's cookie-stripping API proxy. Keep `APP_URL` and the registered SSO callback on the portal origin, `PUBLIC_API_URL` on the public API origin, and production cookies secure/host-scoped. The new Vue portal uses `/auth/sso?portal=1`; authentication failures return to its sign-in screen. The existing release container still serves legacy portal pages; serving the new Vue assets at the portal hostname is a separate owner-run cutover. Do not enable both prototype authentication backends.

Administration audit events store actor/record IDs, permission names and action codes, never request bodies, emails, tokens or IP addresses. `portal:prune-audit` removes events older than 90 days and runs daily through the Laravel scheduler; ensure the deployment scheduler is active. Diagnostic reports use the existing bounded, cookie-free `/api/v1/client-errors` endpoint. No analytics or new tracking cookies are introduced. The SSO callback now requires a single-use nonempty state, regenerates the authenticated session and avoids logging credentials or provider bodies.

`tests/Feature/Api/PortalTest.php` and `PortalAuthenticationTest.php` cover tenant isolation, delegation, CSRF, revocation, term dates, manager handover, branding/storage errors, audit retention and SSO. They run as part of the existing guarded MySQL PHPUnit suite. Deployment, real provider configuration and production data onboarding remain operator checks.

## Acknowledgments

BULSCA's website reflects years of work by its maintainers and volunteers:

- **[James O'Donohoe](https://github.com/jaykay351)** — ongoing maintenance and modernisation of the website, including the Vue frontend and API separation.
- **Noah Hollowell** — previous maintainer. Thank you for the time, care and development work invested in the original BULSCA website and the foundation it provides for the site's future.

Thank you also to the volunteers who maintain the site's content, competition information and resources for the university lifesaving community.

### Open source acknowledgments and licence notices

Built with [Laravel](https://laravel.com/), created by Taylor Otwell and maintained by its contributors, alongside Vue, Backpack and other open source libraries. Their work makes this project possible.

Laravel is MIT-licensed. Its full copyright and permission notice is preserved in [NOTICE.laravel](NOTICE.laravel), and dependency notices remain with their respective packages. This framework notice does not assign a new licence to BULSCA's own application code, content or branding.
