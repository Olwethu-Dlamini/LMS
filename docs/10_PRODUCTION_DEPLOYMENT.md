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
| PHP | mod_php under Apache, running directly on the host |
| Database | MySQL on the same machine, `lms_db` |
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
  The live host's Apache has never seen it.
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
browser  ->  Cloudflare  ->  Caddy tunnel  ->  Apache + mod_php  ->  MySQL
```

Three consequences follow from that chain, and two of them are not obvious.

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

### 3.3 Cloudflare is in front of the origin

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
