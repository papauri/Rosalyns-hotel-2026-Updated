---
name: project-reminder-email-precedence
description: The single booking_reminder email serves both missed-check-in and overdue-checkout; precedence rules that keep no-show out of late-checkout
metadata:
  type: project
---

The `booking_reminder` email type (template key `booking_reminder` in `config/email.php`, sent via `sendBookingReminderEmail()`) is ONE email reused for TWO distinct conditions: missed/upcoming check-in (potential no-show) and overdue checkout. The admin resend modal in `admin/bookings.php` auto-selects it whenever a row is `$is_missed_checkin || $is_overdue_checkout`.

Precedence rules now enforced inside `sendBookingReminderEmail()` (highest first):
1. Blocked statuses (`no-show, cancelled, checked-out, completed, expired`) throw — never get a reminder. No-show supersedes all time-based logic.
2. Not-checked-in bookings are always treated as potential no-show (check-in language, driven by `check_in_date`) — even if checkout date also elapsed. Never framed as late checkout.
3. Only `status='checked-in'` + checkout date passed → overdue-checkout language driven by `check_out_date`.

**Why:** the original function derived everything from `check_in_date` and always emitted arrival/no-show language, conflating the two notifications. Detection in the UI views (bookings.php list flags + SQL, booking-details.php alert, housekeeping queries) was already status-disjoint — the defect was purely in the email layer.

**How to apply:** any new caller/scheduler of reminders must respect that a not-checked-in booking is a no-show candidate, not a late checkout. The status gate `= 'checked-in'` is what keeps no-show out of overdue-checkout detection. See [[project-booking-status-conventions]] for the hyphenated status values.
