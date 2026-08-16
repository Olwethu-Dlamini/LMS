# 📘 User Manual: Leave Management System

**Real Image Internet · Staff Leave Portal**

How to request leave, approve it, and administer the system, written for every
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

1. Open the portal in your browser and enter your **work email address**, the
   same `@realnet.co.sz` address you use for mail.
2. Enter the **temporary password** IT gave you.
3. The system takes you straight to **Set Your New Password**. You cannot reach
   the rest of the portal until this is done. That is deliberate, so no account
   keeps a password somebody else has seen.
4. Type the temporary password once more under *Temporary Password*, then choose
   your own. The four rules tick green as you satisfy them.
5. Confirm it and select **Update Password**. You land on your dashboard, signed in.

### Password rules

Your new password must be at least **10 characters** and include at least one
**uppercase** letter, one **lowercase** letter and one **number**. It cannot be
the same as the temporary one.

**Changing it later.** Select **Change Password** in the thin bar at the very
top of any page. You will need your current password to set a new one.

**If IT resets your password.** An administrator can issue you a new password,
but it always arrives as a temporary one. You will be asked to set your own the
next time you sign in.

**If you are locked out.** After five failed attempts in a row the sign-in form
stops answering for fifteen minutes. This protects your account from somebody
guessing at it. Wait it out and try again, or ask IT to reset your password if
you have genuinely forgotten it. Signing in successfully clears the count, so
mistyping once or twice costs you nothing.

---

## 2. What everyone can do

Every person in the company, including managers, HR and administrators, has
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

The bar across each tile shows how much of that allocation is already committed:
solid for days taken, amber for days still awaiting approval.

Above the tiles:

- **Your Next Leave.** Your next approved dates and how long until they start.
- **Away This Week.** The coming seven working days for your department, with the
  initials of whoever is off and a count of how many that is out of the team.
  Amber means the department has reached the number it is allowed to have away at
  once; red means it has gone past it.

Managers, HR and executives also get **Annual Leave Utilisation**: how much of the
year's allocation each of their departments has committed, flagging staff who have
booked almost nothing yet (they are the ones who come looking for three weeks in
December), and anyone with no allocation at all, who cannot apply until HR runs the
annual entitlement.

### Notifications

The **Alerts** bell in the navigation bar carries a red count of anything you have
not read yet. You are notified when:

- your request is submitted, so you have confirmation it was received;
- it clears a stage, is fully approved, or is declined, with the approver's
  remarks;
- a request needs *your* approval;
- a request in your queue is withdrawn by the person who made it;
- HR or an administrator cancels a request of yours.

Selecting a notification takes you to the request or queue it refers to and marks
it read. **View all notifications** lists everything, newest first, with **Mark all
as read**.

> Notifications are in-app only. The system does not send email, so nothing
> depends on a mail server being reachable.

### The team calendar

**Team Calendar** shows a month at a time for your department: who is off on each
working day, and how many that leaves away out of the team. Weekends and public
holidays are never counted as absence, and holidays are labelled so an empty day
reads as a closure rather than as available cover.

**Approved leave and requests are shown differently**, because a day is only
plannable if you can tell what is booked from what has merely been asked for:

| On the calendar | What it means |
|---|---|
| Blue chip reading **Approved** | Signed off. That person will be off. Counted in the day's total, e.g. `2/8`. |
| Amber chip reading **Requested · not yet approved** | Somebody has asked for this leave and nobody has decided yet. It might still be refused, so it is **never counted** in the day's total. |
| Entry reading **You** | Your own leave, so you can find yourself in a busy month. |

So if a colleague appears on a day in amber, they have put in a leave request that
has not been approved. Useful to know before you request the same days, and not
something to plan cover around yet.

A legend above the grid names both. The month header also totals them: how many
people are approved off, and how many are still waiting on a decision.

What you see depends on your role. Employees see their own department with names
only: leave categories are withheld, because sick and maternity leave are nobody
else's business. Managers see the departments they are responsible for, in full.
HR, executives and administrators can select any department.

### Applying for leave

1. Select **Apply** in the top bar, or **My Leave → Apply for Leave**.
2. Choose a **leave category**. A line of grey text appears underneath listing
   that category's rules: notice required, minimum and maximum length, and
   whether half-days are allowed.
3. Pick your **start** and **end** dates. If the category demands notice, the
   date picker will not let you choose anything sooner. Categories that need no
   notice, such as sick leave, have no such floor, so you can enter dates that have
   already passed and record the leave after the fact.
4. Choose a **duration type**: full days, or a half day (morning or afternoon).
   Half-day options are greyed out for categories that must be taken as whole days.
   A half day applies to **one** day: if you want half a day off, set the start
   and end date to the same date.
5. Watch the **live summary**. It counts the working days, tells you your
   remaining balance, and warns you about anything that would block the request,
   before you submit.
6. Check the **cover panel** underneath it. If anyone else in your department is
   already off on those dates it names them and says how many days each is out.
   Where your department has a cover limit set, it also tells you whether your
   request would take the team past it. See *Who else is away* below.
7. Give a **reason**, attach a document if the category needs one, and select
   **Submit Application**.

You are returned to your history with a confirmation and a reference number in
the form `LV-2026-XXXXXX`. Quote it if you need to ask about the request.

> **How days are counted.** Only working days count. Saturdays, Sundays and every
> public holiday in the calendar are skipped automatically, so a Monday-to-Friday
> request over a week containing a holiday costs you four days, not five. A half
> day costs 0.5.

#### Who else is away

The cover panel is advice, not a gate. Nothing it says will stop you submitting:
sick leave cannot wait for a convenient rota, and your approver is the one who
decides. What it does is put the staffing picture in front of the one person who
can still move the dates cheaply, you, before the request is filed, instead of
surfacing it days later as a rejection.

| What it says | What it means |
|---|---|
| Names, in blue | Colleagues already off then. *Awaiting approval* marks a request that has not been decided yet, so it may still fall away. |
| Amber | The department is already at or over its cover limit on some of those days, with or without you. |
| Red | Your request is the one that would take the department past its limit. Move the dates if they can move; submit anyway if they cannot. |

Leave categories are never shown for colleagues, only that they are away. The
same rule applies on the team calendar, so nobody's sick or maternity leave is
disclosed to the team.

#### Supporting documents

Documents you attach are private. Only you and the people who approve your
request (your line manager, HR, the executive and system administrators) can
open one. Colleagues who can see your absence on the team calendar cannot open
the certificate behind it. PDF, JPG and PNG up to 5 MB are accepted.

If a document fails to upload the whole request is refused rather than filed
without it, so a certificate can never go missing quietly.

### Tracking and cancelling

**My Leave → My Leave History** lists everything you have ever submitted with its
current status. Select **View Details** to see who has signed it off, when, and
any remarks they left.

**Cancel** withdraws a request. Cancel one that is still in approval and the held
days return to your balance immediately. Cancel approved leave before it starts
and the days are given back to you.

Once leave has started you can no longer cancel it yourself and the button is
gone: the days were taken, and handing them back afterwards would turn time off
into credit. If the dates genuinely changed, whether you came back early or never went,
ask HR. They can correct it, and the correction is recorded in the audit log.

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
  APPROVED: days deducted
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
- Change your own allocation. That is HR's
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
4. Add remarks. Do this especially when rejecting. Your note is what the
   employee sees as the explanation.
5. Choose **Approve Stage 1** to pass it to HR, or **Reject Request** to decline
   it and release the days.

### Protecting your cover

Each department can be given a limit on how many people may be away on the same
working day. Administrators set it; you see the consequences.

When you open a request for review, the screen tells you what approving it would do
to your cover:

| Notice | Meaning |
|---|---|
| Green | The department stays within its limit. Nothing to weigh up. |
| Amber: *takes the department to its limit* | Allowed, but it uses the last slot. No cover left if somebody falls ill. |
| Amber: *already over its limit* | The department is short on those days whether or not you approve this one. Worth raising separately. |
| Red: *leaves the department short* | **This request** is what pushes the department past its limit, on the days listed. |

The queue itself flags the affected rows, so you can see which requests need a
closer look without opening each one, and every notice links straight to the month
on the team calendar.

**Nothing is blocked.** Sick leave does not wait for a rota to be convenient, and
the notice does not stop you approving anything. It makes sure you know what you
are agreeing to. If a department has no limit configured, no notice appears.

### Whose leave you see

Your queue is filtered to your own people. A request reaches you if the employee
has you set as their **reporting manager**, or if you are the **designated head**
of their department. You will never see another manager's team. The team calendar
follows the same boundary, so you can see cover for every department you approve
for, including a second department if you head more than one.

> **You cannot approve your own leave.** The system blocks self-approval outright.
> Your own requests go to whoever manages you, exactly like anyone else's.

Everything in [What everyone can do](#2-what-everyone-can-do) applies to you as
well: you still apply for your own leave through the same form.

---

## 6. HR Manager

Stage 2, plus ownership of what everyone is entitled to and all company-wide
reporting.

### Stage 2 approvals

**Approvals → Stage 2 · HR Review** holds everything a line manager has already
cleared. The queue works exactly like Stage 1: review, add remarks, then approve
to send it to the Executive, or reject to end it. Approving here does *not*
deduct days. Only the final stage does.

### Leave allocations

**HR Management → Leave Allocations** is where balances are set.

- **Allocate / Update.** Set one person's days for one category in one year. Use
  it for a mid-year joiner or an agreed exception.
- **Bulk Initialize Year.** Give every active employee the standard allocation
  for a new leave year in one action. This is the normal start-of-year task.

The table shows total, used, pending and remaining per person, so you can see at a
glance who is close to exhausting a category.

### Reports and payroll export

**HR Management → Leave Reports** covers the whole company. Filter by
**department**, **status** and a **date range**, then use **Export Filtered CSV**
to download exactly the rows on screen. The export honours your filters, so what
you see is what you get. The file opens in Excel and is intended for payroll.

> **Also yours.** HR can cancel any application, not just their own, for cases
> where leave needs unwinding after the fact.

---

## 7. Executive

Stage 3, the final authority. Nothing becomes approved leave without you.

### Final sign-off

**Approvals → Stage 3 · Executive Sign-Off** lists requests that both the line
manager and HR have already backed. Your decision is the last one.

- **Approve.** The request becomes *Approved* and, at this moment, the days move
  out of the employee's balance for real.
- **Reject.** The request ends and the held days go straight back, even though
  two approvers had already agreed.

Your dashboard shows how many requests are waiting on you, so the count is
visible the moment you sign in.

> **Worth knowing.** Because the deduction happens here, a balance only changes
> when you act. Anything sitting in earlier stages is held, not spent, which is
> why an employee's available days can look lower than their used days suggest.

---

## 8. System Administrator

You run the system itself: who has an account, how the company is structured, and
what the leave rules are.

### The Admin Console

Select **Admin Console** in the top bar. The navigation turns dark. That is how
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
   matters most: without one, that person's Stage 1 can only be cleared by an
   administrator.
4. Save. Their standard allocations for the current year are created
   automatically from the leave categories.
5. Give them the starting password. They will be forced to replace it when they
   first sign in.

### Removing someone

Deleting a person would take their leave history and the approval audit trail
with them, so the system will not simply do it. **Delete** behaves in one of two
ways:

- The account has **no leave history**, so it is removed permanently. Use this for
  an account created by mistake.
- The account **has history**, so it is **archived** instead, and you are told why.
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
| **Advance notice** | Days of warning required before the start date. Set it to **0** to allow same-day booking *and* backdating, which is exactly what lets sick leave be recorded after the fact. |
| **Half days allowed** | Whether the morning and afternoon options appear at all. |
| **Requires a document** | Combined with a threshold, so you can demand a certificate only once a request passes a certain length. |
| **Paid** | Whether the category is paid or unpaid. |
| **Available on the apply form** | Clear this to retire a category. It vanishes from the form but stays in historical reports. |

Deleting a category follows the same principle as users: one that no application
has ever used is removed, and one that is in use is **retired** instead so past
records keep their meaning.

### Departments and holidays

A department cannot be deleted while people still belong to it. Move them first.
Holidays can be added, edited and removed freely, but a change only affects
**future** calculations; leave that has already been submitted keeps the day count
it was approved with.

**Maximum Away At Once** sets how many of a department's members may be on leave on
the same working day. It drives the shading on the team calendar and the coverage
notice approvers see before they sign off.

- Leave it **blank** for no limit. Nothing is shaded and no notice appears.
- **Zero** means nobody may be away at all, accepted if that is genuinely what
  the department needs.
- A limit that is **at or above the department's headcount** can never be exceeded;
  the listing points this out, so you are not left wondering why no warning ever
  appears.

Set it below the headcount by the number of people the team can actually spare. A
team of six that needs four on the floor has a limit of two.

> **Administrators can approve at any stage.** An administrator can act on
> Stage 1, 2 or 3. Use it to unblock a queue when an approver is away, but the
> audit log records that it was you.

---

## 9. Leave categories

The categories as currently configured. An administrator can change any of these
in the Admin Console, so treat the portal as the authority.

| Code | Category | Days / yr | Min | Max | Half days | Notice | Document | Paid |
|---|---|---:|---:|---:|---|---:|---|---|
| `ANN` | Annual Leave | 20 | 0.5 | None | Allowed | 7 days | Not needed | Paid |
| `SCK` | Sick Leave | 10 | 0.5 | None | Allowed | None | Over 2 days | Paid |
| `CSL` | Casual Leave | 5 | 0.5 | 3 | Allowed | 1 day | Not needed | Paid |
| `MAT` | Maternity / Paternity | 90 | 1 | None | Whole days only | None | Always | Paid |
| `UNP` | Unpaid Leave | 30 | 1 | None | Whole days only | 14 days | Not needed | Unpaid |

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
| 3 Apr 2026 | Friday | Good Friday | No, moves each year |
| 6 Apr 2026 | Monday | Easter Monday | No, moves each year |
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
| **Request too long** | Some categories cap a single request: casual leave at three days. Split a longer absence, or use a category that allows it. |
| **Overlapping dates** | You cannot hold two live requests covering the same day, even in different categories. Cancel the first one, or change the dates. |
| **Missing document** | Sick leave over two days needs a certificate; maternity and paternity always do. Attach a PDF, JPG or PNG. |
| **Half day on the wrong category** | Maternity, paternity and unpaid leave must be whole days. The half-day options are greyed out for them. |
| **Half day across a range** | A half day belongs to one day. Choose half a day with a start and end date days apart and the form refuses it. Set both dates to the same day instead. |
| **Cancelling leave you are already taking** | Approved leave can be cancelled up to the day before it starts. From the first day onward only HR can correct it. |
| **No working days in range** | A range covering only a weekend or only public holidays costs nothing, so there is nothing to approve. |
| **Not enough left** | Remember that pending requests are already holding days. Your available figure is total minus used *minus pending*. |

---

## 12. If something goes wrong

| Symptom | What to do |
|---|---|
| **I forgot my password** | There is no self-service reset. Contact IT and an administrator will issue you a temporary one, which you will replace at your next sign-in. |
| **The portal keeps sending me to the password page** | Your account is still on a temporary password. Set your own and you will be let through. This is working as intended. |
| **My request has sat unapproved for days** | Check whose stage it is in under View Details. If nobody is set as your reporting manager it will never reach a Stage 1 queue. Ask an administrator to set one. |
| **My balance looks wrong** | Check the pending figure first; days on requests in progress are held, not lost. If it is still wrong, HR can correct your allocation. |
| **A category has disappeared from the form** | It has been retired by an administrator. Requests you already made under it are unaffected. |
| **I cannot sign in at all** | Your account may have been archived, which blocks sign-in. Contact IT. |

### Getting help

Call IT on **[+268] 2409 1000** or email **info@realnet.co.sz**. Quote your leave
reference number, the `LV-2026-XXXXXX` code, if your question is about a
specific request.

---

*Real Image Internet · Leave Management System · Plot 168, Tsekwane Street, Mbabane*
