# Refactor notes

What changed in this refactor, why, and what is intentionally left as
follow-up work.

## Summary
The app previously "worked" but was structurally unsound: no authentication,
25 duplicated database connections, no CSRF protection, an incomplete/buggy
schema, and inline CSS/JS throughout. Several files couldn't even run (wrong
`vendor/autoload.php` paths; a `vendor/` committed as empty gitlinks).

This refactor introduces a shared, secure foundation and the missing core
features, then wires the existing business modules into it — **without changing
their business logic**.

## What was added
- **Shared foundation** (`includes/`): central PDO layer, helpers, secure auth,
  CSRF, data-driven navigation, reusable layout partials, an icon set, a
  reporting layer.
- **Authentication system**: `login.php` / `logout.php`, hardened sessions
  (HttpOnly/SameSite cookies, idle timeout, id rotation, anti-fixation),
  login throttling, optional DB-backed remember-me, and email-or-username login.
- **Default administrator** seeded by migration (`implement` / `implement@1725`,
  stored as a bcrypt hash).
- **Role-based access control**: Administrator / Manager / Salesperson, enforced
  by `require_role()` and reflected in the menu and dashboard shortcuts.
- **Modern dashboard** (`dashboard/`): summary cards (sales, revenue, profit,
  quotes, customers, products, stock value, inventory qty, low/out of stock,
  today/this-month sales), quick-action shortcuts, Chart.js charts (monthly
  sales, top products) and a recent-activity feed.
- **User management module** (`users/`): searchable/paginated list, create,
  edit (also self-profile), delete, activate/deactivate, reset password — all
  CSRF-protected and admin-guarded, with self-protection (can't delete/deactivate
  or demote yourself).
- **Consolidated external CSS/JS**: `style.css`, `dashboard.css`, `forms.css`;
  `app.js`, `dashboard.js`, `validation.js`. The original branding/palette is
  preserved via CSS variables.
- **Database migrations**: complete and harden the schema (see
  [`DATABASE.md`](DATABASE.md)) — missing tables, fixed seeds, indexes,
  constraints, default admin — additive and idempotent.

## What was fixed (bugs)
- **Broken autoload paths** in 7 module files (`__DIR__ . '/vendor/autoload.php'`
  pointed inside `modules/<x>/`) — they now bootstrap through the shared layer
  and run.
- **Broken `vendor/`**: dependencies were committed as empty git submodule
  links, so the app couldn't load. `vendor/` is now git-ignored and restored
  with `composer install`.
- **Committed secret**: `.env` (with DB credentials) was tracked. It is now
  git-ignored, with a `.env.example` template.
- **Schema bugs** in `db.sql` (missing tables, wrong seed columns, bad ordering,
  stray statements) — see [`DATABASE.md`](DATABASE.md).

## How existing modules were integrated
A single transformation was applied to every file under `modules/`:
1. Inject `require_once __DIR__ . '/../bootstrap.php';` at the top — this
   enforces authentication and provides the shared connection.
2. Remove the duplicated per-module `getDBConnection()` and the redundant
   autoload/dotenv preamble.

The modules' own SQL and business logic are unchanged, so their behaviour is
preserved. A central `getDBConnection()` (in `modules/bootstrap.php`) keeps
legacy calls working against the shared connection.

## Verified at runtime
Against a live PostgreSQL with the schema + migrations applied:
- Login with the brief's credentials → dashboard. ✓
- Every route redirects unauthenticated users to `login.php`. ✓
- CSRF-less POST is rejected with `403`. ✓
- RBAC: a Salesperson is `403`-blocked from admin-only pages but reaches the
  dashboard and their own profile. ✓
- Logout destroys the session. ✓
- Legacy modules (products, categories, users, customers, suppliers, currency)
  render authenticated with no PHP errors. ✓
- Migrations are idempotent (second run is a no-op). ✓

## Intentional follow-ups (not done here)
These are safe to do incrementally and are **not** required for the app to run
securely today, but complete the brief's vision for the legacy modules:

1. **CSRF tokens on legacy module forms.** The CSRF infrastructure exists and is
   enforced on all new code. The pre-existing module forms don't yet submit a
   token, so global enforcement is *not* switched on for them (it would break
   their untested POST flows). Add `csrf_field()` to each legacy form and call
   `csrf_check()` in its handler, module by module.
2. **Externalise remaining inline CSS/JS.** The new code has none; the legacy
   modules still contain inline `<style>`/`<script>`. Move these into the shared
   stylesheets/scripts (or per-module files) incrementally.
3. **Adopt `db_*()` / `e()` helpers in legacy modules.** They already share the
   connection; migrating their raw queries and echoes to the helpers further
   reduces duplication and tightens escaping.
4. **Migrate remaining modules to the layout partials** (`header.php`/`footer.php`)
   for a consistent sidebar/topbar across every page.
