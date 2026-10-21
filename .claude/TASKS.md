# Live task tracker (read this FIRST when a session resumes)

Kept by the orchestrator: updated when a task starts, when an agent is dispatched or reports,
and when work ships. One line per task: status · what · where it stands · next step.
Status: TODO · DOING · REVIEW · SHIPPED · BLOCKED (owner).

## Open
- DONE · Liwonde dress rehearsal (readiness gate step 3) · findings fixed and shipped 2026-10-20/21.
- SHIPPED d1a4490 / Liwonde 674d2e4 · Receiving gaps: editable invoice price at PO receive (per pack or unit), receive pack lines in packs, barcode receive expiry date, barcode pack size defaults to the item's crate.
- SHIPPED 4256596 / Liwonde 0988ecc · Stock count approval (follow-ups done; layout fixes shipped 85244f7 / Liwonde 2cd33ff): 'stock_count_approve' permission (not just managers), "needs a second person" labels, email approvers on submit (optional) + Remind approvers button.
- SHIPPED 89d5cd4 / Liwonde 2a6b122 · Stock counts: admin switch to turn off the second approver (default ON); Remind approvers only for counter + approvers. Test email sent to 'test' only (count 20261020-4FB5).
- SHIPPED 3a23665 / Liwonde d9ab6ae · PWA lead patch pass: offline replay never drops a refused sale (fresh CSRF, only real success clears it), admin SW no longer caches ?ajax=/api or redirects, sign-out clears cached pages, KDS poll 20s timeout, public SW keeps all *-confirmation pages private. documents_flow test now uses hotel timezone (failed 00:00-02:00 Malawi).
- SHIPPED 3a23665 / Liwonde d9ab6ae · Offline sales held for the cashier who took them (A+B) + sign-out sends first, never traps (8s wait, 20s replay timeout). Not covered: 8-hour idle sign-out (B still holds), entries queued before the update. Guides update dispatched.
- SHIPPED 69f2a51 / Liwonde b4e8408 · Cards & graphs accuracy audit + patch, all 3 batches (money, rooms/F&B, stock/gym/other): ~35 wrong figures fixed; accounting + QA reviewed; browser-checked 30 screens. Guides update dispatched.
- DOING (accounting lead pass) · Occupancy/ADR one rule (owner 2026-10-21): base = all rooms minus out-of-order minus maintenance; ADR incl. VAT, on dashboard, EOD (+PDF/email), Reports. Start after accounting review + batch 2 finish (same files).
- DOING (accounting lead pass) · Rooms lead: 'Rooms to Clean' (getRoomDashboardSummary) = status cleaning OR open task, but Cleaning Queue/card = status cleaning only; pick one meaning. Add to the occupancy pass.
- DOING (accounting lead pass) · EOD reviews line: published reviews only. DONE in 69f2a51: Orders & Tabs today = paid date for settled. NOTE (no change, screens use end date): nothing sets gym_members.status='expired'.
- DOING (accounting lead pass) · Cancellations dated from booking history (owner chose option 1, 2026-10-21): EOD/reports count cancellations by the timeline cancellation entry, not updated_at; first confirm every cancel path (front desk, guest online, group, API, no-show) writes it. No DDL. Same pass as occupancy/ADR.
- ON HOLD (owner yes/no, feature freeze) · Form validation across other admin create/edit forms (duplicates flagged while typing, form reopens with the reason) · page messages are visible again everywhere (alert fix), so server-side rejections now show; next: apply the user-management pattern to the busiest forms.

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

## Owner to-dos
- Set the Admin email in Email Settings so backup alerts reach someone; download a backup copy weekly.
- Deploy both hotels; Facebook: add redirect URI, App ID/Secret, Save and connect.
- Costs for 7 zero-cost ingredients, then opening stock count (second manager approves).
- Grant Edit Booking / Add Payments / Cancel Bookings to receptionists who need them.
- Try Orders & Tabs bulk actions and one PO over-delivery once real orders exist.
