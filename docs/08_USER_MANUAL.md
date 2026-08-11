# 📘 User Manual — Leave Management System

**Real Image Internet · Staff Leave Portal**

How to request leave, approve it, and administer the system — written for every
role that uses it.

> Also available as a self-contained web page: [`user-manual.html`](./user-manual.html)
> (open it in any browser, works offline, prints cleanly) and as
> [`08_USER_MANUAL.docx`](./08_USER_MANUAL.docx) for distribution.

---

## Contents

**Start here**
1. [Signing in the first time](#1-signing-in-the-first-time)
2. [What everyone can do](#2-what-everyone-can-do)
3. [How a request travels](#3-how-a-request-travels)

**Your role**

4. [Employee](#4-employee)
5. [Line Manager](#5-line-manager)
6. [HR Manager](#6-hr-manager)
7. [Executive](#7-executive)
8. [System Administrator](#8-system-administrator)

**Reference**

9. [Leave categories](#9-leave-categories)
10. [Public holidays](#10-public-holidays)
11. [Rules that catch people out](#11-rules-that-catch-people-out)
12. [If something goes wrong](#12-if-something-goes-wrong)

---

## 1. Signing in the first time

Your account is created for you by IT. You will be given a temporary password,
and the system will make you replace it before you can do anything else.

1. Open the portal in your browser and enter your **work email address** — the
   same `@realnet.co.sz` address you use for mail.
2. Enter the **temporary password** IT gave you.
3. The system takes you straight to **Set Your New Password**. You cannot reach
   the rest of the portal until this is done — that is deliberate, so no account
   keeps a password somebody else has seen.
4. Type the temporary password once more under *Temporary Password*, then choose
   your own. The four rules tick green as you satisfy them.
5. Confirm it and select **Update Password**. You land on your dashboard, signed in.

### Password rules

Your new password must be at least **10 characters** and include at least one
**uppercase** letter, one **lowercase** letter and one **number**. It cannot be
the same as the temporary one.

**Changing it later** — select **Change Password** in the thin bar at the very
top of any page. You will need your current password to set a new one.

**If IT resets your password** — an administrator can issue you a new password,
but it always arrives as a temporary one. You will be asked to set your own the
next time you sign in.

---

## 2. What everyone can do

Every person in the company — including managers, HR and administrators — has
these four things, because everybody takes leave.

### Your dashboard

The landing page shows a tile per leave category you have been allocated. Each
tile gives the days you have left, with the full breakdown underneath:

| Figure | Meaning |
|---|---|
| **Total** | Your allocation for the year. |
| **Used** | Days on requests that have been fully approved. |
| **Pending** | Days on requests still working through approval. They are held back so you cannot accidentally spend the same days twice. |
| **Days available** | Total, minus used, minus pending. This is the number you can actually book. |

### Applying for leave

1. Select **Apply** in the top bar, or **My Leave → Apply for Leave**.
2. Choose a **leave category**. A line of grey text appears underneath listing
   that category's rules — notice required, minimum and maximum length, and
   whether half-days are allowed.
3. Pick your **start** and **end** dates. If the category demands notice, the
   date picker will not let you choose anything sooner.
4. Choose a **duration type**: full days, or a half day (morning or afternoon).
   Half-day options are greyed out for categories that must be taken as whole days.
5. Watch the **live summary**. It counts the working days, tells you your
   remaining balance, and warns you about anything that would block the request —
   before you submit.
6. Give a **reason**, attach a document if the category needs one, and select
   **Submit Application**.

You are returned to your history with a confirmation and a reference number in
the form `LV-2026-XXXXXX`. Quote it if you need to ask about the request.

> **How days are counted.** Only working days count. Saturdays, Sundays and every
> public holiday in the calendar are skipped automatically, so a Monday-to-Friday
> request over a week containing a holiday costs you four days, not five. A half
> day costs 0.5.

### Tracking and cancelling

**My Leave → My Leave History** lists everything you have ever submitted with its
current status. Select **View Details** to see who has signed it off, when, and
any remarks they left.

**Cancel** withdraws a request. Cancel one that is still in approval and the held
days return to your balance immediately. Cancel one that was already approved and
the used days are given back.

---

## 3. How a request travels

Every application passes through the same three stages, in order. It only becomes
approved leave after the third.

```
  [ You submit ]
        │
        ▼
  Stage 1 · Line Manager ──(reject)──► Rejected, days returned
        │ approve
        ▼
  Stage 2 · HR Manager ────(reject)──► Rejected, days returned
        │ approve
        ▼
  Stage 3 · Executive ─────(reject)──► Rejected, days returned
        │ approve
        ▼
  APPROVED — days deducted
```

| Stage | Who | What they check |
|---|---|---|
| **1** | Line Manager | Your own manager checks the request against team cover. |
| **2** | HR Manager | HR verifies entitlement, balance and policy. |
| **3** | Executive | Final sign-off. **Only now are the days deducted.** |

A rejection at *any* stage ends the request there and returns the held days to
you. It does not carry on to the next approver.

### What the statuses mean

| You will see | It means | Days are |
|---|---|---|
| Pending Line Manager | Waiting on Stage 1. | Held |
| Pending HR Review | Cleared Stage 1, waiting on HR. | Held |
| Pending Executive Approval | Cleared HR, waiting on final sign-off. | Held |
| Approved | Fully approved. The leave is yours. | **Deducted** |
| Rejected | Declined. The reason is in View Details. | Returned |
| Cancelled | Withdrawn by you, HR or an administrator. | Returned |

---

## 4. Employee

The standard account. You request your own leave and follow its progress.

**You can**

- See your balances for every category you hold
- Apply for leave and preview the day count live
- Track each request through all three stages
- Read approver remarks on your requests
- Cancel a request you no longer need
- Change your own password

**You cannot**

- Approve anything, including your own leave
- See anyone else's leave or balances
- Change your own allocation — that is HR's
- Reach the approval or administration pages

> **If you have no manager yet.** Your request needs a line manager to clear
> Stage 1. If nobody is set as your manager, it will sit unactioned. Ask an
> administrator to set your reporting manager in User Management.

---

## 5. Line Manager

Stage 1. You are the first decision on your team's leave, and the gate before HR
ever sees it.

### Clearing your queue

1. Go to **Approvals → Stage 1 · Line Manager**. The heading shows how many
   requests are waiting.
2. Read the row: who, which category, the dates, the working days it costs, their
   stated reason, and a **File** link if they attached a document.
3. Select **Review / Action** to open the request.
4. Add remarks. Do this especially when rejecting — your note is what the
   employee sees as the explanation.
5. Choose **Approve Stage 1** to pass it to HR, or **Reject Request** to decline
   it and release the days.

### Whose leave you see

Your queue is filtered to your own people. A request reaches you if the employee
has you set as their **reporting manager**, or if you are the **designated head**
of their department. You will never see another manager's team.

> **You cannot approve your own leave.** The system blocks self-approval outright.
> Your own requests go to whoever manages you, exactly like anyone else's.

Everything in [What everyone can do](#2-what-everyone-can-do) applies to you as
well — you still apply for your own leave through the same form.

---

## 6. HR Manager

Stage 2, plus ownership of what everyone is entitled to and all company-wide
reporting.

### Stage 2 approvals

**Approvals → Stage 2 · HR Review** holds everything a line manager has already
cleared. The queue works exactly like Stage 1: review, add remarks, then approve
to send it to the Executive, or reject to end it. Approving here does *not*
deduct days — only the final stage does.

### Leave allocations

**HR Management → Leave Allocations** is where balances are set.

- **Allocate / Update** — set one person's days for one category in one year. Use
  it for a mid-year joiner or an agreed exception.
- **Bulk Initialize Year** — give every active employee the standard allocation
  for a new leave year in one action. This is the normal start-of-year task.

The table shows total, used, pending and remaining per person, so you can see at a
glance who is close to exhausting a category.

### Reports and payroll export

**HR Management → Leave Reports** covers the whole company. Filter by
**department**, **status** and a **date range**, then use **Export Filtered CSV**
to download exactly the rows on screen — the export honours your filters, so what
you see is what you get. The file opens in Excel and is intended for payroll.

> **Also yours.** HR can cancel any application, not just their own, for cases
> where leave needs unwinding after the fact.

---

## 7. Executive

Stage 3, the final authority. Nothing becomes approved leave without you.

### Final sign-off

**Approvals → Stage 3 · Executive Sign-Off** lists requests that both the line
manager and HR have already backed. Your decision is the last one.

- **Approve** — the request becomes *Approved* and, at this moment, the days move
  out of the employee's balance for real.
- **Reject** — the request ends and the held days go straight back, even though
  two approvers had already agreed.

Your dashboard shows how many requests are waiting on you, so the count is
visible the moment you sign in.

> **Worth knowing.** Because the deduction happens here, a balance only changes
> when you act. Anything sitting in earlier stages is held, not spent — which is
> why an employee's available days can look lower than their used days suggest.

---

## 8. System Administrator

You run the system itself: who has an account, how the company is structured, and
what the leave rules are.

### The Admin Console

Select **Admin Console** in the top bar. The navigation turns dark — that is how
you know you are in administration rather than the staff portal. It holds
administration only; there is no Apply or Approvals here. **Staff Portal** on the
right takes you back, and you keep your own leave account.

| Section | What it does |
|---|---|
| **Overview** | Counts across the system, plus a *Setup Attention* panel that flags people with no manager or department, accounts still on a temporary password, and a missing holiday calendar. Start here. |
| **Users** | Create, edit, reset passwords, archive, restore and delete accounts. |
| **Departments** | Create and rename units, and set each one's designated head. |
| **Leave Types** | Every rule behind every leave category. |
| **Holidays** | The dates excluded from all working-day counting. |
| **Audit Log** | Every approval and rejection ever recorded, filterable by action, role and date. |

### Adding someone

1. **Users → Create New User**.
2. Fill in employee ID, name, work email and a starting password.
3. Set their **role**, **department** and **reporting manager**. The manager
   matters most — without one, that person's Stage 1 can only be cleared by an
   administrator.
4. Save. Their standard allocations for the current year are created
   automatically from the leave categories.
5. Give them the starting password. They will be forced to replace it when they
   first sign in.

### Removing someone

Deleting a person would take their leave history and the approval audit trail
with them, so the system will not simply do it. **Delete** behaves in one of two
ways:

- The account has **no leave history** — it is removed permanently. Use this for
  an account created by mistake.
- The account **has history** — it is **archived** instead, and you are told why.
  Archiving stops them signing in and removes them from manager and approver
  lists, while every record survives. This is the right outcome for a leaver.

**Restore** reverses an archive at any time.

> **Two things you cannot do.** You cannot archive or delete the account you are
> signed in with, and you cannot remove the last remaining active administrator.
> Both would lock everybody out. Promote a second administrator first.

### Configuring leave categories

**Leave Types** is where policy actually lives. Every setting below is per
category, and every one is enforced when somebody applies:

| Setting | Meaning |
|---|---|
| **Days per year** | The standard allocation given to new starters and by Bulk Initialize. |
| **Minimum per request** | The smallest bookable amount. |
| **Maximum per request** | The longest single request. Leave it blank for no cap. |
| **Advance notice** | Days of warning required before the start date. Set it to **0** to allow same-day booking *and* backdating — which is exactly what lets sick leave be recorded after the fact. |
| **Half days allowed** | Whether the morning and afternoon options appear at all. |
| **Requires a document** | Combined with a threshold, so you can demand a certificate only once a request passes a certain length. |
| **Paid** | Whether the category is paid or unpaid. |
| **Available on the apply form** | Clear this to retire a category. It vanishes from the form but stays in historical reports. |

Deleting a category follows the same principle as users: one that no application
has ever used is removed, and one that is in use is **retired** instead so past
records keep their meaning.

### Departments and holidays

A department cannot be deleted while people still belong to it — move them first.
Holidays can be added, edited and removed freely, but a change only affects
**future** calculations; leave that has already been submitted keeps the day count
it was approved with.

> **Administrators can approve at any stage.** An administrator can act on
> Stage 1, 2 or 3. Use it to unblock a queue when an approver is away — but the
> audit log records that it was you.

---

## 9. Leave categories

The categories as currently configured. An administrator can change any of these
in the Admin Console, so treat the portal as the authority.

| Code | Category | Days / yr | Min | Max | Half days | Notice | Document | Paid |
|---|---|---:|---:|---:|---|---:|---|---|
| `ANN` | Annual Leave | 20 | 0.5 | — | Allowed | 7 days | Not needed | Paid |
| `SCK` | Sick Leave | 10 | 0.5 | — | Allowed | None | Over 2 days | Paid |
| `CSL` | Casual Leave | 5 | 0.5 | 3 | Allowed | 1 day | Not needed | Paid |
| `MAT` | Maternity / Paternity | 90 | 1 | — | Whole days only | None | Always | Paid |
| `UNP` | Unpaid Leave | 30 | 1 | — | Whole days only | 14 days | Not needed | Unpaid |

*Min and Max are working days in a single request. A dash means no limit.*

In practice that means annual leave needs planning a week ahead, casual leave is
for short notice but capped at three days at a time, sick leave can be logged for
days already past, and unpaid leave needs a fortnight's warning.

---

## 10. Public holidays

These dates are skipped when your leave is counted. You never spend leave on a
public holiday.

| Date | Day | Holiday | Recurs annually |
|---|---|---|---|
| 1 Jan 2026 | Thursday | New Year's Day | Yes |
| 3 Apr 2026 | Friday | Good Friday | No — moves each year |
| 6 Apr 2026 | Monday | Easter Monday | No — moves each year |
| 1 May 2026 | Friday | Workers' Day | Yes |
| 25 May 2026 | Monday | Freedom Day | Yes |
| 25 Dec 2026 | Friday | Christmas Day | Yes |
| 26 Dec 2026 | Saturday | Boxing Day | Yes |

*Administrators maintain this list under Admin Console → Holidays.*

> **Check this every year.** Easter moves, and a holiday landing on a weekend
> costs nobody a day. If the list for a new year is empty, every weekday counts as
> a working day and leave will be over-charged. Administrators are warned about
> this on the Admin Console overview.

---

## 11. Rules that catch people out

Almost every rejected submission comes down to one of these. The live summary on
the apply form warns you about all of them before you submit.

| Problem | What is happening |
|---|---|
| **Not enough notice** | Annual leave needs seven days, unpaid leave fourteen. Request it sooner and the form refuses, telling you how many days' notice you actually gave. |
| **Request too long** | Some categories cap a single request — casual leave at three days. Split a longer absence, or use a category that allows it. |
| **Overlapping dates** | You cannot hold two live requests covering the same day, even in different categories. Cancel the first one, or change the dates. |
| **Missing document** | Sick leave over two days needs a certificate; maternity and paternity always do. Attach a PDF, JPG or PNG. |
| **Half day on the wrong category** | Maternity, paternity and unpaid leave must be whole days. The half-day options are greyed out for them. |
| **No working days in range** | A range covering only a weekend or only public holidays costs nothing, so there is nothing to approve. |
| **Not enough left** | Remember that pending requests are already holding days. Your available figure is total minus used *minus pending*. |

---

## 12. If something goes wrong

| Symptom | What to do |
|---|---|
| **I forgot my password** | There is no self-service reset. Contact IT and an administrator will issue you a temporary one, which you will replace at your next sign-in. |
| **The portal keeps sending me to the password page** | Your account is still on a temporary password. Set your own and you will be let through. This is working as intended. |
| **My request has sat unapproved for days** | Check whose stage it is in under View Details. If nobody is set as your reporting manager it will never reach a Stage 1 queue — ask an administrator to set one. |
| **My balance looks wrong** | Check the pending figure first; days on requests in progress are held, not lost. If it is still wrong, HR can correct your allocation. |
| **A category has disappeared from the form** | It has been retired by an administrator. Requests you already made under it are unaffected. |
| **I cannot sign in at all** | Your account may have been archived, which blocks sign-in. Contact IT. |

### Getting help

Call IT on **[+268] 2409 1000** or email **info@realnet.co.sz**. Quote your leave
reference number — the `LV-2026-XXXXXX` code — if your question is about a
specific request.

---

*Real Image Internet · Leave Management System · Plot 168, Tsekwane Street, Mbabane*
