# EOD_SPEC — End of Day report and timed report emails (the contract)

Owner: `dept-eod-lead`. Audit 2026-11-13; FIX round 2026-11-13 (owner decisions B1-B4 + F1-F6 + delivery LATERs).
Scope: both hotels, same code. Files: `admin/end-of-day-report.php`, `admin/api/end-of-day-send.php`,
`admin/api/end-of-day-pdf.php`, `admin/includes/eod-report-data.php` (NEW: the one data builder),
`admin/includes/eod-revenue.php`, `admin/includes/finance-refunds.php`, `admin/includes/pos-shift-totals.php`,
`includes/eod-pdf-builder.php`, `includes/daily-reports-send.php`, `includes/kds-report-data.php`,
`includes/auto-scheduler.php`, `includes/auto-email-jobs.php`, `includes/report-mailer.php`,
`includes/report-builders.php`, `admin/automated-emails.php`, `admin/kds-report.php`, `scripts/daily_reports.php`,
`admin/includes/hotel-details-settings.php`. Tests (pure): `scripts/tests/daily_reports_job.php`,
`scripts/tests/station_reports_job.php`.

Rule: code and this spec never disagree. Any change to a sender, recipient, CC/BCC, day basis, dedupe key or a
figure's source updates this file in the same ship. Money figures: `dept-accounting-lead` is the review gate.

---

## 0. In one screen: what goes out, to whom, when

| # | What | Sent by | When | To | Visible copy (CC) | Hidden copy (BCC) |
|---|------|---------|------|----|-------------------|-------------------|
| R1 | **End of Day email** (HTML + PDF) | a person, Email button | only when clicked | End-of-day report recipients (§2.1) | `eod_report_cc_emails` (always) + extra addresses typed in the box | staff copy email(s) when the switch is on (§1.4) |
| R2 | **End of Day WhatsApp** (text) | a person, WhatsApp button | only when clicked | `eod_whatsapp_number` → `whatsapp_number` → `whatsapp_hotel_number` → `phone_main` | – | – |
| R3 | **End of Day PDF download** | a person, PDF button | on click | (download) | – | – |
| R4 | **Daily Operations Report + End of Day PDF** (4 CSVs + `eod-report-D.pdf` + headline lines) | in-app timer, job `daily_reports` | once per trading day, within about an hour after the next opening (needs page traffic) | End-of-day report recipients (§2.1) | `automated_job_daily_reports_cc` (default info@promanaged-it.com) + `eod_report_cc_emails` | staff copy email(s) when the switch is on |
| R5 | **Station report emails** (kitchen / bar / coffee bar) | in-app timer, job `station_reports` | once per station per trading day, within about an hour after that station's next opening | `automated_job_station_reports_to`, empty = §2.1 | `automated_job_station_reports_cc` (default info@promanaged-it.com) | staff copy email(s) when the switch is on |
| R6 | R4 by hand | `php scripts/daily_reports.php` (CLI, never at Rosalyn's) | when run | `--recipients` / env `REPORT_RECIPIENTS` | as R4 | staff copy email(s) |
| R7 | **Station Report email, by hand** | a person, Email on Station Report | when clicked | prefilled with R5's To list (editable) | `automated_job_station_reports_cc` | staff copy email(s) |

**Test mode** (Automated Emails > Test recipient set, Liwonde): EVERY row above that emails goes ONLY to the test
address, subject `[TEST]`, no CC, no hidden copy; R2 WhatsApp is not sent ("Test mode is on … not sent"); the
success message says "TEST MODE: sent only to …". (Owner B3 2026-11-13.)

No cron, no server timer, ever (owner 2026-11-12): R4/R5 run inside `includes/auto-scheduler.php`.

---

## 1. Shared plumbing

**1.1 The timer** (`includes/auto-scheduler.php`)
- `rh_scheduler_maybe_run()` runs on every admin page and public page, never on `localhost` / `php -S`. At most every
  `automated_email_interval_minutes` (default 15), detached after the response, under MySQL lock `rh_scheduler`.
  Budgets: 20 s / 25 emails detached, 4 s / 5 otherwise.
- `daily_reports` and `station_reports`: own minimum interval 60 min. Switches: `automated_email_master` +
  `automated_job_daily_reports` / `automated_job_station_reports` (default on).
- "When" = within about an hour after the day ends, on a page load by staff or a guest. No page loads = no send;
  no catch-up for an older day (LATER L4).

**1.2 Trading day** (`admin/includes/pos-shift-totals.php` `rh_pos_last_ended_trading_date`)
- Trading day D = first opening on D → first opening on D+1 (union of station hours). The timer sends the newest
  of today / yesterday / the day before whose trading end is <= now (06:00 openings: run 23:00 on the 12th → 11th;
  03:00 on the 12th → 10th; from 06:00 on the 12th → 11th). Station emails use each station's own window.
- `rh_eod_day_is_open(D)` (eod-revenue.php) = D is after the last ended trading day → every send says "as at HH:MM".

**1.3 Time zone and which date a payment counts on**
- One clock for every End of Day surface and the timer: `rh_eod_now()` / `rh_eod_today()` (eod-revenue.php) =
  `rh_site_timezone()`. The page, the send and the PDF no longer use the server's `date('Y-m-d')` (L8 fixed).
  DB session time zone = PHP zone (`config/database.php:136`).
- `payments.payment_date` is a date. Money RECEIVED counts on its payment date (till sale: its trading business
  date; front desk: the date entered). A REFUND counts on the FIRST day it became paid out (processing or
  completed) and that day never moves (accounting gate 2026-11-13): `rh_refund_eod_date_sql()` (finance-refunds.php;
  pure twin `rh_refund_eod_day()`) = the day of its first `payment_audit_log` row with action `refund_processing` /
  `refund_completed`, field `refund_status`, old value `pending` (written by `rh_settle_refund_row`, the only refund
  status writer); no such row (created already processing / completed, or legacy) = its payment date. So
  processing d1 → completed d3 stays on d1; pending d1 → processing d2 counts on d2. Nothing is written;
  `refund_date_processed` is not read, so the till Z / shift close are unchanged and no closed Z is restated.
  One condition for both: `rh_eod_pay_day_sql()` (eod-revenue.php, 2 params). (F4 fixed.)
- Payments made WITH a credit note (non-refund rows, `payment_method = 'credit_note'`) are guest credit being used,
  not money: `rh_eod_cash_row_sql()` leaves them out of payments received, cash collected, transactions, the
  payment mix, the F&B share of room payments, the previous-day and 7-day cash, on every surface. They show as
  "Credit notes redeemed (guest credit used, no new money)" (`rh_eod_credit_notes_redeemed`), beside "Moved to
  credit notes". Earned revenue is unchanged. (Accounting gate B2.)

**1.4 The report mailer** (`includes/report-mailer.php` `sendReportEmail`)
- Arguments: recipients, subject, html, attachments, textBody, ccEmails.
- Test mode: when `$GLOBALS['rh_automated_ctx']['redirect']` is set (the timer in test mode, or
  `rh_report_with_test_ctx()` around a clicked send) → To = the test address only, `[TEST]`, CC dropped, NO hidden
  staff copy (L1 fixed); a bad test address fails closed.
- Otherwise: CC as given; hidden copy to `email_admin_email` + each `email_admin_backup` when Email Settings
  `email_bcc_admin` = 1, skipping addresses already on the message.
- Log: Emails sent on success and on EVERY failure, including "No valid recipients", "SMTP is not fully
  configured" and non-PHPMailer errors (L20 fixed).

**1.5 Never-twice log** (`automated_email_log`, `rh_auto_log_claim`)
- Claim = `INSERT IGNORE` of (job, account_type, account_id, stage) as `sending`; re-claim only `failed` with
  attempts < 3 and > 1 h old, or `sending` > 15 min old (crash recovery).
- Outcome: `rh_auto_record_send()` (auto-email-jobs.php). Delivered → `sent`, tried twice, and NEVER marked
  `failed` afterwards (so the hourly retry can never re-send a delivered email; L3 fixed). Not delivered →
  `failed` (retried).
- No recipients / bad test address → NO row for R4 and R5 (recipients added later the same day still get it;
  L2 fixed). Zero tickets at a station → one `skipped` row.

**1.6 Shared routing helpers** (`includes/daily-reports-send.php`, pure)
- `rh_eod_report_recipients()` — §2.1. `rh_daily_reports_cc($to, $csv)` — valid, no repeats, never a To.
- `rh_daily_reports_cc_all($to)` — `automated_job_daily_reports_cc` + `eod_report_cc_emails` (R4/R6 CC).
- `rh_report_test_recipient()`, `rh_report_route($to, $cc, $subject, $redirect)` (send / refuse, [TEST], no CC),
  `rh_report_with_test_ctx($redirect, $send)` (sets and restores the mailer's test context).

---

## 2. Delivery matrix

### 2.1 Recipient chain (R1, R4, fallback of R5/R7)
`rh_eod_report_recipients()`: Hotel Settings > Payments & documents **"End-of-day report recipients"**
(`eod_report_recipients`, finance-gated field — F1 fixed) → Email Settings `email_admin_email` → `email_from_email`.
Invalid addresses dropped; a set but unusable list does not fall back. The page shows the result before sending.

### R1 — End of Day email (clicked)
| Field | Answer |
|---|---|
| Trigger | **Email** → POST `admin/api/end-of-day-send.php`. |
| Report day | The page's date (default today, hotel zone). Live figures; while the day is still trading the subject ends "(as at HH:MM)" and the header says "Still trading: figures as at HH:MM". |
| To | §2.1. None → "No one to send it to. Add an address under "End-of-day report recipients" (Hotel Settings > Payments & documents)…", nothing sent. |
| CC | `rh_daily_reports_cc($to, eod_report_cc_emails + box)`: the saved list ALWAYS, plus the box's extras (box starts empty, is never cleared; F2 fixed). |
| BCC | §1.4. |
| Test mode | Only the test address, [TEST], no CC/BCC; message "TEST MODE: sent only to … Real recipients would be: …". |
| Never twice | No dedupe (intended); each click sends. |
| Figures fail | Nothing is sent: "The End of Day figures could not be loaded, so nothing was sent" + `rh_log_event` error (F6 fixed). |
| Log | `rh_log_event('eod-report', …, 'EOD email send', {date, recipients, cc, test_mode, success})`; Emails sent kind `end_of_day_report`. |
| Content | "The day in brief" (only when the AI lead's hook returns text), score, KPI strip with a helper line per tile, alerts, earned by source, cash collected (credit-note refunds said apart), front office, POS, payment mix, top items, housekeeping (module-gated), tomorrow, footer "Figures as at HH:MM; later changes show on the End of Day page." + `eod-report-D.pdf` from `buildEodPdf(rh_eod_report_data(...))`. |
| Permission | `reports` + CSRF. |

### R2 — End of Day WhatsApp (clicked)
- To: `rh_eod_whatsapp_target()` (eod-report-data.php): **"End of Day WhatsApp number"** (`eod_whatsapp_number`,
  Hotel Settings, finance-gated; owner B2) → `whatsapp_number` → `whatsapp_hotel_number` → `phone_main`. The page
  shows "WhatsApp to: number (source)" before sending. None → error naming the setting.
- Test mode: not sent, clear message. Figures fail: nothing sent. One "The day in brief: …" line when the brief
  fits in 300 characters, else none. Log `rh_log_event('eod-report', …, 'EOD WhatsApp send', {date, to, to_source})`.

### R3 — PDF download
`admin/api/end-of-day-pdf.php` → `rh_eod_report_data()` → `buildEodPdf()`. Figures fail → JSON error, no PDF.
Permission `reports` + CSRF. Same PDF as R1 and R4 (L6 fixed).

### R4 — Daily Operations Report + End of Day PDF (timer)
| Field | Answer |
|---|---|
| Trigger | `rh_job_daily_reports` (auto-email-jobs.php), once per trading day D after it ends. |
| Report day | D = last ended trading day for EVERY part: bookings CSV (created on D or arriving D, not deleted), station-day CSV (trading window, Z rule), accounting CSV (money received on D, refunds paid out on D, shift closes of D, still-owed snapshot from the shared receivables rule — F3 fixed), stock health (now), **End of Day for D** (owner B1). |
| End of Day part | Section `end-of-day` in `rh_daily_reports_build_and_send($…, ['eod' => ['date' => D]])`: `rh_eod_report_data()` → one attachment `eod-report-D.pdf` + headline lines (`rh_eod_headline_lines`) + "The day in brief" block when available. If it fails, the CSVs still go and the email says "End of Day report could not be loaded". |
| To / CC | §2.1 / `rh_daily_reports_cc_all` (daily reports copy + End of Day copy, never a To). |
| Test mode | Plan sends only to the test address, `[TEST] `, stage `test:day:D`, no CC; mailer drops the hidden copy. |
| No recipient | Skipped, NO row (L2). |
| Never twice | Key (`daily_reports`, `trading_day`, YYYYMMDD, `day:D`), claimed before the ONE email that carries both the CSVs and the End of Day PDF. Shared with the CLI default run. |
| Retry / record | §1.5. |
| Log | `automated_email_log`; Emails sent kind `daily_report`, "System (scheduler)"; section failures `rh_log_event('daily_reports','error',…)`. |
| Subject | `[{site}] Daily Operations Report — D` (`[TEST] ` prefix in test mode). |
| Permission | Switch + CCs: `timed_report_emails`. Test recipient and master switch now ALSO need `timed_report_emails` (L16 fixed); interval / Run now: `booking_settings`. |

### R5 — Station report emails (timer) — unchanged except §1.4/§1.5
Each enabled station, its own last ended trading day, items of orders created opening→closing counted when served.
To `automated_job_station_reports_to` (empty = §2.1), CC `automated_job_station_reports_cc`, test mode as R4,
no-recipient skip writes no row, zero tickets → one `skipped` row, key `{station}:day:D`, outcome via
`rh_auto_record_send`. Emails sent kind `station_report`.

### R6 — CLI (`scripts/daily_reports.php`, manual only, never at Rosalyn's)
Default run = exactly R4: every dated section = the last ended trading day, End of Day PDF included, claims R4's
key (`test:` key in test mode) so timer and CLI never both send a day (L9 fixed). `--date/--start/--end` = hand
resend (no claim; End of Day PDF only for a single day). `--force` resends a claimed day (logged). Test mode:
only the test address, `[TEST]`, no CC.

### R7 — Station Report Email (clicked, `admin/kds-report.php`)
Permission `kds_reports` (+CSRF); chef / bar / coffee staff pinned to their station. To prefilled with R5's list
(editable, several addresses), CC `automated_job_station_reports_cc`, sent with `sendReportEmail` (kind
`station_report`), test mode as R1 (L11 fixed; restaurant lead reviews).

---

## 3. Figure parity

Since this round every End of Day output that leaves the page — PDF download, clicked Email (body + PDF),
WhatsApp, timed R4 PDF — is built from ONE function, `rh_eod_report_data($pdo, $date)`
(admin/includes/eod-report-data.php). The page computes the same figures with the same helpers and day rules.

| Figure | Source | Date basis | Page | R1/R2/R3/R4 (shared builder) | R4 CSVs |
|---|---|---|---|---|---|
| Revenue earned (+ parts) | `rh_eod_earned_revenue` | rooms: night D; folio: posted D; POS: trading D; conference/events: event date; gym: payments on D, gym refunds on the day paid out | = | = | – |
| Payments received / cash collected / VAT | inline SQL over `rh_eod_pay_day_sql` | money in: payment date; refunds: day paid out (F4) | = | = | accounting CSV: same day rule |
| Refunds paid out (full amount) | `rh_refund_amount_sql`, completed + processing | day paid out | = | = | = |
| of which moved to credit notes | `rh_eod_refunds_to_credit` (`rh_refund_to_credit_sql`) | day paid out | shown apart (cash panel, refunds row, alert) | shown apart (email row, PDF row, WhatsApp line, headline line) | in the refund total |
| Credit notes redeemed (guest credit used, no new money) | `rh_eod_credit_notes_redeemed`; out of every cash figure via `rh_eod_cash_row_sql` | payment date | own line (cash panel) | own line (email, PDF, WhatsApp, headline line) | accounting CSV still lists it under its method (accounting's own report) |
| Payment mix / Non-cash collected | `rh_eod_payment_mix` (non-cash refunds netted by method; credit-note moves left out — F5) | as above | = | = | own method table |
| Cash to reconcile | cash rows − `rh_eod_cash_refunds_out` | as above | = ("Cash to bank" card) | = | – |
| Still owed | `rh_eod_outstanding_split` / `rh_receivable_rows` | snapshot now | = | = | = (F3) |
| Occupancy / ADR / RevPAR | sellable units, rooms factor, earned rooms | night D | = | = | – |
| Arrivals / departures / no-shows / cancellations | identical SQL; `rh_cancellations_on_date` | D | = | = | bookings CSV rows |
| POS block | `rh_pos_eod_summary` | trading D | = | = (orders by type + void reasons now in every PDF) | station-day CSV Z block |
| Close score | `rh_eod_close_score` (module-gated) | – | = | = (L5) | – |
| Quotations | positional placeholders (L7) | D | = | = (now present) | – |
| Tomorrow / previous day | `rh_eod_tomorrow_preview`; earned vs earned | D+1 / D−1 | = | = | – |
| Headline facts | `rh_eod_headline_facts($d, $can)` — key, label, value, unit (money/count/percent), basis; figures already on the report only, no guest names or refs; `$can(key)` drops what a viewer can't see, null = all | as the figure | built from the page's own figures | built from `rh_eod_report_data` | – |
| The day in brief | AI lead's `rh_ai_eod_mount()` (page) / `rh_ai_eod_email_brief()` (R1, R4, via `rh_eod_ai_brief`); restates headline facts only | – | mounted after the KPI strip only if `includes/ai/ai-eod-narrative.php` exists | email block above the KPI strip; WhatsApp line ≤ 300 chars; PDF untouched | – |

Without the AI file / function, there is no brief section anywhere and no error (tested). Every call into the AI
part (page mount, clicked email, timed email) is wrapped in try/catch(Throwable): on any error nothing is printed
or added and the error is logged.

Day rules shown to staff: `rh_eod_day_rules_note()` now also says "Cash collected uses the date on each payment
(a till sale: its trading day; a front-desk payment: the date entered); a refund counts on the day it was paid out"
(L15 fixed).

---

## 4. Findings

### 4.1 FIX-NOW — all fixed 2026-11-13 (pending accounting-lead gate for F3/F4/F5 and QA)
- F1 FIXED — "End-of-day report recipients" field (hotel-details-settings.php, finance flag); both messages name it.
- F2 FIXED — server always adds `eod_report_cc_emails`; box = extras, never cleared.
- F3 FIXED (money) — accounting CSV still-owed from `rh_receivable_rows` + type column + "unavailable" when a
  source fails; bookings CSV skips deleted bookings.
- F4 FIXED (money) — refunds on the day paid out (`rh_refund_eod_date_sql`) on the page, shared builder, gym
  earned, payment mix, cash refunds out, 7-day trend and the accounting CSV.
- F5 FIXED (money) — credit-note refunds out of the non-cash mix, shown apart everywhere; refunds total unchanged.
- Accounting gate 2026-11-13, B1 FIXED — refund day = first day paid out, never moves (audit-derived, §1.3).
- Accounting gate 2026-11-13, B2 FIXED — credit-note redemptions out of received / cash / mix, own line (§1.3).
- F6 FIXED — figures that cannot be loaded send / print nothing.

### 4.2 LATER
Fixed this round: L1, L2, L3, L5, L6, L7, L8, L9, L11, L15, L16, L17 (wording "Figures as at HH:MM; later
changes show on the End of Day page"), L20, L21 ("Email goes to / CC / WhatsApp to" line + "(as at HH:MM)").
Still open (routed by the orchestrator):
- L4 — a missed / thrice-failed day is lost with no alert.
- L10 — partial POS refunds drop out of their day after a later full refund (accounting lead).
- L12 — covered by B2/B3 (closed).
- L13 — guide 19 wording + timed-tasks row; job label "Daily station reports email" (guides-kb-lead / ui-lead).
- L14 — station report "revenue" = value of items served, not sales (restaurant lead, UI wording).
- L18 — "Still owed" sub-line includes pending (ui-lead).
- L19 — no tourism levy figure on End of Day (owner FEATURE if wanted).
- UI_REQUESTs in §5 not done this round.

### 4.3 Owner decisions — DECIDED 2026-11-13
- B1 DECIDED YES — the End of Day PDF + headline lines ride in the timed daily email (R4) for the ended trading
  day; same recipients, CC = daily reports copy + End of Day copy, same key, no second email. Built.
- B2 DECIDED YES — "End of Day WhatsApp number" setting; until set, the old chain; target shown by the button. Built.
- B3 DECIDED YES — test mode covers clicked End of Day Email, Station Report Email, CLI and the timed emails:
  only the test address, [TEST], no CC/BCC; WhatsApp not sent; messages say so. Built.
- B4 DECIDED — keep as is (any `reports` user may add extra CC; logged).

### 4.4 Closed / confirmed (no action)
- `''` passed to `sendReportEmail` was the text body, not a BCC.
- PATCH RP-2 fixed earlier (discounts / surcharges apart; packages skip deleted / cancelled / no-show).
- Expected arrivals exclude tentative (shown apart).
- F&B double count via non-room-service folio orders is not reachable today (only room-service orders carry a
  booking); if that changes, exclude any order with a linked `booking_charges` row (eod-revenue.php,
  pos-shift-totals.php, dashboard.php).

---

## 5. UI requests still open
UI_REQUEST | admin/end-of-day-report.php | "Cash to bank" card | no helper; label differs from "Cash to reconcile" | one label + helper
UI_REQUEST | admin/end-of-day-report.php | "Close score" card | no helper | list what lowers it (the email now has one)
UI_REQUEST | admin/end-of-day-report.php | "Still owed" card | pending in the sub-line but not the total (L18)
UI_REQUEST | includes/eod-pdf-builder.php | PDF KPI tiles | no helper lines (the email tiles now have them)
UI_REQUEST | admin/end-of-day-report.php | "Email goes to …" line (new, plain `eod-sub` text) | needs the shared style
UI_REQUEST | includes/auto-scheduler.php | job label | "Daily station reports email" → "Daily operations report email"
UI_REQUEST | admin/kds-report.php | station "revenue" | "Value of items served (not sales)"

## 6. Configuration checks (owner / orchestrator, read-only)
C1 both: `email_bcc_admin` = 1 and info@promanaged-it.com is the staff copy or a backup staff copy email (else the
MSP mailbox misses R1/R7; R4/R5 reach it by visible CC).
C2 both: fill "End-of-day report recipients" (owner chooses; never set at Rosalyn's without the owner).
C3 Liwonde: Test recipient = johnpaulchirwa@gmail.com → every report email goes only there.
C4 both: `site_timezone` unset or Africa/Blantyre.
C5 both: "End of Day WhatsApp number" (owner chooses).

## 7. Invariants
1. Every report email has ONE answer in §2 for trigger, day, To, CC, BCC, test mode, no-recipient, dedupe, retry,
   log, permission.
2. Every End of Day output that leaves the page comes from `rh_eod_report_data()`; the page uses the same helpers.
3. One clock: `rh_eod_now()` (site time zone).
4. The timer never sends a day twice; a delivered send is never re-sent; a skipped no-recipient day is not lost.
5. Test mode: only the test address, [TEST], no CC/BCC, for every report email; no WhatsApp.
6. Rosalyn's is live: no test, script or CLI run sends a report there or changes its recipient settings.
7. Never a report of zeros: if the figures cannot be loaded, nothing is sent or printed.
8. No cron, no MRA wording, no AI-looking UI; the brief appears only when AI is available.
