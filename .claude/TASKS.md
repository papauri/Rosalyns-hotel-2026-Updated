# Live task tracker (read this FIRST when a session resumes)

Kept by the orchestrator: updated when a task starts, when an agent is dispatched or reports,
and when work ships. One line per task: status · what · where it stands · next step.
Status: TODO · DOING · REVIEW · SHIPPED · BLOCKED (owner).

## Open
- TODO · Linked-data follow-ups · API payment delete has no automated test (needs an HTTP harness that leaves no live rows); owner decisions in .claude/plans/linked-data-cleanup.md (group bookings, consumed room service on cancel, CN vs cash, POS receipt history, gym bill on member cancel, orphan gallery row 43).
- TODO · Form validation across other admin create/edit forms (duplicates flagged while typing, form reopens with the reason) · page messages are visible again everywhere (alert fix), so server-side rejections now show; next: apply the user-management pattern to the busiest forms.

## Shipped 2026-10-20
- Payment audit log on every payment edit and refund (admin, API, settle, cancel/CN/no-show refunds); admin edit cannot reopen a cancelled payment; "Order note cleared" in POS accounting; scripts/tests/linked_money.php (24 checks) in run_tests.
- Linked-data QA follow-ups: cancelled payments cannot be reopened or re-amounted via the API; system order closes record no user (not id 0); clearer credit-note re-open message.

## Shipped today (2026-10-19)
- Linked-data cleanup: cancel/delete/no-show/void close or remove everything linked (open tabs, kitchen tickets, room holds, quotations, gym bookings, room photos/files); money kept and reversed, never deleted; scripts/data-health.php in run_tests.
- No-access pages: friendly card on the person's main page (access-notice.php) instead of a silent bounce.
- Backups: delete (newest + 3 always kept), working restore with safety backup, in-page run, plain-words alerts (failed / 36h stale / weekly off-site) to the admin email; new dept-backup-lead.
- User management: invisible page messages fixed system-wide (.alert opacity), live duplicate username/email check, form reopens with reason, busy buttons, no default Administrator role.
- Bulk bar pinned on screen; Individual Rooms uses the shared bulk bar.
- d764c30 / dc51157 Owner fixes: modal widths, edit-booking layout, table delete, ingredients paging, staff invites, receipt WhatsApp template, manifest.
- 0c29d3f / 2ccec73 ship.php, CODE_INDEX, cost rules, guides, toggle confirms.
- 3c0d1a3 / d7de0ad Stock zero-stock selling, opening count, one alert rule; admin UI for phones/tablets.
- 5cbf815 / da0ccb3 Confirm + guest-bill charges need Edit Booking.
- 570f23f / 84c7a63 Permission-based dashboards/menus/links; guides.
- f24dc66 / d97028a Facebook Connect.

## Owner to-dos
- Set the Admin email in Email Settings so backup alerts reach someone; download a backup copy weekly.
- Deploy both hotels; Facebook: add redirect URI, App ID/Secret, Save and connect.
- Costs for 7 zero-cost ingredients, then opening stock count (second manager approves).
- Grant Edit Booking / Add Payments / Cancel Bookings to receptionists who need them.
- Try Orders & Tabs bulk actions and one PO over-delivery once real orders exist.
