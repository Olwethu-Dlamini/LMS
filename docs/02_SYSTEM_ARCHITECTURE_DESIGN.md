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
│   └── NotificationEngine.php   # Email / System notification dispatcher
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
