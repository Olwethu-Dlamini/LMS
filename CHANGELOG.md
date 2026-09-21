# Changelog

Notable changes to the Leave Management System, newest first. Each entry says
what changed, what it means operationally, and which migrations a server needs
after the pull - because `git pull` moves files and never schema.

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
