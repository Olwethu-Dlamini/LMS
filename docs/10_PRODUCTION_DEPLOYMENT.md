# Production Deployment: The Server This Actually Runs On
## Leave Management System (LMS)

Everything else in `docs/` describes the software. This describes the one machine
it runs on, because almost nothing here can be worked out by reading the
repository, and several things in the repository actively suggest the wrong
answer.

Written 2026-08-26, from the live host.

---

## 1. What the live system is

| | |
|---|---|
| Address | `https://lms.22112002.xyz` |
| Host | Debian, user `srv1` |
| Checkout | `/var/www/lms.22112002.xyz`, owned `srv1:www-data` |
| PHP | PHP-FPM 8.4, served by Caddy. There is no Apache on this host |
| Database | MySQL on the same machine, `lms_db`, as `lms_user` over the Unix socket |
| Live since | 2026-08-25 |

The staff roster is real. Accounts hold real `@realnet.co.sz` addresses, so
anything that sends reaches colleagues. See section 5 of
[`09_EMAIL_AND_SMTP.md`](./09_EMAIL_AND_SMTP.md) before enabling mail.

---

## 2. There is no Docker in production

`docker-compose.yml` and `Dockerfile` are in the repository and are used for
development and UAT. The production host has no Docker installed at all.

This matters more than it sounds, because those two files are where a reader
naturally looks to find out how the system is configured, and every answer they
give is wrong for the live server:

- The `PassEnv` line in the `Dockerfile` is Apache configuration *for the image*.
  The live host runs no Apache at all, so nothing there ever reads it. Its
  equivalent is `env[...]` in the PHP-FPM pool, section 3.4.
- Every `MAIL_*` and `DB_*` environment variable in `docker-compose.yml` is
  container configuration. Nothing sets them on the live host.
- `/var/www/html` is the path inside the image. On the live host the code is at
  `/var/www/lms.22112002.xyz`, which is why the worker's cron entry has to be
  written out rather than copied.

**On this server, `config/local.php` is the only configuration mechanism that
does anything.** Layer 2 of `config/constants.php`, the environment, is empty
here because nothing populates it.

---

## 3. The request path

```
browser  ->  Cloudflare  ->  Caddy tunnel  ->  PHP-FPM 8.4  ->  MySQL
```

Four consequences follow from that chain, and three of them are not obvious.

### 3.1 TLS ends before PHP sees the request

Cloudflare terminates the visitor's HTTPS and the traffic reaches PHP as plain
HTTP. `$_SERVER['HTTPS']` is therefore unset on a page a visitor loaded over
HTTPS, and any code that guesses its own scheme would guess `http` and emit
links that redirect or get blocked as mixed content.

Nothing in this application guesses. `APP_URL` is set explicitly in
`config/local.php`, scheme included:

```php
define('APP_URL', 'https://lms.22112002.xyz');
```

That is not a stylistic choice. It is the reason the chain works, and it is why
`APP_URL` must always carry `https://` here even though the web server itself is
only ever spoken to in clear text.

### 3.2 REMOTE_ADDR is the proxy, not the visitor

`LoginThrottle::callerAddress()` reads `$_SERVER['REMOTE_ADDR']` and nothing
else. That is a deliberate decision, and the right default: `X-Forwarded-For` is
a request header, so anyone can write whatever they like into it, and a rate
limiter that trusts it is a rate limiter an attacker steps around by changing a
string on every attempt. `tests/test_suite.php` asserts the header is ignored.

Behind this proxy chain, though, `REMOTE_ADDR` is Caddy's address for every
visitor alike. Failures are counted per `(email, ip_address)`, so in practice the
key collapses to the email alone.

What that costs, precisely:

- **Guessing at one account is still stopped.** Five failures against one address
  in fifteen minutes closes the door, which is the protection the table was added
  for.
- **Spraying across many accounts is not slowed at all.** One password tried
  against thirty staff addresses is one failure each, and nothing counts the
  source they share.
- **Nothing locks out innocent users**, because the email is still part of the
  key. A shared address does not mean a shared lockout.

Fixing it properly means trusting `CF-Connecting-IP` only when `REMOTE_ADDR` is
one of Cloudflare's own published ranges, and treating it as absent otherwise.
That is a real change to security code and is deliberately not made here.

### 3.3 PHP-FPM hands PHP an empty environment

`clear_env` defaults to `yes`, so the pool discards whatever environment it
inherited and `getenv()` sees only names the pool file lists explicitly:

```ini
; /etc/php/8.4/fpm/pool.d/www.conf
env[DB_HOST] = localhost
env[DB_PORT] = 3306
env[DB_NAME] = lms_db
env[DB_USER] = lms_user
env[DB_PASS] = ...
```

This is the FPM equivalent of Apache's `PassEnv`, with the same silent failure: a
name left out is not an error, it just makes `getenv()` return false so the
caller falls through to its development default.

**The CLI reads none of this.** `php tools/send_queued_email.php` runs under the
CLI SAPI, which never opens a pool file, so a configuration that lives only in
`www.conf` produces a working website and tools that cannot reach the database:

```
Database Connection Error: SQLSTATE[HY000] [1698] Access denied for user 'root'@'localhost'
```

That is not a wrong password. `1698` is the `auth_socket` plugin: `root@localhost`
authenticates by operating-system identity, so `sudo mysql` succeeds and the same
command as an ordinary user cannot. It appears here only because `getenv('DB_USER')`
returned false and `config/database.php` fell back to `root`.

The fix is to put the connection details in `config/local.php` with `putenv()`,
which both SAPIs read, rather than in the pool file which only one of them does.
`config/local.php.example` ships that block commented out for exactly this reason.
A credential in two places drifts, and the way it shows up is a working portal
whose cron mail worker has quietly stopped connecting.

Note `DB_HOST` is `localhost`, not `127.0.0.1`. PDO reads `localhost` as a Unix
socket and `127.0.0.1` as TCP, and that one word is load-bearing here.

### 3.4 Cloudflare is in front of the origin

Responses carry `cf-cache-status` and `server: cloudflare`. Application pages set
`Cache-Control: no-store`, so nothing personal is cached, but a change to a file
under `assets/` may be served stale until Cloudflare's copy expires. Purge there
before concluding a stylesheet change did not deploy.

---

## 4. Configuration lives in one untracked file

`config/local.php` on the live host. It is in `.gitignore`, so no pull can
conflict with it and no pull can revert it. `config/local.php.example` is the
tracked template.

At minimum, on this server:

```php
<?php
define('APP_URL', 'https://lms.22112002.xyz');
define('LOGIN_THROTTLE_ENABLED', true);
define('MAIL_ENABLED', false);
define('MAIL_REDIRECT_TO', 'somebody@example.com');
```

Because it holds the database password, it must not be world-readable, and it
must still be readable by the PHP-FPM user. Owner `srv1`, group the pool's user:

```bash
sudo chown srv1:www-data config/local.php && chmod 640 config/local.php
```

**Never edit `config/constants.php` on this server.** It is tracked. An edit
there is a local modification that blocks the next pull, and any `checkout`,
`reset` or aborted merge silently discards it, taking the live configuration with
it. That failure has already happened once, on 2026-08-26, and is what
[section 5](#5-deploying) exists to prevent.

Confirm what is actually in force without opening the file:

```bash
php tools/send_queued_email.php --status
```

It prints the address links will be built from and says so in capitals when
`MAIL_REDIRECT_TO` is diverting mail.

---

## 5. Deploying

```bash
cd /var/www/lms.22112002.xyz
git pull
```

Then the two things a pull cannot do for you, in order.

### 5.1 Migrations, because git does not move schema

A pull moves files. The database is state that lives in MySQL, outside the
repository entirely, and nothing in a checkout reaches it. Code that needs a new
table ships a numbered file in `migrations/` and somebody applies it:

```bash
mysql lms_db < migrations/005-email-outbox.sql
```

There is no migration runner and no table recording what has been applied, so
what has run is not discoverable from the system. Each file is safe to re-run and
ends with a check query that names what now exists, so applying one you are
unsure about is cheaper than reasoning about it. The full list is in the README
under "Upgrading an existing database".

The application is written to run ahead of its migrations rather than fail: until
`004` sign-in is simply not rate limited, until `005` notifications appear in the
bell and no email is queued. That is deliberate, and it has a cost worth knowing.
A missing migration does not announce itself. `LoginThrottle` checks whether
`login_attempts` exists and allows every sign-in if it does not, so
`LOGIN_THROTTLE_ENABLED = true` with the migration unapplied is a switch that is
on and doing nothing.

### 5.2 Verify the address, not the page

```bash
curl -sI https://lms.22112002.xyz/ | grep -i location
```

Expect `location: https://lms.22112002.xyz/modules/auth/login.php`.

This is the check that matters after any deploy touching configuration.
`config/constants.php` defaults `APP_URL` to `http://localhost:8000`, so a
correct answer here is positive evidence that `config/local.php` was read. Opening
the login page in a browser proves nothing: it is reachable at its own URL whether
or not `APP_URL` is right, and only the redirect from `/` and the asset paths in
`includes/header.php` actually exercise the setting.

---

## 6. When a pull will not go through

Recorded because it happened on 2026-08-26 and cost most of a morning.

### 6.1 Divergent branches after a history rewrite

```
+ 29b6609...2892756 main -> origin/main (forced update)
fatal: Need to specify how to reconcile divergent branches.
```

A `filter-branch` on the development machine rewrote every commit, giving
identical content entirely new hashes, and the result was force-pushed. The
server was still on the old hashes. Git sees two histories with no common recent
ancestor and calls them divergent, which is true, but the usual reading of that
word is wrong here: the server held no work of its own. Its forty commits were
the pre-rewrite twins of forty of origin's fifty-eight.

Merging two lineages of the same history is the wrong operation. It produces a
merge commit reconciling a branch with itself. Confirm there is nothing to keep,
then take the remote's history wholesale:

```bash
git log --oneline origin/main..HEAD    # read this before the next line
git fetch origin
git reset --hard origin/main
```

`git log origin/main..HEAD` is the safety check, not a formality. It lists what
would be discarded. Every subject matching a commit already in `origin/main`
means the branch is a rewritten duplicate and nothing is lost. An unfamiliar
subject means somebody committed on the server and `reset --hard` would destroy
it.

`config/local.php` is untracked, so `reset --hard` leaves it alone. **Write it
before the reset, not after.** The incoming `constants.php` defaults `APP_URL` to
localhost, and if that lands with no local file the live site starts redirecting
staff to their own machines the moment the reset completes.

### 6.2 "Entry not uptodate. Cannot merge."

```
error: Entry 'config/constants.php' not uptodate. Cannot merge.
fatal: Could not reset index file to revision 'origin/main'.
```

`reset --hard` normally overwrites local changes without asking, so this is not
the usual "you have uncommitted work" refusal. It means git's index holds stat
information it has not re-verified against disk, and it will not overwrite a file
it cannot account for.

```bash
git update-index --refresh
git checkout -- config/constants.php
git reset --hard origin/main
```

The same stale index is why `git status` had been reporting a clean tree while
`config/constants.php` visibly held a hand-edit. Git was answering from an index
it had not refreshed. If status and the file on disk disagree, refresh the index
before believing either.
