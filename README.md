# Leave Management System (LMS)

A robust, multi-role PHP Leave Management System built with a modular component architecture (Header, Navbar, Sidebar, Footer, Layout), automated working days calculation engine, and a 3-tier sequential approval workflow (**Line Manager**, then **HR**, then **Executive / Boss**).

---

## Complete Pre-Implementation System Design Documentation

Before any code implementation begins, full architecture and system design blueprints have been established in the [`docs/`](./docs/) directory:

1. [01_SOFTWARE_REQUIREMENTS_SPECIFICATION.md](./docs/01_SOFTWARE_REQUIREMENTS_SPECIFICATION.md) - Software Requirements Specification (SRS) & User Roles
2. [02_SYSTEM_ARCHITECTURE_DESIGN.md](./docs/02_SYSTEM_ARCHITECTURE_DESIGN.md) - Modular PHP Architecture & System Component Design
3. [03_DATABASE_DESIGN_AND_ERD.md](./docs/03_DATABASE_DESIGN_AND_ERD.md) - Database ERD, Data Dictionary & Full SQL Schema (`schema.sql`)
4. [04_APPROVAL_WORKFLOW_STATE_MACHINE.md](./docs/04_APPROVAL_WORKFLOW_STATE_MACHINE.md) - 3-Stage Approval Flow State Machine Specification
5. [05_BUSINESS_RULES_ENGINE_SPECIFICATION.md](./docs/05_BUSINESS_RULES_ENGINE_SPECIFICATION.md) - Working Days Engine, Holiday Exclusions & Balance Rules
6. [06_ROLE_BASED_ACCESS_CONTROL_MATRIX.md](./docs/06_ROLE_BASED_ACCESS_CONTROL_MATRIX.md) - RBAC Permission Matrix across 5 User Roles
7. [07_UI_UX_WIREFRAMES_AND_COMPONENT_MAP.md](./docs/07_UI_UX_WIREFRAMES_AND_COMPONENT_MAP.md) - Page Layout Wireframes & Bootstrap Theme Token Map

---

## User Manual

End-user documentation covering all five roles, the leave rules, the holiday
calendar and troubleshooting. Same content in three formats. Edit the Markdown,
then regenerate the other two:

| Format | File | Use it for |
|---|---|---|
| **Markdown** | [`docs/08_USER_MANUAL.md`](./docs/08_USER_MANUAL.md) | The source of truth. Renders on GitHub, diffs cleanly in review. |
| **Web page** | [`docs/user-manual.html`](./docs/user-manual.html) | Self-contained: fonts and styles are inlined, so it works offline from a share drive and prints to a clean 15-page A4 document. |
| **Word** | [`docs/08_USER_MANUAL.docx`](./docs/08_USER_MANUAL.docx) | Handing to HR for distribution or intranet upload. Real Word heading styles, so Word's navigation pane and table of contents work. |

Regenerating the Word version after editing the Markdown:

```bash
python3 tools/md_to_word_html.py docs/08_USER_MANUAL.md /tmp/manual.html
soffice --headless --convert-to "docx:MS Word 2007 XML" --outdir docs /tmp/manual.html
mv docs/manual.docx docs/08_USER_MANUAL.docx
```

---

## Supported Roles & Approval Pipeline

```
[ Employee Applies ]
        │
        ▼
[ Stage 1: Line Manager Review ] ──(Rejection)──► [ Rejected & Released ]
        │ (Approved)
        ▼
[ Stage 2: HR Manager Review ]  ──(Rejection)──► [ Rejected & Released ]
        │ (Approved)
        ▼
[ Stage 3: Executive / Boss Sign-Off ] ──(Rejection)──► [ Rejected & Released ]
        │ (Approved)
        ▼
[ Status: APPROVED & Days Deducted ]
```

| Role | Primary Responsibilities |
|---|---|
| **Employee** | Applies for leave, views personal entitlement balance, tracks live application progress. |
| **Line Manager** | Reviews team leave applications, approves/rejects Stage 1 requests, views team calendar. |
| **HR Manager** | Approves/rejects Stage 2 requests, manages employee leave allocations and public holiday calendar. |
| **Executive / Boss** | Final authority for Stage 3 approvals, views high-level dashboard and company-wide reports. |
| **System Admin** | Manages user accounts, departments, leave types, and audit logs. |

---

## Coverage, Notifications & Email

**Team calendar** (`modules/leave/team_calendar.php`) is a server-rendered month per
department: who is off each working day and how many that leaves away out of the
team. Approved leave and requests still awaiting a decision are drawn and labelled
apart, each as a filled chip saying its state in words: *Approved*, or
*Requested · not yet approved*. Only approved leave is counted against the
headcount: a day reading "3 away" is unplannable when one of them is off and two
have only asked. A legend names both. Your own entries read "You". Weekends and
public holidays are never counted as absence.
Employees see their own department without leave categories, since sick and
maternity leave should not be disclosed to colleagues; managers see the
departments they approve for; HR, executives and admins see any department.

**Coverage limits.** `departments.max_concurrent_absences` sets how many members
may be away at once, edited per department in the admin console. Every approval
stage shows a notice saying whether *this* request is what tips the department
over, or whether it was already short, and the same notice appears on the apply
form. The calendar itself no longer shades days amber and red for this: whole-day
shading competed with the entries inside the cell and coloured days by a threshold
most departments never set. Nothing is blocked anywhere: sick leave does not wait
for a convenient rota.

**Before you book.** The apply form answers the coverage question while the
dates can still change. Once a category and a range are chosen it names who else
in your department is already off then, for how many days, and whether the
request would take the team past its limit. Same wording as the approval queues,
given to the person who can still move the dates. Nothing is blocked.

**Notifications.** A bell with an unread count in both navigation bars.
Applicants hear about submission, each stage cleared, approval, rejection (with
remarks) and cancellation; approvers hear when a request reaches their queue or is
withdrawn from it. Recipients are derived from the workflow, so reassigning a
department's head redirects future notices. Notices are raised after each commit
and every write is guarded, so a notification problem can never roll back an
approval.

The bell keeps itself current, polling `api/notifications_poll.php` once a minute
so an approver sitting on their queue sees a request arrive without reloading. It
stops while the tab is in the background, and never rearranges the list while the
dropdown is open.

**Email.** Every one of those notifications is also sent to the recipient's work
address, which is the half that reaches people who are not signed in. Mail is
queued rather than sent during the request: an approver pressing **Approve**
should not wait on a mail server, and must not wait out a socket timeout when it
cannot be reached. A worker drains the queue on a cron. See
[Outgoing email](#outgoing-email) for setting it up; it is off until configured.

The shared engines are `helpers/LeaveCapacity.php` (who is away, and does that
break cover), `helpers/Notifier.php` (who to tell, and what to say),
`helpers/EmailQueue.php` (the outbox), `helpers/Mailer.php` (SMTP) and
`helpers/DashboardInsights.php` (the dashboard figures). The day arithmetic and
aggregation in each are pure functions, covered by the test suite.

---

## Technology Stack

- **Core**: PHP 8.0+ (PDO, Native Sessions, Clean Modular Component Architecture)
- **Database**: MySQL 8.0 / MariaDB
- **Frontend / Styling**: HTML5, Vanilla CSS, Bootstrap 5, FontAwesome, DataTables
- **Architecture**: Front Controller Routing with Component-based Layout

---

## Setup & Database Installation

### Standard install

1. Import the schema (`schema.sql` is the executable one; `docs/03_…md` is
   documentation, not a loadable file):
   ```bash
   mysql -u root -p < schema.sql
   ```
2. Configure the DB connection. `config/database.php` reads these environment
   variables and falls back to the defaults shown:

   | Variable  | Default     |
   |-----------|-------------|
   | `DB_HOST` | `127.0.0.1` |
   | `DB_PORT` | `3306`      |
   | `DB_NAME` | `lms_db`    |
   | `DB_USER` | `root`      |
   | `DB_PASS` | *(empty)*   |

3. Launch the development server from the project root:
   ```bash
   php -S localhost:8000
   ```

`APP_URL` in `config/constants.php` is `http://localhost:8000`; change it if you
serve the app from a different host or port.

### Upgrading an existing database

`schema.sql` is the full current schema, applied only to a fresh install. An
existing database is brought up to date with the numbered files in `migrations/`,
applied in order:

```bash
mysql -u root -p lms_db < migrations/001-role-aware-routing.sql
mysql -u root -p lms_db < migrations/002-calendar-and-notifications.sql
mysql -u root -p lms_db < migrations/003-decode-double-escaped-text.sql
mysql -u root -p lms_db < migrations/004-login-attempts.sql
mysql -u root -p lms_db < migrations/005-email-outbox.sql
```

Each is safe to re-run and ends with a check query you can read to confirm it took.

| Migration | What it does |
|---|---|
| `001-role-aware-routing` | Moves in-flight applications onto role-aware routing. No schema change. |
| `002-calendar-and-notifications` | Adds `departments.max_concurrent_absences` and the `notifications` table. |
| `003-decode-double-escaped-text` | Repairs text stored HTML-escaped, so `Sales &amp; Marketing` reads as `Sales & Marketing` again. |
| `004-login-attempts` | Adds the `login_attempts` table behind the sign-in rate limit. |
| `005-email-outbox` | Adds the `email_outbox` table that outgoing notification email is queued in. |

The portal keeps working ahead of each of these rather than failing. Until `002`
the notification bell stays hidden and the calendar shows no coverage limits;
until `004` sign-in simply is not rate limited; until `005` notifications appear
in the bell and no email is queued. An installation is never locked out of itself
because a migration has not run yet.

### Local UAT environment

`./uat.sh` runs the app against its own private MariaDB instance on port **3307**,
so it never touches a system MySQL already using 3306:

```bash
./uat.sh start       # start database + web server
./uat.sh status      # what is running, plus application counts
./uat.sh reset       # clear applications/logs/balances, keep user accounts
./uat.sh reinstall   # drop and re-import schema.sql from scratch
./uat.sh logs        # tail PHP and MariaDB error logs
./uat.sh stop
```

Then open <http://localhost:8000>.

**There are no accounts to sign in with yet.** `schema.sql` seeds reference data
only: roles, departments, leave categories and holidays. So a fresh database
has no users at all. That is deliberate: the five `@lms.com` demo accounts that
used to be seeded here all shared the password `password123`, which is published
in this file, so every installation shipped with the same known way in.

Create the first administrator, which prints a password once and forces it to be
replaced on first sign-in:

```bash
php tools/create_admin.php --email you@realnet.co.sz --name "Your Name"
```

Then load the staff roster with `tools/seed_employees.php` (below) and set roles,
departments and reporting managers in **Administration → User Management**.

Locked out with no administrator left? The same tool is the way back:

```bash
php tools/create_admin.php --email you@realnet.co.sz --reset-password
```

---

### Per-server settings, so deploying is only `git pull`

Anything that differs between a laptop and a live server lives outside the
repository, in `config/local.php`:

```bash
cp config/local.php.example config/local.php
# edit it once, on the server
```

That file is in `.gitignore`. It is never committed, so a pull cannot conflict
with a production setting and cannot silently revert one. `config/constants.php`
reads three layers in order, and the first to define a constant wins:

1. `config/local.php`, if it exists.
2. Environment variables, for anything it did not set.
3. The defaults in `constants.php`, which are development values.

**`APP_URL` is the one to get right.** It is the base of every link in every
notification email, and those are rendered by a cron worker that has no HTTP
request to infer a hostname from, so it cannot be detected automatically. Left
as `http://localhost:8000` on a live server, the portal loads its stylesheets
from an address that does not exist and every staff member is mailed a link to
their own machine. Nothing errors: mail sends, is marked delivered, and the
links are simply dead. `tools/test_email.php` and
`php tools/send_queued_email.php --status` both warn in capitals when `APP_URL`
still looks local.

A minimal live `config/local.php`:

```php
<?php
define('APP_URL', 'https://leave.example.co.sz');
define('MAIL_ENABLED', true);
define('LOGIN_THROTTLE_ENABLED', true);
```

---

## Outgoing email

Every notification is also emailed to the recipient's work address, from the
`lms@realnet.co.sz` mailbox. Off until switched on, so an installation without a
reachable mail server queues nothing rather than building a backlog it cannot
send.

### How it is put together

Four pieces, each of which can be read without the others:

| File | What it does |
|---|---|
| `helpers/Notifier.php` | Decides who is told what. Queues the email as it writes the bell row. |
| `helpers/EmailTemplate.php` | Turns a notification into a subject and both bodies. Pure functions. |
| `helpers/EmailQueue.php` | The `email_outbox` table: queue it, claim it, retry it, record what happened. |
| `helpers/Mailer.php` | The only file that knows SMTP exists. Wraps the vendored PHPMailer in `lib/`. |

Mail is queued rather than sent inside the request. An approver pressing
**Approve** should not wait on an SMTP round trip, and should certainly not wait
out a socket timeout when the mail server is unreachable. A queued message also
survives an outage, and the table doubles as the delivery log: *was Thandi told?*
is a question it can answer, including the reason when the answer is no.

Nothing about email can disturb an approval. Queueing happens after the
transaction commits, inside its own guard, and a notification whose email could
not be queued is still a success - the bell will show it and the failure is
logged.

### The mail server, as found

`mail.realnet.co.sz` is an IMail 8.22 server. Its `EHLO` on port 25 answers with
`SIZE`, `8BITMIME`, `DSN`, `ETRN` and `EXPN`, and ports 587 and 465 refuse
connections outright. Two consequences, both already reflected in the defaults:

- **No authentication.** No `AUTH` is advertised, so there is nothing to log in
  to. `MAIL_USERNAME` is empty and the client relays without authenticating. The
  `lms@realnet.co.sz` password is for reading that mailbox over POP3 - a
  different job, and not one this application does.
- **No encryption.** No `STARTTLS`, so leave notices cross the network in the
  clear. That is the server's configuration rather than a choice made here, and
  worth raising with whoever runs it.

The server decides what to relay by the address the connection comes from, and it
accepts mail from inside Realnet's own ranges. **The application has to keep
running from an address the mail server trusts.** Moved to a host outside those
ranges, sending will be refused, and the symptom is a relay error in the outbox
rather than anything obvious. The `realnet.co.sz` SPF record ends in `-all`, so
mail leaving from anywhere unlisted is rejected outright rather than junked.

### Switching it on

**Ask the server first**, from the machine that will be sending. This sends
nothing - it opens the port, reads the greeting, asks `EHLO` what the server
supports, offers an envelope, and hangs up before the message body:

```bash
php tools/test_email.php
```

It reports what the server offers and whether it would relay, and names the two
mismatches that otherwise produce a baffling error: a username set against a
server with no `AUTH` (which fails as *"Could not authenticate"* and explains
nothing), and encryption asked of a server with no `STARTTLS`.

Then send one real message to yourself:

```bash
php tools/test_email.php --to you@realnet.co.sz
```

Only once that arrives, turn it on. Everything is an environment variable, the
same as the `DB_*` settings, with defaults in `config/constants.php`:

```bash
export MAIL_ENABLED=true
```

| Variable | Default | Notes |
|---|---|---|
| `MAIL_ENABLED` | `false` | Off means nothing is queued at all. |
| `MAIL_HOST` | `mail.realnet.co.sz` | |
| `MAIL_PORT` | `25` | The only port this server answers on. |
| `MAIL_ENCRYPTION` | empty | `tls`, `ssl`, or empty for none. |
| `MAIL_USERNAME` | empty | Empty means relay without authenticating. |
| `MAIL_PASSWORD` | empty | Never put this in a tracked file. Unused as things stand. |
| `MAIL_FROM_ADDRESS` | `lms@realnet.co.sz` | Must be a mailbox the server will send as. |
| `MAIL_REPLY_TO` | `info@realnet.co.sz` | Replies reach a person; `lms@` is not read. |
| `MAIL_REDIRECT_TO` | empty | Sends everything to one address instead of the real recipients. For UAT. Must be empty in production. |
| `MAIL_BATCH_SIZE` | `20` | Messages per worker run. |
| `MAIL_MAX_ATTEMPTS` | `5` | Then the message is marked failed and left alone. |

Under Apache, `PassEnv` has to name each one or `getenv()` returns nothing and the
constants quietly fall back to their defaults - which looks exactly like
configuration being ignored. The `Dockerfile` already does this.

### The worker

Nothing is sent until the worker runs. One cron entry:

```cron
* * * * * cd /var/www/html && php tools/send_queued_email.php >> /var/log/ri-leave-mail.log 2>&1
```

Under Docker, run it on the host against the container:

```cron
* * * * * docker compose -f /path/to/docker-compose.yml exec -T web php tools/send_queued_email.php
```

A minute is right because the queue exists to keep SMTP off the critical path,
not to delay mail. It is quiet on success, so an entry with no redirection will
not mail root every minute about nothing; failures always print, and the exit
code says which happened (`0` fine, `1` something could not be sent, `2` could
not run at all). Only one copy runs at a time - a mail server that has started
timing out holds each run open, and a minutely cron would otherwise pile up
stalled workers.

Failures are retried after 1, 5, 15 and 60 minutes, then marked `failed` and left
alone. Failed rows are never deleted automatically: they are the ones somebody
still has to read.

### Testing it without mailing the whole company

The UAT database is seeded from the real staff roster, so almost every account
carries a colleague's actual address, and the UAT machine can reach the mail
server. Turning `MAIL_ENABLED` on there and running the worker will send real
leave notices to real people about requests that do not exist.

Two switches keep that from happening, and it is worth knowing they are separate:

- **`MAIL_ENABLED`** controls whether anything is *queued*.
- **The worker** controls whether anything is *sent*. Nothing leaves until it runs.

So there are three sensible levels of UAT:

**1. Content only, nothing sent.** Turn queueing on and never run the worker.
Every notice piles up in `email_outbox`, where you can read exactly what would
have gone out, to whom:

```bash
export MAIL_ENABLED=true
# exercise the app: apply, approve, reject, cancel
mysql -u root -p lms_db -e "SELECT id, to_email, subject, status FROM email_outbox ORDER BY id;"
mysql -u root -p lms_db -e "SELECT body_text FROM email_outbox ORDER BY id DESC LIMIT 1\G"
```

**2. Real delivery, all of it to you.** Set `MAIL_REDIRECT_TO` and every message
goes to that one mailbox instead, whoever it was addressed to:

```bash
export MAIL_ENABLED=true
export MAIL_REDIRECT_TO=you@realnet.co.sz
php tools/send_queued_email.php --verbose
```

Each message keeps its real recipient in the subject line, as
`[to thandi@realnet.co.sz] ...`, and opens with a banner saying it was
redirected. This is the level to use for judging whether the mail actually
*reads* well, since it exercises the whole path including the mail server.

The redirect happens when the message is sent, not when it is queued, so
`email_outbox` still records who each one was really for and the delivery log
stays truthful. A `MAIL_REDIRECT_TO` that is not a valid address makes sending
fail rather than falling back to the real recipient: honouring a typo by mailing
the actual staff is the one outcome anybody setting it was trying to avoid.

**3. Genuine end-to-end.** Clear `MAIL_REDIRECT_TO` and point a test account at
an address you control:

```bash
mysql -u root -p lms_db -e \
  "UPDATE users SET email = 'you+uat@realnet.co.sz' WHERE id = <a test account>;"
```

Do that rather than clearing the redirect with the seeded roster in place.

### When mail is not arriving

Start here. It reports the configuration and the queue together, with the most
recent errors and what the mail server actually said:

```bash
php tools/send_queued_email.php --status
```

| What it says | What it means |
|---|---|
| `sending enabled: no` | `MAIL_ENABLED` is false. Nothing is being queued. |
| `queued` climbing, `sent` at 0 | Nothing is running the worker. Check the cron entry. |
| `Could not connect to SMTP host` | Port blocked, wrong host, or this machine is off the network the server trusts. |
| `Could not authenticate` | `MAIL_USERNAME` is set against a server offering no `AUTH`. Clear it. |
| `relay` or `550` in an error | The server will not relay from this address. See the note above. |
| Everything `sent`, nothing received | Delivered but filtered. Check the junk folder; then it is a question for whoever runs the mail server. |

Old delivery records can be let go of, without touching failed ones:

```bash
php tools/send_queued_email.php --prune 90
```

---

## Staff Accounts & Passwords

### Loading the staff roster

`tools/seed_employees.php` loads the Real Image roster. It is idempotent, so it
skips anyone whose email already exists, so it is safe to re-run as the roster
grows.

```bash
php tools/seed_employees.php                      # dry run: shows what it would create
php tools/seed_employees.php --commit             # write the accounts
php tools/seed_employees.php --commit --reissue   # fresh temp password for everyone
                                                  # still awaiting a first sign-in
```

Use `--reissue` if the credential list is lost. Stored passwords are bcrypt
hashes and cannot be recovered, only replaced. The CSV is **appended**, never
overwritten, so previously issued passwords are never destroyed; where an email
appears more than once, the newest row wins.

Each new account gets **its own random temporary password** and is flagged
`must_change_password`. The temporary passwords are written to
`$HOME/.ri-leave-uat/initial-passwords.csv` (mode `0600`, deliberately **outside
the repository** so they can never be committed). Distribute them securely,
then delete the file.

New starters are created as plain `employee` with no department and no reporting
manager. Assign those in **Administration → User Management**. Until a user has
a manager, only an admin can clear Stage 1 for them.

### Password rules

- Minimum 10 characters, with at least one uppercase, one lowercase and one number.
- Users change their own password via **Change Password** in the top utility strip.
- Anyone flagged `must_change_password` is held on the change-password screen and
  cannot reach the rest of the app until they set their own password.
- An admin password reset (**User Management → Password**) is always treated as
  temporary and re-raises that flag.
- Sign-in rate limiting exists but is **switched off** while the system is in
  testing; see `LOGIN_THROTTLE_ENABLED` in `config/constants.php`. Switched on,
  five failures for the same address and email inside fifteen minutes stops
  answering until the window passes, and a successful sign-in clears the count.
  Counting per email *and* caller together is deliberate: per email alone would
  let anyone lock a colleague out on purpose. **Turn it on before go-live.**
- Password fields carry a show/hide eye, so a temporary password full of
  punctuation can be checked before it is submitted rather than after being
  locked out by it.

---

## Admin Console

Administrators get a separate console at `/modules/admin/index.php`, reachable
from the **Admin Console** link in the staff nav. It carries its own dark
navigation containing system administration only, with no Apply, My Leave or
Approvals, and a **Staff Portal** switcher back, so an admin can still book
their own leave. Every `/modules/admin/*` route is gated by
`require_role(ROLE_ADMIN)`.

| Section | What it does |
|---|---|
| **Overview** | Counts plus a *Setup Attention* panel flagging users with no manager or department, accounts still on a temporary password, and a missing holiday calendar. |
| **Users** | Create, edit, reset password, archive/restore, delete. |
| **Departments** | Create, rename, reassign head, delete (blocked while members remain). |
| **Leave Types** | Full rule configuration. See below. |
| **Holidays** | Add, edit, delete. Changes affect future calculations only. |
| **Audit Log** | Every approval and rejection, filterable by action, role and date. |

### Deleting is deliberately guarded

`leave_applications` and `leave_approval_logs` are wired `ON DELETE CASCADE`, so
a naive user delete would erase that person's leave history and the audit trail
with them. Instead:

- **Users.** Delete permanently removes the account *only* when it has zero
  applications and zero approval log entries. Anything with history is
  **archived** instead, and you are told why. You cannot remove your own account
  or the last active administrator.
- **Leave types.** A category referenced by any application is **retired**
  rather than deleted: history and reports stay intact and it disappears from
  the apply form.
- **Departments.** Cannot be deleted while they still have members.

### Configurable leave rules

Each leave type carries its own policy, enforced server-side in
`LeaveCalculator::validateEligibility()` and mirrored as hints on the apply form:

| Setting | Meaning |
|---|---|
| `max_days_per_year` | Annual allocation used when seeding entitlements |
| `min_days_per_request` | Smallest bookable request |
| `max_days_per_request` | Largest single request; blank means no cap. A request is one contiguous range, so this also caps consecutive days within it |
| `allow_half_day` | Whether half-day options are offered at all. A half day applies to a single day: choosing one across a longer range is refused, not quietly counted as the range minus half a day |
| `min_notice_days` | Days of advance notice required. **0 also permits backdating**, which is what lets sick leave be recorded after the fact. The apply form's date picker takes its earliest date from this rule, so a zero-notice category has no floor at all |
| `requires_attachment` + `attachment_threshold_days` | Demand a document only once a request exceeds N working days. This replaces what was a hardcoded "sick leave over 2 days" rule |
| `is_paid` | Paid or unpaid |
| `is_active` | Retired types vanish from the apply form but stay in reports |

Shipped defaults: Annual 7 days notice; Casual max 3 days per request with
1 day notice; Sick no notice with a document over 2 days; Maternity and Unpaid
whole days only, Unpaid needing 14 days notice.

---

## Supporting documents

Sick notes and other attachments are the most sensitive records here, so they are
not served as files. The upload directory denies direct access (`.htaccess` for
Apache; for nginx add `location ^~ /uploads/ { deny all; }` to the server block),
and the only route to a document is
[`modules/leave/attachment.php`](./modules/leave/attachment.php), which streams
it only to the applicant, their line manager, HR, executives and administrators.
A colleague who can see the absence on the team calendar cannot open the
certificate behind it, and a refusal looks identical to a missing file so the
route cannot be used to find out who has filed one.

Stored names are random (`att_<32 hex>.pdf`). The previous scheme was built from
the applicant's user id and the upload time, which made the directory walkable by
anybody who could guess a timestamp. Documents already stored under the old names
still open, because the path is read from the application rather than rebuilt.

Accepted: PDF, JPG, PNG, up to 5 MB. A document that fails to store fails the
whole submission, rather than leaving a request in a queue looking complete
without the certificate it depends on.

---

## Before go-live

- [x] ~~Delete the five `@lms.com` demo accounts and remove them from `schema.sql`.~~
      Done. No accounts are seeded at all. Bootstrap with `tools/create_admin.php`.
- [ ] Create `config/local.php` from the example, and set `APP_URL` to the
      production hostname. Do not edit `config/constants.php` on a server: it is
      tracked, so the next pull will fight you for it.
- [ ] Move the DB credentials to real environment variables (never commit them).
- [ ] Serve over HTTPS. The session cookie sets `HttpOnly`, `SameSite=Lax` and
      strict session ids itself, and turns on `Secure` as soon as the request
      arrives over HTTPS, so no php.ini change is needed.
- [ ] Apply every migration in `migrations/`, in order.
- [ ] Block `/uploads/` at the web server if you serve with nginx (Apache is
      covered by the `.htaccess` already in the directory).
- [ ] Set `LOGIN_THROTTLE_ENABLED` to `true` in `config/local.php`.
- [ ] Prove email from the production host with `php tools/test_email.php`, send
      one real message with `--to`, then set `MAIL_ENABLED=true`.
- [ ] Add the `tools/send_queued_email.php` cron entry. Without it nothing is
      ever sent, and the outbox grows quietly.
- [ ] Check the mail server still relays from the production host's address - it
      decides by address, not by password.
- [ ] Confirm `MAIL_REDIRECT_TO` is **empty** in production. Set, it silently
      diverts every notice to one mailbox and nobody else is told. Both
      `tools/test_email.php` and `--status` say so in capitals when it is on.
- [ ] Re-issue everyone's password: `php tools/seed_employees.php --commit --reissue`.
      UAT sets every account to `password123` for testing; that must not survive.
- [ ] Turn `display_errors` **off** in production PHP config.
- [ ] Assign every user a department, role and reporting manager.
- [ ] Load the real public holiday calendar for the leave year.
- [ ] Delete `$HOME/.ri-leave-uat/initial-passwords.csv` once passwords are handed out.

### Docker

`docker-compose.yml` + `Dockerfile` bring up PHP 8.2 (Apache) and MySQL 8.0 with
the schema auto-imported:

```bash
docker compose up -d --build
```

### Tests

```bash
php tests/test_suite.php        # honours the DB_* environment variables above
php tests/test_email_queue.php # the outbox, against a real database
```

The second needs a database and the `email_outbox` table, and skips with a
reason when it has neither. It refuses to run if real mail is waiting to be
sent rather than deleting a queue it did not create.
