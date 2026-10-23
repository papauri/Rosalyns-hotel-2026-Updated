# Shipped task log (archived 2026-10-24)

## Shipped 2026-10-20
- Receipt on every payment (all paths/types, pending->paid, till/split); quote before, invoice only when owed, paid-in-full statement; Block Rooms enforced; stock loss controls (alert shading, manual additions, count reminder, Needs review); ? help on every page; guides; OWASP claim removed. Accounting final sign-off: production-ready for money. Readiness gate written into BUILD_PLAN.md.
- Stock budget (Stock > Budget): open-to-buy per month/category, fixed or % of expected sales, PO send check (warn / needs approval + reason), dashboard tile, Buying hint, permissions Stock Budget (view)/(set & approve), 65-check test.
- Owner decisions: group cancel/delete ask each time; started room-service food stays billable on cancel; POS order delete keeps receipt history; gym member cancel voids unpaid bills; orphan gallery row deleted. Tests: linked_gym, api_payment_delete.
- Form checks on menu items, ingredients, suppliers, rooms, room types, tables (live duplicate warning, reopen with reason, busy button).
- Mobile: admin menu drawer on phones/tablets, slim header; POS cart footer pinned.
- Guides + FAQ + KB updated for all of the above.
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
