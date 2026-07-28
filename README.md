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

## 🛠️ Technology Stack

- **Core**: PHP 8.0+ (PDO, Native Sessions, Clean Modular Component Architecture)
- **Database**: MySQL 8.0 / MariaDB
- **Frontend / Styling**: HTML5, Vanilla CSS, Bootstrap 5, FontAwesome, DataTables
- **Architecture**: Front Controller Routing with Component-based Layout

---

## 🚀 Setup & Database Installation

1. Import `docs/03_DATABASE_DESIGN_AND_ERD.md` SQL schema into MySQL server:
   ```bash
   mysql -u root -p < docs/03_DATABASE_DESIGN_AND_ERD.md
   ```
2. Configure DB connection credentials in `config/database.php`.
3. Launch development server:
   ```bash
   php -S localhost:8000
   ```
