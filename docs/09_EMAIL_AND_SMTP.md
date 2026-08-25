# Email & SMTP: How It Works, and How To Change It
## Leave Management System (LMS)

This document covers outgoing email end to end: the protocol underneath it, the
specific mail server this installation talks to, how the application is built on
top of both, every setting that changes its behaviour, and what to do when mail
is not arriving.

It is written to be read by somebody who has never configured a mail server. If
you already know SMTP, skip to [section 3](#3-the-mail-server-we-actually-have).

---

## 1. Why email exists in this system at all

Leave routing always knew who had to act next. Nobody was told. An approver
discovered a request by visiting their queue; an applicant discovered a decision
by re-opening their history. A request could sit for days because nobody had a
reason to log in and look.

In-app notifications fixed half of that: the **Alerts** bell reaches whoever is
signed in. Email is the other half, and the larger one, because it reaches people
who are not.

The design rule that follows from this is worth stating before anything else:

> **Email is a courier, never a gate.** No email failure may delay, block or roll
> back an approval. A late notice is a nuisance; a lost approval is a payroll
> problem.

Every decision below is downstream of that sentence.

---

## 2. How SMTP works

SMTP, the Simple Mail Transfer Protocol, is a plain-text conversation over a TCP
socket. It is old, it is simple, and it is worth understanding directly, because
almost every mail problem you will hit is one side of this conversation refusing
something.

### 2.1 The conversation

You can hold it by hand. This is a real exchange with the production server, with
`>>>` for what we send and `<<<` for what it answers:

```
                                    <<< 220 realnet.co.sz (IMail 8.22) NT-ESMTP Server X1
>>> EHLO leave.realnet.co.sz
                                    <<< 250-realnet.co.sz says hello
                                    <<< 250-SIZE 0
                                    <<< 250-8BITMIME
                                    <<< 250-DSN
                                    <<< 250-ETRN
                                    <<< 250 EXPN
>>> MAIL FROM:<lms@realnet.co.sz>
                                    <<< 250 ok
>>> RCPT TO:<thandi@realnet.co.sz>
                                    <<< 250 ok its for <thandi@realnet.co.sz>
>>> DATA
                                    <<< 354 send data
>>> Subject: Leave request approved
>>> From: Leave Management System <lms@realnet.co.sz>
>>> To: Thandi <thandi@realnet.co.sz>
>>>
>>> Your leave request is approved.
>>> .
                                    <<< 250 ok
>>> QUIT
                                    <<< 221 Goodbye
```

Six steps, and each one can fail:

| Step | What it means | What failure looks like |
|---|---|---|
| Connect | Open a TCP socket to the server's port | Timeout, or connection refused |
| `EHLO` | Introduce yourself; the server lists what it supports | Rarely fails |
| `MAIL FROM` | Declare who the mail is from | `550` if the server will not send as that address |
| `RCPT TO` | Declare one recipient. Repeated per recipient | `550 relay denied`, or `550 no such user` |
| `DATA` | Send headers, a blank line, then the body, ended by a lone `.` | `552` if too large |
| `QUIT` | Hang up | Never matters |

### 2.2 Reply codes

Every line the server sends starts with three digits, and the **first digit is
the whole story**:

| Starts with | Meaning | What the system does |
|---|---|---|
| `2` | Accepted | Carry on |
| `3` | Send more (only `354`, after `DATA`) | Send the body |
| `4` | **Temporary** failure. Try later | Retry. The queue backs off and tries again |
| `5` | **Permanent** failure. Do not retry | Also retried, up to `MAIL_MAX_ATTEMPTS`, then marked failed |

That `4` versus `5` distinction is the single most useful thing to know when
reading `last_error` in the outbox. `421 too many connections` will fix itself.
`550 relay denied` never will, and no amount of retrying is going to help.

### 2.3 The envelope is not the headers

This confuses everybody once, so it is worth being explicit.

- **The envelope** is `MAIL FROM` and `RCPT TO`. It is what the server actually
  routes on. It is not shown to the recipient.
- **The headers** are `From:`, `To:`, `Subject:`, inside `DATA`. They are what the
  recipient sees. They are decoration as far as delivery is concerned.

The two do not have to match, which is how mailing lists work, how `Bcc` works,
and how forgery works. It is also why `MAIL_REDIRECT_TO` can send a message
addressed to `thandi@` into a completely different mailbox: it rewrites the
envelope recipient and leaves the record of the intended one intact.

It is also why `250 ok` after `DATA` does **not** mean "delivered". It means the
server has taken responsibility for the message. What happens after that, whether
it lands in an inbox, a junk folder or a bounce, is outside our visibility.

### 2.4 Ports, and what usually lives on them

| Port | Convention | Encryption | Authentication |
|---|---|---|---|
| 25 | Server-to-server relay | Usually none, sometimes STARTTLS | Usually none |
| 587 | Submission by a client | STARTTLS expected | Expected |
| 465 | Submission, legacy | TLS from the first byte | Expected |

**STARTTLS** means the conversation starts in plain text and is upgraded to
encrypted on request. Implicit TLS (port 465) is encrypted before the first byte.
Both end up encrypted; they differ only in when.

### 2.5 Authentication, and relaying without it

A server has to decide whether it will carry your message. There are two ways:

1. **Authentication.** You prove you own a mailbox with `AUTH`, and the server
   sends on its behalf. This is what a mail client does.
2. **The calling address.** The server recognises the IP you connected from as one
   it serves, and relays without asking anything.

The second is how this installation works, and the consequences are covered next.

---

## 3. The mail server we actually have

Configuration is not guesswork. Ask the server:

```bash
php tools/test_email.php
```

Against `mail.realnet.co.sz` it reports:

```
  connected in 2ms
  greeting: 220 realnet.co.sz (IMail 8.22 186149-1) NT-ESMTP Server X1
  EHLO: 250 realnet.co.sz says hello
  the server offers:
      SIZE 0
      8BITMIME
      DSN
      ETRN
      EXPN

  authentication offered : no
  STARTTLS offered       : no
```

Ports 587 and 465 refuse connections outright. So:

**It is IMail 8.22, on port 25, with no `AUTH` and no `STARTTLS`.**

Four consequences follow, and all four are already reflected in the defaults.

### 3.1 The mailbox password is not used for sending

There is no `AUTH` to use it with. `MAIL_USERNAME` and `MAIL_PASSWORD` are both
empty, and the client relays without logging in.

The `lms@realnet.co.sz` password is for **reading** that mailbox over POP3 on port
110, which is a different protocol doing a different job. This application never
reads mail, so it never needs that password.

If somebody enables `AUTH` on the server later, set both values and the client
starts authenticating. No code changes.

### 3.2 The application must run from an address the server trusts

Relay is decided by the calling IP. This host is inside Realnet's ranges, which is
why sending works with no credentials at all.

**This is the setting most likely to break on a move.** Host the application
outside those ranges and every send fails with a relay error. The symptom appears
in the outbox as `550` in `last_error`, not as anything obvious at the
application level. If the app is ever moved, re-run `tools/test_email.php` from
the new host before assuming anything still works.

### 3.3 Mail leaves unencrypted

No STARTTLS is offered, so leave notices cross the network in clear text,
including staff names, leave categories and approver remarks. That is the
server's configuration rather than a choice made here.

If STARTTLS is ever enabled, the change on our side is one line:

```php
define('MAIL_ENCRYPTION', 'tls');
```

Worth raising with whoever administers IMail. Until then, `SMTPAutoTLS` is left
on in `helpers/Mailer.php`, so if the server ever starts advertising STARTTLS the
connection is upgraded automatically without waiting for a config change.

### 3.4 SPF is strict, which is good for us

The `realnet.co.sz` SPF record ends in `-all`:

```
v=spf1 ip4:196.28.7.0/24 ip4:41.204.0.0/24 ... mx -all
```

`-all` is a hard fail: mail claiming to be from `realnet.co.sz` that leaves from
an unlisted address is **rejected**, not filed as junk. Sending through the
organisation's own server satisfies this. It also means you cannot substitute a
third-party sender (SendGrid, Gmail SMTP) without first adding it to the SPF
record.

---

## 4. How the application is built on this

### 4.1 The four pieces

Each can be read without the others.

| File | Responsibility | Knows nothing about |
|---|---|---|
| `helpers/Notifier.php` | Who is told what, and in what words | SMTP, the outbox |
| `helpers/EmailTemplate.php` | A notification as a subject and two bodies | The database, the network |
| `helpers/EmailQueue.php` | The `email_outbox` table: queue, claim, retry, log | SMTP |
| `helpers/Mailer.php` | Talking SMTP. The only file that knows a server exists | Leave, the database |
| `lib/PHPMailer/` | Vendored SMTP client, no composer | Anything above |

### 4.2 The path a notice takes

```
   An approval is recorded
            |
            v
   ApprovalWorkflow::processAction()
            |
            +-- db->commit()                 <-- the leave decision is now safe
            |
            v
   Notifier::decisionRecorded()
            |
            +--> INSERT INTO notifications   <-- the bell. Immediate.
            |
            +--> EmailQueue::enqueueNotification()
                        |
                        +--> EmailTemplate::renderHtml() / renderText()
                        +--> INSERT INTO email_outbox   status = 'queued'
                                        |
   ... the HTTP request ends here ...   |
                                        |
   cron, every minute                   |
            |                           |
            v                           v
   tools/send_queued_email.php --> EmailQueue::run()
                                        |
                                        +--> claim a row  status = 'sending'
                                        +--> Mailer::send()  --> SMTP --> the server
                                        +--> status = 'sent', or back to 'queued' with a retry time
```

Two properties of that diagram matter more than the rest:

**Notifier runs after `commit()`.** The leave decision is durable before any
notification is attempted, so nothing about notifying can undo it.

**The request ends before any SMTP happens.** An approver pressing Approve waits
for a database write, not a mail server. This is the reason the queue exists. An
inline send would hold the page open for an SMTP round trip on every approval, and
for the full socket timeout every time the mail server was unreachable.

### 4.3 Why a queue and not just sending

| Property | Inline send | Queue |
|---|---|---|
| Approver waits for SMTP | Yes | No |
| Mail server down | Message lost | Message delayed |
| Transient failure | Lost | Retried, with backoff |
| "Was Thandi told?" | Unanswerable | A row, with a timestamp or an error |
| Extra moving parts | None | One table, one cron entry |

The last row is the real cost: **nothing is sent until the worker runs.** A
forgotten cron entry means a silently growing outbox. `--status` is what makes
that visible.

### 4.4 Claiming, and why it is done the hard way

Two workers must never send the same message twice. The claim is a conditional
`UPDATE`, and the row is only ours if it reports one affected row:

```sql
UPDATE email_outbox
SET status = 'sending', attempts = attempts + 1, claimed_at = NOW()
WHERE id = :id AND status = 'queued'
```

A second worker racing for the same row is told it changed nothing, and asks for
another. `SELECT ... FOR UPDATE SKIP LOCKED` would also work, but this
installation runs MySQL 8 in the container and MariaDB on the UAT machine, and a
compare-and-swap on a status column behaves identically on both and on older
versions of either.

Two details that look like mistakes and are not:

**`attempts` is spent at claim time, not after sending.** A worker killed
mid-send has already paid for the try. That is the safe direction: the
alternative is a message that crashes the worker being retried for ever.

**Staleness is measured from `claimed_at`, not `queued_at`.** A message queued
last week and claimed one second ago is in flight, not abandoned. Measuring from
`queued_at` would requeue it under a live worker and deliver it twice.

### 4.5 The retry schedule

Failures are retried after 1, 5, 15 and 60 minutes, then marked `failed` and left
alone. Widening, so a server that is briefly busy is retried almost immediately
while one that is down for the afternoon is not hammered every minute.

Failed rows are **never** pruned automatically. They are the ones somebody still
has to read.

### 4.6 What cannot break an approval

- Queueing sits in its own `try`/`catch`, after the notification row is written.
- A missing `email_outbox` table (migration 005 not run) is logged and swallowed.
- A recipient with no usable address is skipped and logged.
- `push()` returns success for a stored notification whose email could not be
  queued, because the bell will still show it and the caller cannot fix a mail
  problem anyway.

This is tested by renaming the table away and confirming `push()` still returns
true.

---

## 5. Every setting, and what it does

Precedence, first to define a constant wins:

1. `config/local.php` (untracked, per server)
2. Environment variables
3. Defaults in `config/constants.php`

| Setting | Default | What it does |
|---|---|---|
| `APP_URL` | `http://localhost:8000` | Base of every link in every email. See the warning below. |
| `MAIL_ENABLED` | `false` | Off means nothing is **queued**. Not the same as nothing being sent. |
| `MAIL_HOST` | `mail.realnet.co.sz` | Server to connect to. |
| `MAIL_PORT` | `25` | The only port this server answers on. |
| `MAIL_ENCRYPTION` | `''` | `''`, `tls` (STARTTLS) or `ssl` (implicit TLS). |
| `MAIL_USERNAME` | `''` | Empty means relay without authenticating. |
| `MAIL_PASSWORD` | `''` | Never in a tracked file. Unused against this server. |
| `MAIL_FROM_ADDRESS` | `lms@realnet.co.sz` | Envelope sender. Must be one the server will send as. |
| `MAIL_FROM_NAME` | `Leave Management System` | Display name in the `From:` header. |
| `MAIL_REPLY_TO` | `info@realnet.co.sz` | Where replies go, because `lms@` is not read. |
| `MAIL_REDIRECT_TO` | `''` | Divert **everything** to one mailbox. UAT only. |
| `MAIL_TIMEOUT` | `15` | Seconds to wait on the server before giving up. |
| `MAIL_BATCH_SIZE` | `20` | Messages per worker run. |
| `MAIL_MAX_ATTEMPTS` | `5` | Attempts before a message is marked `failed`. |
| `MAIL_RETRY_BACKOFF` | `1,5,15,60` | Minutes between attempts. Last value repeats. |

### 5.1 APP_URL deserves its own warning

It is the base of every link in every notification, and those are rendered by a
**cron worker with no HTTP request**, so a hostname cannot be inferred. Left as
`localhost` on a live server:

- mail is accepted, marked sent, and delivered;
- every recipient gets a link to their own machine;
- nothing errors anywhere.

Nobody reports that as a mail problem. Both `tools/test_email.php` and
`--status` warn in capitals when `APP_URL` still looks local.

### 5.2 MAIL_REDIRECT_TO deserves its own warning too

Set in production, every notice goes to one mailbox and **nobody else is
notified**, while the system reports complete success. It exists because the UAT
database is seeded from the real staff roster: 30 of its 31 accounts carry a
colleague's real address, and the UAT machine can reach the mail server, so one
test approval would mail thirty people about a request that does not exist.

Three safeguards:

- The redirect happens at **send** time, not queue time, so `email_outbox` still
  records the real recipient and the delivery log stays truthful.
- The subject is prefixed `[to thandi@realnet.co.sz]`, so thirty near-identical
  test messages stay distinguishable, and the body carries a banner.
- **It fails closed.** An invalid `MAIL_REDIRECT_TO` raises rather than falling
  back to the real recipient. Whoever set it meant "do not mail the actual
  staff", and honouring a typo by mailing them is the exact outcome they were
  avoiding.

---

## 6. Changing its behaviour

### 6.1 Talk to the server yourself

The single most useful diagnostic, and it sends nothing:

```bash
php tools/test_email.php
```

It connects, reads the greeting, asks `EHLO` what the server supports, offers an
envelope to see whether a relay would be accepted, then hangs up before the
message body. Safe against a live server.

To send one real message:

```bash
php tools/test_email.php --to you@realnet.co.sz
```

Raw, with no application involved:

```bash
printf 'EHLO test\r\nQUIT\r\n' | nc mail.realnet.co.sz 25
```

Note that `bash`'s `/dev/tcp` is not a reliable probe here. During development it
reported port 25 unreachable while PHP's `fsockopen` connected in 2ms to the same
host and port. Trust the PHP-based tool.

### 6.2 Move to an authenticated submission port

If IMail is reconfigured, or the system is pointed at a different provider:

```php
define('MAIL_HOST', 'smtp.example.com');
define('MAIL_PORT', 587);
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_USERNAME', 'lms@realnet.co.sz');
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD'));
```

Confirm with `tools/test_email.php` first: it names the two mismatches that
otherwise produce a baffling error. A username set against a server offering no
`AUTH` fails as *"Could not authenticate"* and explains nothing; encryption asked
of a server with no `STARTTLS` fails on the handshake.

Remember the SPF record ends in `-all`. A new sender must be added to it or its
mail is rejected outright.

### 6.3 Change what is sent, and to whom

| To change | Edit |
|---|---|
| Which events send email | `helpers/Notifier.php`, the three event methods |
| Who receives an event | `Notifier::approversFor()` |
| Wording of titles | `Notifier::awaitingTitle()`, `outcomeFor()` |
| The detail rows | `Notifier::detailsFor()` |
| Layout, colours, button | `helpers/EmailTemplate.php` |
| Subject prefix | `EmailTemplate::subject()` |

`EmailTemplate` is entirely static and free of I/O, so wording can be changed and
asserted on without a mail server, a database or an inbox.

### 6.4 Tune throughput

A large batch on a slow server makes each worker run longer. `MAIL_BATCH_SIZE`
times the per-message round trip should stay comfortably under a minute, or runs
begin to overlap. They cannot collide (the lock and the claim both prevent it),
but the queue stops keeping up.

```php
define('MAIL_BATCH_SIZE', 50);   // busier site
define('MAIL_TIMEOUT', 8);       // fail faster on a flaky server
define('MAIL_RETRY_BACKOFF', '1,2,5,10,30');
```

### 6.5 Stop email without touching anything else

```php
define('MAIL_ENABLED', false);
```

Nothing is queued from that moment. The bell keeps working, and the approval
workflow is untouched. Anything already queued still sends when the worker runs;
to stop that too, remove the cron entry.

---

## 7. When mail is not arriving

Always start here. It reports the configuration and the queue together, with the
most recent errors and what the server actually said:

```bash
php tools/send_queued_email.php --status
```

| Symptom | Cause | Fix |
|---|---|---|
| `sending enabled: no` | `MAIL_ENABLED` is false | Set it in `config/local.php` |
| `queued` climbing, `sent` at 0 | Nothing is running the worker | Add the cron entry |
| `*** APP_URL IS A LOCAL ADDRESS ***` | `APP_URL` never set on this host | Set it. Every link mailed so far is dead |
| `*** REDIRECTING ***` | `MAIL_REDIRECT_TO` set in production | Clear it. Only that mailbox is being notified |
| `Could not connect to SMTP host` | Port blocked, wrong host, or off the trusted network | `php tools/test_email.php` |
| `Could not authenticate` | `MAIL_USERNAME` set against a server with no `AUTH` | Clear `MAIL_USERNAME` |
| `550 relay denied` in `last_error` | Server will not relay from this machine's address | Talk to whoever runs IMail |
| `550 no such user` | The address on the account is wrong | Fix it in User Management |
| Everything `sent`, nothing received | Delivered, then filtered | Check junk. Then it is a mail server question |
| `sending` rows stuck | A worker died mid-send | The next run requeues anything held over 15 minutes |

Reading the queue directly:

```sql
SELECT id, to_email, status, attempts, last_error, next_attempt_at
FROM email_outbox
WHERE status IN ('queued','failed')
ORDER BY id DESC LIMIT 20;
```

Retry everything that has given up:

```sql
UPDATE email_outbox
SET status = 'queued', attempts = 0, next_attempt_at = NOW()
WHERE status = 'failed';
```

---

## 8. Running it

### 8.1 The cron entry

```cron
* * * * * cd /var/www/html && php tools/send_queued_email.php >> /var/log/ri-leave-mail.log 2>&1
```

Under Docker, from the host:

```cron
* * * * * docker compose -f /path/to/docker-compose.yml exec -T web php tools/send_queued_email.php
```

Quiet on success, so an entry with no redirection will not mail root every minute.
Failures always print. Exit codes: `0` fine, `1` something could not be sent, `2`
could not run at all, so monitoring can read them.

Only one worker runs at a time. The lock is not needed for correctness, since the
claim already makes concurrent workers safe, but a server that has started timing
out holds each run open for `MAIL_TIMEOUT` seconds, and a minutely cron would
otherwise pile up stalled workers all waiting on the same dead host.

### 8.2 Housekeeping

```bash
php tools/send_queued_email.php --prune 90
```

Deletes mail **sent** more than 90 days ago. Never touches failed rows. Worth a
monthly cron entry once the system has been live long enough to accumulate.

### 8.3 What to watch

`queued` should hover near zero. Sustained growth means the worker is not running
or the server is refusing everything. `failed` should be zero; anything there has
exhausted its attempts and will not be retried without intervention.

---

## 9. Security notes

**Header injection.** Subjects carry staff names, so they are collapsed to a
single line before reaching a header. A newline would let the rest be read as
another header, which is how a `Bcc:` gets added by an attacker.

**Escaping.** Every value interpolated into the HTML body goes through one
escaping function. Approver remarks are typed by a person, and `nl2br` runs
**after** escaping, never before.

**Credentials.** `MAIL_PASSWORD` has no literal anywhere in the repository. A
mailbox password in a committed file is a mailbox password on GitHub.
`config/local.php` is gitignored for the same reason.

**Transport.** Unencrypted, as covered in section 3.3. This is the outstanding
security item on the email path.

**Transactional headers.** `Auto-Submitted: auto-generated` and
`X-Auto-Response-Suppress: All` are set, which keeps out-of-office replies and
bulk filters off the `lms@` mailbox.

**Attachments.** Notification email never contains one. Sick notes and
certificates are only ever served through `modules/leave/attachment.php`, behind
an authorisation check. A medical certificate must not leave the building in an
unencrypted email.

---

## 10. Testing

```bash
php tests/test_suite.php         # wording, addressing, retry schedule, escaping
php tests/test_email_queue.php   # the outbox, against a real database
php tools/test_email.php         # the actual server, sends nothing
```

The queue tests skip with a reason when there is no database, and refuse to run
when real mail is waiting rather than deleting a queue they did not create.

For testing against real staff data without mailing real staff, see
**Testing it without mailing the whole company** in the README: `MAIL_ENABLED`
and the worker are separate switches, which is what makes safe levels of testing
possible.
