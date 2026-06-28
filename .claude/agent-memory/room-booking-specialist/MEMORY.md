# Room Booking Specialist — Memory Index

- [Availability Engine](project-booking-availability-engine.md) — checkRoomAvailability() in config/database.php; create-booking must conflict-check inside the locked transaction
- [Status Conventions](project-booking-status-conventions.md) — statuses use hyphens (checked-in); rooms_available decrement/increment rules per transition
- [Reminder Email Precedence](project-reminder-email-precedence.md) — one booking_reminder email serves missed-check-in AND overdue-checkout; no-show never gets a late-checkout alert
- [Editable Email Templates](project-editable-email-templates.md) — four sync points to add a DB-backed, admin-editable, premium-shell booking/finance email
- [Housekeeping Cleanup & Assignment](project-housekeeping-cleanup-assignment.md) — one open checkout-cleanup per room; cleaning status gates assignment; getRoomHousekeepingAssignmentBlock() reasons
