# Leave Management System (LMS)

A robust, multi-role PHP Leave Management System built with a modular component architecture (Header, Navbar, Sidebar, Footer, Layout), automated working days calculation engine, and a 3-tier sequential approval workflow (**Line Manager** ➔ **HR** ➔ **Executive / Boss**).

---

## 📚 Complete Pre-Implementation System Design Documentation

Before any code implementation begins, full architecture and system design blueprints have been established in the [`docs/`](./docs/) directory:

1. 📄 [01_SOFTWARE_REQUIREMENTS_SPECIFICATION.md](./docs/01_SOFTWARE_REQUIREMENTS_SPECIFICATION.md) - Software Requirements Specification (SRS) & User Roles
2. 🏛️ [02_SYSTEM_ARCHITECTURE_DESIGN.md](./docs/02_SYSTEM_ARCHITECTURE_DESIGN.md) - Modular PHP Architecture & System Component Design
3. 🗄️ [03_DATABASE_DESIGN_AND_ERD.md](./docs/03_DATABASE_DESIGN_AND_ERD.md) - Database ERD, Data Dictionary & Full SQL Schema (`schema.sql`)
4. 🔄 [04_APPROVAL_WORKFLOW_STATE_MACHINE.md](./docs/04_APPROVAL_WORKFLOW_STATE_MACHINE.md) - 3-Stage Approval Flow State Machine Specification
5. ⚙️ [05_BUSINESS_RULES_ENGINE_SPECIFICATION.md](./docs/05_BUSINESS_RULES_ENGINE_SPECIFICATION.md) - Working Days Engine, Holiday Exclusions & Balance Rules
6. 🛡️ [06_ROLE_BASED_ACCESS_CONTROL_MATRIX.md](./docs/06_ROLE_BASED_ACCESS_CONTROL_MATRIX.md) - RBAC Permission Matrix across 5 User Roles
7. 🎨 [07_UI_UX_WIREFRAMES_AND_COMPONENT_MAP.md](./docs/07_UI_UX_WIREFRAMES_AND_COMPONENT_MAP.md) - Page Layout Wireframes & Bootstrap Theme Token Map

---

## 📘 User Manual

End-user documentation covering all five roles, the leave rules, the holiday
calendar and troubleshooting. Same content in three formats — edit the Markdown,
then regenerate the other two:

| Format | File | Use it for |
|---|---|---|
| **Markdown** | [`docs/08_USER_MANUAL.md`](./docs/08_USER_MANUAL.md) | The source of truth. Renders on GitHub, diffs cleanly in review. |
| **Web page** | [`docs/user-manual.html`](./docs/user-manual.html) | Self-contained — fonts and styles are inlined, so it works offline from a share drive and prints to a clean 15-page A4 document. |
| **Word** | [`docs/08_USER_MANUAL.docx`](./docs/08_USER_MANUAL.docx) | Handing to HR for distribution or intranet upload. Real Word heading styles, so Word's navigation pane and table of contents work. |

Regenerating the Word version after editing the Markdown:

```bash
python3 tools/md_to_word_html.py docs/08_USER_MANUAL.md /tmp/manual.html
soffice --headless --convert-to "docx:MS Word 2007 XML" --outdir docs /tmp/manual.html
mv docs/manual.docx docs/08_USER_MANUAL.docx
```

---

## 👥 Supported Roles & Approval Pipeline

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

## 📅 Coverage & Notifications

**Team calendar** (`modules/leave/team_calendar.php`) — a server-rendered month per
department: who is off each working day and how many that leaves away out of the
team. Approved leave and requests still awaiting a decision are drawn, counted and
labelled apart — solid against the headcount for the first, dashed and counted as
`+n` for the second — because a day reading "3 away" is unplannable when one of
them is off and two have only asked. A legend names both. Your own entries read
"You". Weekends and public holidays are never counted as absence.
Employees see their own department without leave categories, since sick and
maternity leave should not be disclosed to colleagues; managers see the
departments they approve for; HR, executives and admins see any department.

**Coverage limits** — `departments.max_concurrent_absences` sets how many members
may be away at once, edited per department in the admin console. Every approval
stage shows a notice saying whether *this* request is what tips the department
over, or whether it was already short, and the same notice appears on the apply
form. The calendar itself no longer shades days amber and red for this: whole-day
shading competed with the entries inside the cell and coloured days by a threshold
most departments never set. Nothing is blocked anywhere: sick leave does not wait
for a convenient rota.

**Before you book** — the apply form answers the coverage question while the
dates can still change. Once a category and a range are chosen it names who else
in your department is already off then, for how many days, and whether the
request would take the team past its limit. Same wording as the approval queues,
given to the person who can still move the dates. Nothing is blocked.

**In-app notifications** — a bell with an unread count in both navigation bars.
Applicants hear about submission, each stage cleared, approval, rejection (with
remarks) and cancellation; approvers hear when a request reaches their queue or is
withdrawn from it. Recipients are derived from the workflow, so reassigning a
department's head redirects future notices. Notices are raised after each commit
and every write is guarded, so a notification problem can never roll back an
approval. There is no email dependency.

The shared engines are `helpers/LeaveCapacity.php` (who is away, and does that
break cover), `helpers/Notifier.php` (who to tell, and what to say) and
`helpers/DashboardInsights.php` (the dashboard figures). The day arithmetic and
aggregation in each are pure functions, covered by the test suite.

---

## 🛠️ Technology Stack

- **Core**: PHP 8.0+ (PDO, Native Sessions, Clean Modular Component Architecture)
- **Database**: MySQL 8.0 / MariaDB
- **Frontend / Styling**: HTML5, Vanilla CSS, Bootstrap 5, FontAwesome, DataTables
- **Architecture**: Front Controller Routing with Component-based Layout

---

## 🚀 Setup & Database Installation

### Standard install

1. Import the schema (`schema.sql` is the executable one — `docs/03_…md` is
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
```

Each is safe to re-run and ends with a check query you can read to confirm it took.

| Migration | What it does |
|---|---|
| `001-role-aware-routing` | Moves in-flight applications onto role-aware routing. No schema change. |
| `002-calendar-and-notifications` | Adds `departments.max_concurrent_absences` and the `notifications` table. |
| `003-decode-double-escaped-text` | Repairs text stored HTML-escaped, so `Sales &amp; Marketing` reads as `Sales & Marketing` again. |
| `004-login-attempts` | Adds the `login_attempts` table behind the sign-in rate limit. |

The portal keeps working ahead of each of these rather than failing. Until `002`
the notification bell stays hidden and the calendar shows no coverage limits;
until `004` sign-in simply is not rate limited. An installation is never locked
out of itself because a migration has not run yet.

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
only — roles, departments, leave categories and holidays — so a fresh database
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

## 👤 Staff Accounts & Passwords

### Loading the staff roster

`tools/seed_employees.php` loads the Real Image roster. It is idempotent — it
skips anyone whose email already exists, so it is safe to re-run as the roster
grows.

```bash
php tools/seed_employees.php                      # dry run: shows what it would create
php tools/seed_employees.php --commit             # write the accounts
php tools/seed_employees.php --commit --reissue   # fresh temp password for everyone
                                                  # still awaiting a first sign-in
```

Use `--reissue` if the credential list is lost — stored passwords are bcrypt
hashes and cannot be recovered, only replaced. The CSV is **appended**, never
overwritten, so previously issued passwords are never destroyed; where an email
appears more than once, the newest row wins.

Each new account gets **its own random temporary password** and is flagged
`must_change_password`. The temporary passwords are written to
`$HOME/.ri-leave-uat/initial-passwords.csv` (mode `0600`, deliberately **outside
the repository** so they can never be committed). Distribute them securely,
then delete the file.

New starters are created as plain `employee` with no department and no reporting
manager. Assign those in **Administration → User Management** — until a user has
a manager, only an admin can clear Stage 1 for them.

### Password rules

- Minimum 10 characters, with at least one uppercase, one lowercase and one number.
- Users change their own password via **Change Password** in the top utility strip.
- Anyone flagged `must_change_password` is held on the change-password screen and
  cannot reach the rest of the app until they set their own password.
- An admin password reset (**User Management → Password**) is always treated as
  temporary and re-raises that flag.
- Sign-in rate limiting exists but is **switched off** while the system is in
  testing — see `LOGIN_THROTTLE_ENABLED` in `config/constants.php`. Switched on,
  five failures for the same address and email inside fifteen minutes stops
  answering until the window passes, and a successful sign-in clears the count.
  Counting per email *and* caller together is deliberate: per email alone would
  let anyone lock a colleague out on purpose. **Turn it on before go-live.**
- Password fields carry a show/hide eye, so a temporary password full of
  punctuation can be checked before it is submitted rather than after being
  locked out by it.

---

## ⚙️ Admin Console

Administrators get a separate console at `/modules/admin/index.php`, reachable
from the **Admin Console** link in the staff nav. It carries its own dark
navigation containing system administration only — no Apply, My Leave or
Approvals — with a **Staff Portal** switcher back, so an admin can still book
their own leave. Every `/modules/admin/*` route is gated by
`require_role(ROLE_ADMIN)`.

| Section | What it does |
|---|---|
| **Overview** | Counts plus a *Setup Attention* panel flagging users with no manager or department, accounts still on a temporary password, and a missing holiday calendar. |
| **Users** | Create, edit, reset password, archive/restore, delete. |
| **Departments** | Create, rename, reassign head, delete (blocked while members remain). |
| **Leave Types** | Full rule configuration — see below. |
| **Holidays** | Add, edit, delete. Changes affect future calculations only. |
| **Audit Log** | Every approval and rejection, filterable by action, role and date. |

### Deleting is deliberately guarded

`leave_applications` and `leave_approval_logs` are wired `ON DELETE CASCADE`, so
a naive user delete would erase that person's leave history and the audit trail
with them. Instead:

- **Users** — Delete permanently removes the account *only* when it has zero
  applications and zero approval log entries. Anything with history is
  **archived** instead, and you are told why. You cannot remove your own account
  or the last active administrator.
- **Leave types** — a category referenced by any application is **retired**
  rather than deleted: history and reports stay intact and it disappears from
  the apply form.
- **Departments** — cannot be deleted while they still have members.

### Configurable leave rules

Each leave type carries its own policy, enforced server-side in
`LeaveCalculator::validateEligibility()` and mirrored as hints on the apply form:

| Setting | Meaning |
|---|---|
| `max_days_per_year` | Annual allocation used when seeding entitlements |
| `min_days_per_request` | Smallest bookable request |
| `max_days_per_request` | Largest single request; blank means no cap. A request is one contiguous range, so this also caps consecutive days within it |
| `allow_half_day` | Whether half-day options are offered at all. A half day applies to a single day: choosing one across a longer range is refused, not quietly counted as the range minus half a day |
| `min_notice_days` | Days of advance notice required. **0 also permits backdating**, which is what lets sick leave be recorded after the fact — the apply form's date picker takes its earliest date from this rule, so a zero-notice category has no floor at all |
| `requires_attachment` + `attachment_threshold_days` | Demand a document only once a request exceeds N working days. This replaces what was a hardcoded "sick leave over 2 days" rule |
| `is_paid` | Paid or unpaid |
| `is_active` | Retired types vanish from the apply form but stay in reports |

Shipped defaults: Annual 7 days notice; Casual max 3 days per request with
1 day notice; Sick no notice with a document over 2 days; Maternity and Unpaid
whole days only, Unpaid needing 14 days notice.

---

## 🔐 Supporting documents

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
still open — the path is read from the application rather than rebuilt.

Accepted: PDF, JPG, PNG, up to 5 MB. A document that fails to store fails the
whole submission, rather than leaving a request in a queue looking complete
without the certificate it depends on.

---

## ✅ Before go-live

- [x] ~~Delete the five `@lms.com` demo accounts and remove them from `schema.sql`.~~
      Done — no accounts are seeded at all. Bootstrap with `tools/create_admin.php`.
- [ ] Change `APP_URL` in `config/constants.php` to the production hostname.
- [ ] Move the DB credentials to real environment variables (never commit them).
- [ ] Serve over HTTPS. The session cookie sets `HttpOnly`, `SameSite=Lax` and
      strict session ids itself, and turns on `Secure` as soon as the request
      arrives over HTTPS — no php.ini change needed.
- [ ] Apply every migration in `migrations/`, in order.
- [ ] Block `/uploads/` at the web server if you serve with nginx (Apache is
      covered by the `.htaccess` already in the directory).
- [ ] Set `LOGIN_THROTTLE_ENABLED` to `true` in `config/constants.php`.
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
php tests/test_suite.php     # honours the DB_* environment variables above
```
