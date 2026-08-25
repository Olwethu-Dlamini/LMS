# System Architecture & Design Specification
## Leave Management System (LMS)

---

## 1. High-Level Architectural Pattern

The system uses a **Modular Component Architecture** in native PHP 8+. It follows clean separation of concerns without heavy framework overhead:

```
                  +-----------------------------------+
                  |          Web Browser (Client)     |
                  +-----------------------------------+
                                    |
                                    v (HTTP Request)
                  +-----------------------------------+
                  |         Router / Front Entry      |
                  |             (index.php)           |
                  +-----------------------------------+
                                    |
            +-----------------------+-----------------------+
            |                                               |
            v                                               v
+-----------------------+                       +-----------------------+
|  Auth & Session Check |                       | Global CSRF & Sanitizer|
+-----------------------+                       +-----------------------+
            |                                               |
            +-----------------------+-----------------------+
                                    |
                                    v
                  +-----------------------------------+
                  |       Layout Engine (layout.php)  |
                  +-----------------------------------+
                   /        |               |        \
                  /         |               |         \
                 v          v               v          v
          header.php   navbar.php       sidebar.php   footer.php
                                    |
                                    v
                  +-----------------------------------+
                  |      Module View / Logic Page     |
                  |     (e.g., modules/leave/apply)   |
                  +-----------------------------------+
                                    |
                       +------------+------------+
                       |                         |
                       v                         v
            +---------------------+   +---------------------+
            |  LeaveCalculator.php|   | ApprovalWorkflow.php|
            +---------------------+   +---------------------+
                       |                         |
                       +------------+------------+
                                    |
                                    v
                  +-----------------------------------+
                  |       PDO Database Wrapper        |
                  |       (config/database.php)       |
                  +-----------------------------------+
                                    |
                                    v
                  +-----------------------------------+
                  |          MySQL Database           |
                  +-----------------------------------+
```

---

## 2. Directory Structure & Organization

```
/media/oll/Linux/Projects/RI Leave/
│
├── config/
│   ├── database.php             # PDO database connection & configuration
│   └── constants.php            # App constants, state codes, system settings
│
├── docs/                        # Complete Pre-Implementation System Designs
│   ├── 01_SOFTWARE_REQUIREMENTS_SPECIFICATION.md
│   ├── 02_SYSTEM_ARCHITECTURE_DESIGN.md
│   ├── 03_DATABASE_DESIGN_AND_ERD.md
│   ├── 04_APPROVAL_WORKFLOW_STATE_MACHINE.md
│   ├── 05_BUSINESS_RULES_ENGINE_SPECIFICATION.md
│   ├── 06_ROLE_BASED_ACCESS_CONTROL_MATRIX.md
│   └── 07_UI_UX_WIREFRAMES_AND_COMPONENT_MAP.md
│
├── includes/                    # Layout Components & Helper Utilities
│   ├── header.php               # Head tags, CSS imports, theme variables
│   ├── navbar.php               # Top navigation, user profile, notifications
│   ├── sidebar.php              # Dynamic role-filtered navigation menu
│   ├── footer.php               # Footer info, modal containers, JS scripts
│   ├── layout.php               # Primary layout wrapper
│   └── functions.php            # Global helper functions (Auth, Sanitization, CSRF)
│
├── helpers/                     # Business Logic Engine & Service Classes
│   ├── LeaveCalculator.php      # Working days calculation & balance validation
│   ├── ApprovalWorkflow.php     # 3-tier approval state machine & logger
│   ├── Notifier.php             # Who is told what, and in what words
│   ├── EmailQueue.php           # The email_outbox: queue, claim, retry, log
│   ├── EmailTemplate.php        # A notification rendered as HTML and plain text
│   └── Mailer.php               # SMTP delivery (wraps PHPMailer in lib/)
│
├── modules/                     # Role-Based Feature Modules
│   ├── auth/                    # Login, Logout, Password Reset, Profile
│   ├── dashboard/               # Main entry dashboard per role
│   ├── leave/                   # Apply leave, My History, Leave Details
│   ├── manager/                 # Line Manager Approval Portal & Team View
│   ├── hr/                      # HR Approval Portal, Leave Allocations, Reports
│   ├── executive/               # Executive/Boss Approval Portal & Analytics
│   └── admin/                   # User Management, Departments, Leave Types, Holidays
│
├── lib/                         # Vendored third-party code (no composer)
│   └── PHPMailer/               # SMTP client, three source files
│
├── api/                         # JSON endpoints consumed by page scripts
│   ├── calculate_days.php       # Live working-day preview on the apply form
│   └── notifications_poll.php   # Unread count and latest notices for the bell
│
├── tools/                       # Operational CLI scripts
│   ├── create_admin.php         # Bootstrap the first administrator
│   ├── seed_employees.php       # Load the staff roster
│   ├── send_queued_email.php    # The email worker. Runs on cron
│   └── test_email.php           # Ask the mail server what it supports
│
├── uploads/                     # Medical Certificates & Attachment Storage
│   └── attachments/
│
├── assets/                      # Theme Assets
│   ├── css/                     # Custom & Theme CSS
│   ├── js/                      # Bootstrap JS & Application Scripts
│   └── vendor/                  # Bootstrap 5, FontAwesome, DataTables
│
└── index.php                    # Application Front Controller & Routing Gateway
```

---

## 3. Core Component Layout Specification

The user interface uses a modular template layout. Each component handles a distinct part of the page lifecycle:

### 3.1 `includes/header.php`
- Sets document charset, meta tags, and title.
- Loads Bootstrap 5 CSS, Google Fonts, and vendor stylesheets.
- Injects theme custom CSS variables for dark/light mode and branding colors.

### 3.2 `includes/navbar.php`
- Topbar displaying branding logo, quick search, and toggle sidebar button.
- User profile dropdown showing avatar, full name, and active role badge.
- Quick action menu (Apply Leave button, Logout).

### 3.3 `includes/sidebar.php`
- Evaluates `$_SESSION['user_role']` dynamically.
- Renders only authorized menu links based on the user's role:
  - **Employee**: Dashboard, Apply for Leave, My History, Leave Balances.
  - **Line Manager**: Team Requests (Stage 1 Queue), Team Calendar.
  - **HR**: HR Requests (Stage 2 Queue), Leave Allocations, Reports, Holiday Manager.
  - **Executive**: Executive Requests (Stage 3 Queue), High-Level Dashboard.
  - **Admin**: User Accounts, Departments, Leave Types, System Logs.

### 3.4 `includes/layout.php`
- Acts as the primary structural template wrapper:
  ```php
  <?php
  require_once __DIR__ . '/header.php';
  require_once __DIR__ . '/navbar.php';
  ?>
  <div class="main-container">
      <?php require_once __DIR__ . '/sidebar.php'; ?>
      <main class="content-wrapper">
          <?php echo $pageContent; ?>
      </main>
  </div>
  <?php require_once __DIR__ . '/footer.php'; ?>
  ```

---

## 4. Security & Data Flow Strategy

1. **Authentication Gate**: Every module file calls `require_auth()` at top.
2. **Authorization Gate**: Route authorization verified via `require_role(['manager', 'hr', 'admin'])`.
3. **Data Sanitization**: All user input passed through `sanitize_input()` before processing.
4. **Prepared Queries**: All SQL execution wrapped in PDO prepared statements with parameter binding (`:param`).
5. **CSRF Token Verification**: POST requests validated against `$_SESSION['csrf_token']`.

---

## 5. Notification & Email Subsystem

Full protocol-level treatment, configuration reference and troubleshooting are in
[09_EMAIL_AND_SMTP.md](./09_EMAIL_AND_SMTP.md). This section covers the
architecture only.

### 5.1 The governing constraint

> Notification is a courier, never a gate. No notification or email failure may
> delay, block or roll back an approval.

Every structural decision below follows from that sentence. Being told late is a
nuisance; a lost approval is a payroll problem.

### 5.2 Separation of responsibilities

Five units, each understandable without reading the others:

| Unit | Responsibility | Deliberately ignorant of |
|---|---|---|
| `helpers/Notifier.php` | Who is told what, in what words | SMTP, the outbox, templates |
| `helpers/EmailTemplate.php` | A notification as subject + HTML + text | The database, the network |
| `helpers/EmailQueue.php` | The `email_outbox` state machine | SMTP, leave |
| `helpers/Mailer.php` | The SMTP conversation | Leave, the database, the queue |
| `api/notifications_poll.php` | Current bell state as JSON | Everything except `Notifier` |

`EmailTemplate` is entirely static and free of I/O. That is a testability
decision: the wording is the part most likely to be wrong and the hardest to
verify by mailing yourself test messages, so it is asserted on instead.

`Mailer` takes an injectable transport, so the queue can be driven end to end
against a closure that records instead of sending. No test needs credentials, a
network or an inbox.

### 5.3 Two channels, one choke point

Every notification passes through `Notifier::push()`, which writes the bell row
and queues the email. Because there is exactly one such place, the two channels
cannot drift apart and start telling different stories.

The channels differ only in format, and for a reason. The bell gets a single
short line, because the navbar dropdown has room for two rows of small text. The
email gets the same facts as a labelled table, because an approver working
through eleven notices needs to find the dates without reading a sentence. The
two are built separately (`describe()` and `detailsFor()`) rather than one being
parsed out of the other.

### 5.4 Synchronous bell, asynchronous email

```
  request  ->  commit  ->  notifications INSERT  ->  email_outbox INSERT  ->  response
                                (immediate)              (queued)
                                                             |
  cron, every minute                                             v
  tools/send_queued_email.php  ->  claim  ->  SMTP  ->  sent | retry
```

The HTTP request ends before any SMTP is attempted. An approver waits on a
database write, never on a mail server, and never on a socket timeout when that
server is unreachable.

`Notifier` is called *after* `db->commit()` at all three call sites in
`ApprovalWorkflow`, so the leave decision is durable before anything is
attempted on its behalf.

### 5.5 Concurrency in the worker

Claiming is a compare-and-swap on the status column rather than
`SELECT ... FOR UPDATE SKIP LOCKED`, because the application runs on MySQL 8 in
the container and MariaDB on the UAT host, and the CAS behaves identically on
both and on older versions of either.

`attempts` is spent at claim time, so a worker killed mid-send has already paid
for the try. Staleness is measured from `claimed_at`, never `queued_at`:
measuring from the latter would requeue an in-flight message and deliver it
twice.

### 5.6 Degradation

| Failure | Effect |
|---|---|
| Migration 005 not applied | Notifications work, nothing is queued. Logged |
| `MAIL_ENABLED` false | Nothing queued. Bell unaffected |
| Recipient has no usable address | That one skipped and logged |
| Mail server unreachable | Messages delayed and retried, never lost |
| Worker never scheduled | Outbox grows. Visible in `--status` |
| `api/notifications_poll.php` fails | Bell keeps its server-rendered state |
| Session expires | Poll returns 401 and the bell stops polling |

### 5.7 Configuration layering

`config/constants.php` reads three layers, first definition winning:
`config/local.php` (untracked, per server), then environment variables, then
development defaults. Deploying is therefore `git pull` with no tracked file to
edit on a server and no possibility of a pull reverting a production setting.

`APP_URL` is the setting to get right. It is the base of every link in every
notification, and those are rendered by a cron worker with no HTTP request to
infer a hostname from.
