# Software Requirements Specification (SRS)
## Leave Management System (LMS)

---

## 1. Introduction

### 1.1 Purpose
The purpose of this document is to define the complete functional, technical, and architectural requirements for the **Leave Management System (LMS)**. This system automates the leave request, calculation, multi-level approval, and tracking process within an organization.

### 1.2 Scope
The LMS is a web-based PHP application built with a modular component-based architecture. It provides an automated, multi-tiered approval workflow for leave applications, ensuring policy compliance, accurate working day calculations, and real-time leave balance management.

### 1.3 Key Objectives
- Automate leave application submission, tracking, and balance management.
- Enforce a 3-tier approval hierarchy (**Line Manager** ➔ **HR** ➔ **Executive / Boss**).
- Exclude weekends and public holidays automatically during leave day calculations.
- Support role-based access control (RBAC) with granular permissions across 5 distinct user roles.
- Provide responsive, modular Bootstrap-themed interfaces.

---

## 2. User Roles & Hierarchy

The system defines 5 primary user roles:

| Role ID | Role Name | Description & Key Responsibilities |
|---|---|---|
| **1** | **Employee** | Submits leave applications, views personal balances, tracks approval status, and views leave history. |
| **2** | **Line Manager** | Reviews team leave applications, approves/rejects Stage 1 requests, views team calendar. |
| **3** | **HR Manager** | Reviews Stage 2 approvals, manages leave allocations/entitlements, manages holiday calendar, generates reports. |
| **4** | **Executive / Boss** | Final authority (Stage 3) for executive-level approvals, views high-level dashboard & company-wide leave stats. |
| **5** | **System Admin** | Manages system users, departments, leave types, system settings, and audit logs. |

---

## 3. Functional Requirements

### 3.1 Authentication & Session Management
- **FR-AUTH-01**: Users must authenticate using email and encrypted password (PHP `password_hash` with BCRYPT).
- **FR-AUTH-02**: System must enforce role-based access control (RBAC) via session middleware (`check_auth()`, `has_role()`).
- **FR-AUTH-03**: Secure logout and session destruction with CSRF protection on form submissions.

### 3.2 Leave Application & Calculation Engine
- **FR-LEAVE-01**: Employees can apply for leaves by selecting leave type, start date, end date, and reason.
- **FR-LEAVE-02**: System must calculate **net working days** excluding Saturdays, Sundays, and official public holidays.
- **FR-LEAVE-03**: System must validate requested days against the employee's available leave entitlement.
- **FR-LEAVE-04**: System must prevent submission if dates overlap with existing pending or approved requests.
- **FR-LEAVE-05**: System must require file attachment (medical certificate) if Sick Leave exceeds specified threshold (e.g. > 2 days).

### 3.3 Multi-Level Approval Workflow Engine
- **FR-WF-01**: 3-Stage Approval Sequence:
  - **Stage 1 (Pending Line Manager)**: Assigned to the applicant's direct manager.
  - **Stage 2 (Pending HR)**: Triggered after Line Manager approval.
  - **Stage 3 (Pending Executive/Boss)**: Final sign-off required for request completion.
- **FR-WF-02**: Approvers can accept or reject requests with mandatory/optional comments.
- **FR-WF-03**: Rejection at any stage immediately halts the workflow, sets status to `Rejected`, and releases reserved pending days back to the employee's balance.
- **FR-WF-04**: Final approval at Stage 3 deducts days from `used_days` and marks status as `Approved`.

### 3.4 Dashboards & Reporting
- **FR-DASH-01**: Employee Dashboard displaying balance breakdown (Annual, Sick, Casual, etc.) and application timeline.
- **FR-DASH-02**: Approver Queue displaying pending requests grouped by current stage.
- **FR-DASH-03**: HR Dashboard with team availability calendar and exportable CSV reports for payroll integration.
- **FR-DASH-04**: Admin Panel for managing users, departments, leave policies, and public holidays.

---

## 4. Non-Functional Requirements

### 4.1 Security
- **NFR-SEC-01**: All database interactions must use PHP PDO prepared statements to eliminate SQL Injection risks.
- **NFR-SEC-02**: Inputs must be sanitized using `htmlspecialchars()` to prevent XSS vulnerabilities.
- **NFR-SEC-03**: File uploads must validate MIME types (PDF, PNG, JPG) and restrict maximum size (5MB).

### 4.2 Performance & Reliability
- **NFR-PERF-01**: Page response times must be under 300ms for typical dashboard requests.
- **NFR-PERF-02**: Database indexing on foreign keys (`user_id`, `department_id`, `status`, `start_date`, `end_date`).

### 4.3 Maintainability & Component Architecture
- **NFR-MAINT-01**: UI code must follow a clean modular structure (`header.php`, `navbar.php`, `sidebar.php`, `footer.php`, `layout.php`).
- **NFR-MAINT-02**: Strict separation between core business logic (`helpers/`) and UI view rendering (`modules/`).
