# Changelog

Notable changes to the Leave Management System, newest first. Each entry says
what changed, what it means operationally, and which migrations a server needs
after the pull - because `git pull` moves files and never schema.

---

## 2026-10-02 - Range calendar, real balances, recurring holidays

8 commits, `52fc037..93557c1`, plus this documentation commit. Applied to the
live host the same day: `1418f94..93557c1` by `git pull --ff-only`, which also
brought in `52fc037` (this changelog). Rollback point: `1418f94`.

**Migrations required: none.** Every column the code reads already exists on
the live database (`holidays.is_recurring`, and the migration 008 columns on
`leave_types`).

**No CDN purge needed.** The theme and the picker are now linked through
`asset_url()`, so the new files are fetched on the first page load after the
pull. See *Assets are versioned* below.

### The leave category list shows your own balance

Every option on Apply for Leave used to read "Max: 21 Days/Year", which is
`leave_types.max_days_per_year`: the policy default HR seeds allocations from,
the same for everybody, and not what anybody actually had. It now reads the
applicant's `leave_entitlements` row for the year on the form: *Annual Leave (16
days available)*, *(no allowance)* where nothing is allocated, and *Emergency
Leave (from Annual Leave, 16 days available)* for a category that spends another
balance. Same arithmetic as the dashboard and `validateEligibility()`: total -
used - pending.

### Recurring holidays now recur

The **Recurring** tick on Administration > Holidays was saved and never read: the
working-day count matched `holiday_date` exactly, so "Christmas Day, 2026-12-25,
recurring" counted in 2026 and not in 2027. `LeaveCalculator::getHolidays()` now
repeats a recurring holiday on its day and month in every later year, never
reaches back before the year it was entered for, and skips 29 February outside
leap years. The working-day count, the live preview, department cover, the team
calendar and the new Apply calendar all read through it, so they agree.

**Operational effect on the live data.** New Year's Day, Workers' Day, Freedom
Day, Christmas Day and Boxing Day are marked recurring, so they now close those
dates in 2027 and later without new rows. Good Friday and Easter Monday are not,
and must still be entered each year. Requests already submitted keep the day
count they were saved with; nothing is recalculated.

### One range calendar for the leave dates

The two browser date inputs are replaced by a booking-style range calendar
(Litepicker 2.0.12, MIT, vendored in `assets/plugins/litepicker/`, wrapped by
`assets/js/leave-range-picker.js`): two months on a laptop, one on a phone, the
start and end marked and the days between shaded. While choosing, the tooltip
counts working days with the server's rules, "8 working days · 11 days".

- The working week is Monday to Friday: weekends and public holidays cannot be a
  first or last day, but a range can cross them. Holidays show their name.
- The earliest date follows the category's notice period, now computed in local
  time; the old code used UTC and offered yesterday's floor before 02:00.
- The submitted fields are unchanged hidden `start_date` / `end_date` in
  `YYYY-MM-DD`, so the server's checks, the live summary and the cover panel
  work exactly as before. The server remains the judge of every rule.
- A submit without dates opens the calendar instead of reaching the server.

Litepicker's upstream repository is archived and receives no fixes. It is used on
this one page; replacing it means changing that page only.

### Phone layout

- Apply for Leave: the wide Submit button ran up to 75px past the edge below
  414px, so the page scrolled sideways. The buttons now stack, Submit first.
- Team calendar: the month navigation was one 386px row. It now wraps on small
  screens. The month grid still scrolls inside its own frame, as before.
- Checked at 320, 360, 390 and 414px: dashboard, apply, history, team calendar,
  notifications and change password all fit.

### Assets are versioned

`asset_url()` in `includes/functions.php` appends a file's modification time as
`?v=`. `assets/css/ri-theme.css` on every page and the picker files on Apply for
Leave go through it, so a deploy that rewrites them changes their URL and nobody
is served the old copy from a browser or Cloudflare cache.

### Documentation

README (technology stack corrected: Bootstrap 4.4.1 and jQuery, not Bootstrap 5,
FontAwesome and DataTables), requirements (FR-LEAVE-01, -02, new -07),
architecture (asset tree, new section 6 on dates, holidays and balances),
database (`is_recurring`, `max_days_per_year`), business rules (BR-HOL-01..03,
BR-BAL-06), UI wireframe for Apply, the user manual in Markdown and HTML, and the
deployment guide (versioned assets, `--ff-only` and rollback). The Word manual is
regenerated, which also brings it up to date with the 2026-09-21 release.

### Verified

- `tests/test_suite.php`: 236 passed, 0 failed, on PHP 8.2 and on PHP 8.4 (the
  live host's version). Five new cases cover recurring holidays.
- In a browser against a local copy, as three staff with 16, 8 and 2.5 days left:
  each saw their own balance; the calendar locked dates inside the notice period
  and weekends; 21-31 December showed "8 working days" in the tooltip and the
  server counted 8; a rejected request kept its dates; New Year 2027 appeared on
  the team calendar.
- Live after the pull: the login page links `ri-theme.css?v=...`, and the theme,
  picker script and picker stylesheet are all served (200). The Apply page on the
  live host was checked by hand.

### Known gaps

- Carried over: `MAIL_REPLY_TO` is the sending mailbox on the live host (emails
  say replies go to `lms@realnet.co.sz`), while the user manual tells staff
  replies reach `info@realnet.co.sz` and that nobody reads `lms@`. Which mailbox
  replies should reach is undecided; the manual is unchanged until it is.
- The holiday list holds seven 2026 dates. Eswatini holidays such as King's
  Birthday (19 April) and Independence Day (6 September) are not on it, so they
  are counted as working days until HR adds them.

---

## 2026-09-21 - Single-stage approval, emergency leave, per-role notification

25 commits, `ff3d641..1418f94`. Applied to the live host the same day.

**Migrations required, in this order:**

```bash
./tools/export_database.sh --dump-only     # 007 settles balances irreversibly
git pull
mysql lms_db < migrations/007-single-stage-approval.sql
mysql lms_db < migrations/008-emergency-leave.sql
```

Purge the CDN afterwards: much of the UI change is in `assets/css/ri-theme.css`.

### One approval decides a request

The three-stage chain is gone. A request is decided by a single approver and
that decision is final, routed by the applicant's own role so nobody signs off
on their own leave:

| Applicant | Decided by |
|---|---|
| Employee | Their line manager, or the head of their department |
| Line Manager | HR |
| Executive | HR |
| HR | The executive |
| System Admin | Cannot apply; may approve on any queue as break-glass |

The three approval screens remain, each now holding a different kind of
applicant rather than a different stage of the same request. The balance is
deducted on the only approval rather than on the third.

`migrations/007` settles applications that were sitting in an HR or executive
queue having already had the approval the new rule consults - an employee's
request at HR has its line manager's decision - by approving them and moving
their days from pending to used. It prints exactly which applications that is
before it writes anything, and writes no audit-log rows: `approver_id` is
`NOT NULL`, there is no system account, and attributing a settlement to
somebody who did not decide it would put a false entry in the one table meant
to be trustworthy.

**It notifies nobody.** The migration is SQL and never passes through
`Notifier`, so the affected applicants find out by opening their history. Tell
them.

### Emergency Leave

A category with no allowance of its own: the days come off the person's Annual
Leave. No notice period, no cap on a single request, and it may be dated in the
past, so it covers something that has already happened. It still needs its one
approval - instant in what it permits, not in bypassing the approver - and the
approver's notice says it is an emergency.

Three new columns on `leave_types`, all editable in the admin console rather
than hardcoded against a category code:

| Column | Meaning |
|---|---|
| `deducts_from_type_id` | The category whose entitlement this one spends. Resolved in one hop; a self-reference or a missing row falls back to its own balance |
| `allow_negative_balance` | The request may pass a balance it exceeds. A *missing* allocation row is still refused - there would be nothing to deduct from |
| `notify_as_urgent` | Approvers are told about it as urgent, and it is emailed even to the roles that get no queue email |

A category that spends another's balance is never seeded an entitlement row and
cannot be allocated by hand, so nobody is given an emergency balance to exhaust
and no dashboard tile appears for it.

### Notifications, and who gets email

- Approvers are told their decision is final and that approving books the
  leave. That is the change that matters to a line manager who was one
  signature of three.
- Applicants are told who decides their request, and on approval that the days
  have already left their balance.
- **HR and executives get no "awaiting your approval" email.** They decide
  leave for everybody senior, so one message per request would fill the
  mailboxes of the two roles least able to start ignoring their mail. Their own
  leave still emails them, line managers keep both channels, and **anything
  urgent overrides the rule** - an emergency must not wait for somebody to open
  a screen.
- A suppressed notice writes no `email_outbox` row, so a notification row
  without one is the rule applying rather than the queue failing.

### Screens

- **Company leave overview.** `Away This Week` followed the viewer's own
  department, which showed an HR manager four people and an HR manager with no
  department the words "you are not assigned to a department". It now follows
  the scope each role already has: own department for an employee, every
  department they approve for a line manager, the whole company with an
  away-today count for HR, executives and administrators.
- **Only approved leave counts as away.** The dashboard was counting undecided
  requests and the calendar was not, so the same day read "3 away" on one page
  and "1/8" on the other. Requests are shown apart as `+n`.
- **HR allocations, one row per person.** It listed a row per entitlement, so
  everybody appeared once per category with their name reprinted each time -
  thirty-two people as ninety-six rows, two thirds of them the zeroes migration
  006 left behind. Now one row per person with the main category on it and the
  rest in a drop-down, a year selector, a filter box, and every figure
  clickable to open the allocation form pre-filled. It is also driven from the
  staff list rather than the entitlement rows, so somebody with no allocation
  at all finally appears on the page where HR can give them one.
- The Stage 1/2/3 language is gone from both navigation bars, every queue
  screen, the apply form, the history timeline, the audit log and the error
  messages.

### Decisions recorded, not just implemented

- **Sign-in rate limiting is off.** `LOGIN_THROTTLE_ENABLED` stays `false` on
  development and on the live server. The consequence is written next to the
  setting in every place that used to tell you to turn it on: the form accepts
  guesses as fast as they arrive, against addresses that follow a predictable
  pattern, and nothing records the attempts. `LoginThrottle`, the
  `login_attempts` table and migration 004 all remain, so it is one constant to
  reverse.
- `docs/11_LESSONS_FROM_GOING_LIVE.md` section 13 has the reasoning behind the
  approval change, the notification split and the balance sourcing, written
  while it was still available.

### Fixed along the way

- `tools/export_database.sh` and `uat.sh` were committed without the execute
  bit while the docs told you to run them as `./tools/export_database.sh`.
- One test asserted `LOGIN_THROTTLE_ENABLED === false`, which is the
  development default, so the suite reported a correctly hardened server as a
  failing build the first time it was ever run on one.
- The test mock's user row carried no email address, so on a host with
  `MAIL_ENABLED=true` every notification took `EmailQueue`'s "no usable
  address" branch and the suite never exercised queueing at all.

### Known gaps

- `docs/08_USER_MANUAL.docx` is not regenerated; it needs LibreOffice. The
  Markdown and the self-contained HTML are current.
- `MAIL_REPLY_TO` is the sending mailbox on the live host, while the user
  manual tells staff replies reach `info@realnet.co.sz`.
