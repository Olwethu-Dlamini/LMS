# Lessons From Going Live
## Leave Management System (LMS)

Written 2026-08-26, the day after launch, when a routine `git pull` on the server
took the site down and the morning spent recovering turned up three bugs nobody
had noticed.

None of these are clever. They are the ordinary things that only show up once
code stops running on the machine that wrote it, and they are written down
because every one of them was invisible until it was not.

---

## 1. The bugs all had the same shape

Three separate defects were found. Read one at a time they look unrelated. Read
together they are one mistake made three times:

| | Worked | Failed | Why |
|---|---|---|---|
| `APP_URL` | Cron worker | Web pages | Missing from Apache's `PassEnv` |
| `DB_USER`, `DB_PASS` | Web pages | Command-line tools | Set in the PHP-FPM pool, which the CLI never reads |
| Worker cron entry | Nowhere | Everywhere | `cd` to the container's path, which does not exist on the host |

Every one is **a setting that exists in one execution context and not another**.
And every one failed by **falling through to a development default instead of
raising an error**.

That second half is what made them expensive. A missing database password stops
the program and gets fixed in a minute. A missing database password that silently
becomes `root` produces a working website and broken tools, and the search starts
in the wrong place entirely.

**The lesson:** when a configuration value has no safe default, do not give it
one. `MAIL_PASSWORD` has no literal anywhere in this repository and that is why
it has never caused a mystery.

---

## 2. Per-server settings do not belong in version control

The failure that started the day: `APP_URL` was a literal in
`config/constants.php`, which is tracked. The live value had been typed in by
hand on the server, so it was a local modification. It blocked every pull, and
the first git operation that touched the working tree discarded it. The site
began redirecting staff to `http://localhost:8000`.

Editing a tracked file on a server is not deployment. It is a change with no
history, no backup, and a guarantee of conflict.

The fix is `config/local.php`: untracked, gitignored, created once per machine,
read before anything else. A pull cannot conflict with it and cannot revert it.
Deploying became `git pull` and nothing else, which is the only version of
deployment anybody actually follows.

---

## 3. The same code runs in more than one place

PHP runs here under three different SAPIs, and each gets its environment from
somewhere different:

- **PHP-FPM** reads `env[...]` lines in the pool file, and `clear_env` defaults
  to `yes`, so it sees nothing else.
- **Apache with mod_php** reads names listed in `PassEnv`, and nothing else.
- **The CLI** reads the actual process environment, and never opens either file.

A configuration mechanism is only useful if every context that needs it can read
it. The database credentials lived in the FPM pool, so the website worked
perfectly and every command-line tool failed. Nothing was misconfigured. The
configuration simply was not where half the program could see it.

`config/local.php` is read by all three, which is the entire argument for it.

---

## 4. Reading the environment at load time makes require order load-bearing

`config/database.php` defined its constants from `getenv()` as the file was
loaded. `config/local.php`, which may set those values with `putenv()`, is loaded
by `config/constants.php`. So the two files had to be required in one particular
order, and nothing said so.

`includes/functions.php` required constants first. `helpers/EmailQueue.php`
required database first. Both looked equally reasonable, and only one of them
worked once anything actually depended on it.

**The lesson:** do not leave an ordering requirement to be remembered. If a file
depends on another having run, it should require it. Fixing
`config/database.php` to require `config/constants.php` itself fixed every caller
at once, including the six that had the same latent bug and had never been run in
a context that exposed it.

---

## 5. Prove a setting with something that exercises it

Twice in one morning a test was run that could not have detected the failure it
was meant to rule out.

Loading the login page in a browser does not prove `APP_URL` is right: that page
is reachable at its own address whether or not the setting is correct. Only the
redirect from `/` and the asset paths use it. The test that means something is:

```bash
curl -sI https://lms.22112002.xyz/ | grep -i location
```

Fetching that same page with `curl` also does not prove the database works, since
`login.php` only connects inside its `POST` branch. A `GET` never touches MySQL.

**The lesson:** before running a check, ask which line of code it reaches. A test
that cannot fail is not evidence, and it is worse than no test because it ends
the investigation.

---

## 6. `git pull` moves files and nothing else

The database is state living outside the repository. No checkout reaches it.
Code that needs a new table ships a file in `migrations/` and a human applies it.

This project deliberately runs ahead of its migrations rather than failing, so an
installation is never locked out of itself by a table that does not exist yet.
That kindness has a cost worth knowing: **a missing migration does not announce
itself**. `LOGIN_THROTTLE_ENABLED` was switched on while `login_attempts` had
never been created, and `LoginThrottle` degrades to allowing every sign-in rather
than locking the organisation out. The switch was on. It was doing nothing.

A feature flag and the schema it depends on have to be checked together.

---

## 7. Rewriting history breaks every clone that already exists

A `filter-branch` on the development machine rewrote every commit hash and was
force-pushed. The server still held the old hashes, so `git pull` reported
divergent branches. That reads as "the server has work of its own" and it did
not: its forty commits were the pre-rewrite twins of forty of origin's
fifty-eight.

Merging two lineages of the same history reconciles a branch with itself. The
right operation on a deployment target is to take the remote wholesale:

```bash
git log --oneline origin/main..HEAD    # read this first
git reset --hard origin/main
```

That first line is the safety check and not a formality. It lists exactly what
would be destroyed. Familiar subjects mean rewritten duplicates and nothing is
lost. An unfamiliar subject means somebody committed on the server.

**If you rewrite published history, you own the recovery of every clone.** Doing
it to strip commit trailers cost more than the trailers did.

---

## 8. Order the deploy so the site is never briefly wrong

`config/local.php` had to be written **before** `git reset --hard`, not after.
The incoming `constants.php` defaults `APP_URL` to localhost, so a reset landing
with no local file would have pointed the live site at staff members' own
machines for however long the next command took to type.

Untracked files survive `reset --hard`, which is what makes the ordering
possible. Deploy steps have an order, and the order is chosen so that no moment
in the middle is broken.

---

## 9. Capability can belong to a machine rather than a credential

`mail.realnet.co.sz` advertises no `AUTH`. It decides whether to relay by looking
at the IP the connection came from. There is no password involved and there never
was.

So "can this application send email?" is a question about **where it is plugged
in**, and no file in this repository can answer it. Move the host and every byte
is identical and nothing sends.

That is why `tools/test_email.php` exists and why its transcript is pasted into
`09_EMAIL_AND_SMTP.md` section 3.2 rather than summarised. Configuration can be
reviewed. Environment has to be tested from the machine in question.

---

## 10. Give dangerous features two gates

Email is guarded twice, deliberately:

- `MAIL_ENABLED` decides whether anything is **queued**.
- The cron worker decides whether anything is **sent**.

Neither alone releases mail. That is what made it possible to deploy the entire
email system to a live server holding the real staff roster and have it sit
there inert until it had been proven end to end, and it is what makes UAT on a
live system possible at all: close the second gate and the application records
exactly what it would have sent while sending none of it.

The corresponding discipline is that both gates must be opened **together and
deliberately**. Redirect cleared but no worker running means nothing reaches
anyone. Worker running with the redirect still set means every notice silently
goes to one mailbox while staff conclude the system is quiet.

---

## 11. Small things that cost real time

**`git status` can be wrong.** It reported a clean tree while the file on disk
visibly held an edit, because the index held stat data it had not re-verified.
The same staleness made `reset --hard` fail with `Entry not uptodate`. If status
and the file disagree, `git update-index --refresh` before believing either.

**`sed -i` does not edit in place.** It writes a new file and renames it over the
old one, so ownership and group are the ones creating it. On a file that a
service must read, re-assert ownership and permissions after every edit.

**`1698` is not a wrong password.** It is the `auth_socket` plugin: the account
authenticates by operating-system identity, which is why `sudo mysql` succeeds
and the same command as an ordinary user cannot. No password can fix it because
none is ever consulted.

**Cron has almost no `PATH` and does not read your shell profile.** A bare `php`
and a relative path both work when typed and fail silently every minute
thereafter.

---

## 12. An audit trail should be a copy of the artifact, not a report about it

Added the day after launch: every outgoing notice is blind-copied to one mailbox,
so there is somewhere to read what the system has told people.

The implementation choice is the lesson. It would have been easier to send a
second, separate message to the archive address, or to build a page that renders
what a notification "would have looked like". Instead the copy is a `Bcc` on the
same SMTP transaction as the real delivery.

**The advantage is fidelity.** The archived copy *is* the message the recipient
received. Same headers, same rendered HTML, same links, sent in the same moment
by the same code path. There is no second rendering that can drift, no template
that has changed since, no reconstruction that quietly differs from what somebody
actually opened. When the question is "what exactly did this person receive?",
the mailbox answers it rather than approximating it.

Anything that re-renders is answering a different question: what the system
*would produce today* for that event. Those are the same answer right up until a
template changes, and it is precisely after a change that somebody asks.

The same reasoning is why `email_outbox` stores `body_html` in full rather than
the ingredients needed to rebuild it.

### 12.1 Match the failure response to the harm, not to the shape

`MAIL_REDIRECT_TO` and `MAIL_ARCHIVE_TO` are both "an address in configuration
that might be wrong". They behave in opposite ways on a bad value, and neither is
a style preference:

- A bad `MAIL_REDIRECT_TO` **refuses to send at all**. Honouring the typo by
  delivering to the real recipient is the single outcome the person who set it
  was trying to prevent, and during UAT it means mailing thirty colleagues about
  leave that does not exist.
- A bad `MAIL_ARCHIVE_TO` is **logged and ignored, and the message goes out**.
  Refusing here would mean a colleague is never told about their own leave
  because the address for a copy nobody was waiting on has a typo.

Two identical-looking errors, opposite correct responses, because what goes wrong
is not the same. "Fail loudly" and "degrade quietly" are not house style to be
applied consistently. Each one is derived from the cost of being wrong.

### 12.2 Adding a signal to a channel devalues what is already in it

The cost, stated honestly, because it arrived with the feature.

`lms@realnet.co.sz` was already doing three jobs: it is the sending address, it
receives replies, and it receives bounces. Bounces are the messages in it that
genuinely need a person, and the only evidence that a notice failed to reach
somebody after the relay accepted it (section 3.5 of
[`09_EMAIL_AND_SMTP.md`](./09_EMAIL_AND_SMTP.md)).

Copying every notification into that same mailbox buries them. Thirty staff
generating routine notices will produce far more archive copies than bounces, and
the archive is the part nobody needs to read.

The fix is a filter on `Auto-Submitted: auto-generated`, which `Mailer` already
sets on every message, sorting copies into a folder and leaving replies and
bounces in the inbox. The general point is worth more than the fix: **a new
stream of low-urgency messages makes the high-urgency ones in the same place
harder to see.** Volume added to a channel is value removed from what was already
there, and the feature that adds it should arrive with the sorting rule, not
before it.

### 12.3 Know whether you are adding information or adding access

`email_outbox` already held every message in full, with the real recipient and
the delivery state, queryable with SQL. The archive mailbox adds **no data that
was not already recorded**. What it adds is a way to read the trail in a mail
client instead of writing a query.

That is a real benefit and worth the volume it costs, as long as it is understood
for what it is. It does not make the outbox redundant, it does not make the
outbox safe to prune, and if the two ever disagree the outbox is the record.

---

## What this cost

One morning of a live system serving redirects to `localhost`, and a rate limiter
that had been switched off since launch on a public sign-in form.

Everything that caused it was known to somebody at some point. None of it was
written down. That is what `10_PRODUCTION_DEPLOYMENT.md` is for.

Section 12 was added later the same week and is a different kind of entry: not a
mistake recovered from, but a decision recorded while the reasoning behind it was
still available. Those are cheaper to write and worth more, and they are the ones
that stop being obvious first.
