# DTS-DRDS — Deployment & Production Hardening Guide

This document covers what to change before this system touches real data,
plus a map of the finished system and its known limitations.

---

## 1. Initial Setup

1. Import the schema:
   ```bash
   mysql -u root -p < schema.sql
   ```
2. Copy the `relief-dts/` folder to your web root (Apache/Nginx + PHP 8.0+,
   `pdo_mysql` and `fileinfo` extensions enabled).
3. Edit `config/config.php`:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — real credentials, not `root`/blank.
   - Create a dedicated MySQL user scoped to `dts_drds` only (not `root`).
4. Confirm `uploads/documents/` and `uploads/manifests/` are writable by the
   web server user (`chown www-data:www-data uploads -R` on Debian/Ubuntu).
5. Log in with a seed account (`admin` / `Passw0rd!123`) and **immediately
   change the password** via User Management → Reset Password, or replace
   the seed hash before go-live.

---

## 2. Required Changes Before Production

These are not optional polish — the app is intentionally left open in a
few spots for local development convenience. Close them before deploying:

| # | File | Change |
|---|------|--------|
| 1 | `config/config.php` | Set `error_reporting(0)` / `ini_set('display_errors', '0')`. Stack traces must never reach the browser. |
| 2 | `config/config.php` | Confirm the app is served over HTTPS so `session.cookie_secure` actually activates (it's conditional on `$_SERVER['HTTPS']`). |
| 3 | `config/config.php` | Change `DB_USER`/`DB_PASS` to a least-privilege MySQL account. |
| 4 | `schema.sql` seed data | Replace or remove the 3 demo accounts (`admin`, `drms_user`, `logistics1`) — same password hash on all three. |
| 5 | Web server config | Deny direct HTTP access to `/config/`, `/classes/`, `/includes/`, and `schema.sql` (see Apache/Nginx snippets below). `uploads/.htaccess` already blocks script execution there, but Nginx doesn't read `.htaccess` — see note below. |
| 6 | `download.php` | Currently any authenticated user can download any attachment (shared-records model). If you need per-department restriction, add a check against `document.origin_department_id` / `current_holder_id` before streaming. |

### Apache
`.htaccess` already ships in `uploads/`. Add a root-level rule (or vhost
block) denying the sensitive folders:
```apache
<FilesMatch "\.(sql)$">
    Require all denied
</FilesMatch>
<DirectoryMatch "/(config|classes|includes)/">
    Require all denied
</DirectoryMatch>
```

### Nginx
Nginx ignores `.htaccess`. Replicate both rules directly in the server block:
```nginx
location ~ ^/(config|classes|includes)/ { deny all; return 404; }
location ~ \.sql$ { deny all; return 404; }
location ~ ^/uploads/.*\.php$ { deny all; return 404; }
```

---

## 3. What's Already Hardened

No action needed here — listed so you know it's covered:

- **Passwords**: `password_hash()` / `password_verify()` (bcrypt), never plaintext.
- **SQL injection**: 100% prepared statements via PDO, `ATTR_EMULATE_PREPARES` off.
- **CSRF**: every state-changing endpoint (all 21 POST handlers in `/ajax/`)
  calls `csrf_protect()`; verified in this session — see consistency check below.
- **File uploads**: real MIME-type sniffing via `finfo` (not the client-supplied
  type), random filenames, 10MB cap, upload dir has `.htaccess` blocking
  script execution, files served only through the authenticated `download.php`
  gate — never linked directly.
- **Session security**: `httponly`, `samesite=Lax`, regenerated on login
  (fixation prevention), idle timeout (30 min), destroyed cleanly on logout.
- **Brute-force throttling**: 5 failed logins per username+IP locks out
  further attempts for 10 minutes.
- **XSS**: all dynamic output passes through `e()` (PHP) or `escapeHtml()`
  (JS) before insertion into the DOM.
- **Access control**: every page and every AJAX endpoint carries an explicit
  `require_login()` or `require_role([...])` guard — verified with no gaps
  in this session (see below).
- **Referential integrity**: FKs throughout; inventory items referenced by
  a past distribution can't be deleted (`ON DELETE RESTRICT`), preserving
  historical accuracy.

---

## 4. Consistency Check (performed this session)

Before shipping this build, the following automated checks were run
against the full codebase:

- Every `fetch()` / `apiPost()` / DataTables `url:` target in `/assets/js/`
  resolves to an actual file in `/ajax/`. ✅ No dangling references.
- Every `new ClassName()` call resolves to a file in `/classes/`. ✅
- Every AJAX endpoint that branches on `REQUEST_METHOD !== 'POST'` also
  calls `csrf_protect()`. ✅ 21/21.
- Every AJAX endpoint has at least `require_login()`; role-restricted
  endpoints (`admin`, `logistics`) use `require_role()`. ✅ 25/25.
- Every top-level page has an explicit guard call. ✅ 9/9.
- No duplicate Bootstrap modal IDs within any single page. ✅

**Not verified in this session:** actual PHP syntax linting (`php -l`) —
no PHP binary was available in the build sandbox. Run this before deploying:
```bash
find relief-dts -name "*.php" -exec php -l {} \;
```

---

## 4a. Running the Test Suite

PHPUnit tests live in `tests/` and cover `classes/Document.php` and
`classes/Relief.php` — the two files with real transactional logic
(routing, approval gating, stock deduction). They run against a real
MySQL database, not mocks, since that's what actually exercises
transactions, foreign keys, and `FOR UPDATE` locking.

```bash
composer install
mysql -u root -p -e "CREATE DATABASE dts_drds_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
grep -v -E '^(CREATE DATABASE|USE)' schema.sql | mysql -u root -p dts_drds_test
vendor/bin/phpunit --testdox
```

Each test truncates and reseeds every table in `setUp()`, so tests never
depend on execution order or on data left over from a previous run. Point
`TEST_DB_HOST` / `TEST_DB_NAME` / `TEST_DB_USER` / `TEST_DB_PASS` env vars
at a different database if `127.0.0.1` / `dts_drds_test` / `root` / *(blank)*
isn't right for your machine — never run this against `dts_drds` itself,
the suite truncates every table it touches.

---

## 5. Known Gaps / Deliberately Out of Scope

- **`download.php` access model** is "any authenticated user" rather than
  department-scoped — flagged in the table above if you need it tighter.
- **No password-complexity rules beyond length** (8 char minimum). Add a
  regex check in `ajax/user_save.php` / `user_reset_password.php` if your
  policy requires mixed case/numbers/symbols.
- **No email verification or "forgot password" self-service flow** —
  password resets are admin-initiated only, by design (government/agency
  context typically wants this centralized).
- **No rate limiting on AJAX endpoints beyond login** — `login.php` has
  brute-force throttling; other endpoints rely on session auth only. Add
  an application-level rate limiter (e.g. Redis token bucket) if this
  will be internet-facing rather than on an internal network.
- **`login_attempts` and `document_logs` tables grow unbounded** — no
  automatic pruning. Add a scheduled job (cron + `DELETE ... WHERE
  created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)`) if long-term retention
  isn't required for compliance.

---

## 6. Quick Smoke Test Checklist

Run through this after deployment, before calling it done:

- [ ] Log in with each of the 3 seed roles; confirm sidebar items differ correctly.
- [ ] Create a document, attach a PDF, edit it, route it to another user.
- [ ] Log in as that user, acknowledge receipt, mark it completed.
- [ ] Archive a document, confirm it disappears from the active list and
      appears under Archived Documents; restore it.
- [ ] Add an inventory item, record a distribution against it with the
      "Generate Manifest" toggle on, confirm the manifest document appears
      in DTS and stock was deducted correctly.
- [ ] Try to delete an inventory item referenced by a distribution — should
      be blocked with a friendly error.
- [ ] As admin, create a new user, deactivate a non-self account, confirm
      that user can no longer log in.
- [ ] Confirm you (admin) cannot deactivate your own account.
- [ ] Try uploading a `.exe` renamed to `.pdf` as an attachment — should
      be rejected by the MIME sniff.
