# Next build order (owner-approved 2026-11-14)

## How to run this queue (every session follows this exactly)

The owner starts EVERY session with the same prompt: **"Run the next step of .claude/NEXT_BUILD_ORDER.md"**.

1. Read ONLY this file and the "Open" section of `.claude/TASKS.md`. Do not re-plan; the plans are final.
2. Pick the FIRST row in the queue whose Status is not `SHIPPED`. If it is `DOING`, resume it (check git log + TASKS.md
   for what is already done). Never skip ahead, never run two steps in one session.
3. Set that row to `DOING <date>` here and add a DOING line in TASKS.md.
4. Execute that step's brief (below) exactly. Standing rails apply: ROSALYN'S IS LIVE (no DB tests, scripts or test
   emails there; Liwonde only), DEPARTMENTS.md ship gate, one builder + one review, stop every `php -S`.
5. If an owner decision is genuinely needed, ask it, park the row as `BLOCKED: <question>` and stop. Do not start the
   next step.
   Steps 1-5 also add their entry to `.claude/GUIDES_AI_SYNC.md` in the ship commit (DEPARTMENTS.md rule 5,
   owner 2026-11-14: guides + Assistant always last). Model policy: Opus builds; Fable 5.2 only in the three cases in
   DEPARTMENTS.md (step 4 qualifies for ONE Fable call).
6. When the step has shipped to BOTH hotels: set the row to `SHIPPED <date> <Rosalyn's hash> / <Liwonde hash>`, update
   TASKS.md, commit + push, and END the session with exactly this message to the owner:

   > Step N shipped (<one-line summary>). Please run **/clear**, then paste:
   > **Run the next step of .claude/NEXT_BUILD_ORDER.md**
   > Next up: step N+1 - <its title>.

   If it was the last row, say "Queue complete" instead and give the SESSION SUMMARY.

## Queue

| # | Status | Step | Read | Builder -> gate |
|---|---|---|---|---|
| 0 | SHIPPED 2026-11-14 7a4cadc + dee8489 + 976db55 (other session; its LATER items stay in TASKS.md) | Wait: Hotel Settings change log + settings page order/search shipped (other session) | TASKS.md "DOING 2026-11-14 ... logs lead" | check only |
| 1 | SHIPPED 2026-11-14 e83be44 / 5d72c79 | B1 Guest booking lookup correctness (+ "Booking Received" heading) | GUEST_BOOKING_ALIGNMENT_PLAN.md F1, F5, F8, F11, §3 B1 | dept-guest-site-lead -> dept-accounting-lead + qa-auditor |
| 2 | SHIPPED 2026-11-14 5599ae0 / d1aa02d | B3 History price snapshot on online bookings | GUEST_BOOKING_ALIGNMENT_PLAN.md F4, §3 B3 | dept-rooms-lead -> qa-auditor opus |
| 3 | SHIPPED 2026-11-14 754aba7 / 19fdb11 | F7 Live quote for 2+ joined room pairs | GUEST_BOOKING_ALIGNMENT_PLAN.md F7 | dept-rooms-lead -> qa-auditor opus |
| 4 | SHIPPED 2026-11-14 eaef2d2 / d408fc2 (switch OFF; 085 run at Liwonde only, Rosalyn's waits for the owner) | Cancellation & no-show policies (+ F2 whole-booking guest cancel) | CANCELLATION_POLICY_SPEC.md | one Opus builder -> dept-accounting-lead + qa-auditor opus |
| 5 | TODO | F10 Guest booking emails (rate x nights, rate plan, all room refs) | GUEST_BOOKING_ALIGNMENT_PLAN.md F10 | dept-guest-site-lead -> dept-accounting-lead |
| 6 | TODO | Staff guides + help search sync | this file, step 6 brief | guides-kb-lead (haiku) -> haiku review |
| 7 | TODO | AI Assistant sync | this file, step 7 brief | dept-ai-lead (Opus) -> opus review |

**LATER (never pulled by this queue; only when the owner asks):** F3 + F6 + F5 API part (no outside client uses the API,
owner 2026-11-14) · F9 split-booking note into booking_notes · F11 payment line from `payment_policy` · admin
create-booking packages.

Owner decisions behind this order: GUEST_BOOKING_ALIGNMENT_PLAN §4 and CANCELLATION_POLICY_SPEC OWNER-Q 1-3, tier
boundary ("fewer than X days": cancelling exactly 7 days before is free), waive permission (admin + manager default,
grantable to anyone).

## Step briefs

**Step 0 - check only.** Read the "Open" section of TASKS.md and `git log -10`. Has the Hotel Settings work (settings
change log + settings page order/search, logs lead + ui-lead, "DOING 2026-11-14") shipped to BOTH hotels? If yes: mark
step 0 SHIPPED with the hashes and end with the clear-session message. If no: tell the owner what is left, leave step 0
TODO and stop. Build nothing.

**Step 1 - B1 guest lookup.** Read GUEST_BOOKING_ALIGNMENT_PLAN.md §2 F1, F5 (lookup part only), F8, F11 (heading
"Booking Received" for pending ONLY; the payment line is unchanged) and §3 row B1. Owner decisions are in §4; do not
reopen them. Builder: dept-guest-site-lead (Opus); money gate: dept-accounting-lead; then one qa-auditor review. Write
the new test scripts/tests/booking_lookup_group.php as the plan says (refuses Rosalyn's). Tests and browser check
(1400 + 390) at LIWONDE only. Ship to both hotels.

**Step 2 - B3 History price snapshot.** Read GUEST_BOOKING_ALIGNMENT_PLAN.md §2 F4 and §3 row B3. Builder:
dept-rooms-lead (Opus); tell dept-logs-lead what changed; gate: qa-auditor (opus). Touches the booking.php,
includes/mixed-booking.php and api/bookings.php create paths plus includes/booking-timeline.php. Logging only, never
fatal, old bookings untouched. Run suites from the Liwonde root; shared include = full suite once at Liwonde before
ship. Ship to both hotels.

**Step 3 - F7 joined quote.** Read GUEST_BOOKING_ALIGNMENT_PLAN.md §2 F7 only. check-availability.php: build the same
non-overlapping combination queue booking.php uses (:385-:396) and price each room off its own combination. Joined
rooms are a real feature: keep the cross-type availability rules intact. Builder: dept-rooms-lead (Opus), or inline if
under ~10 tool calls; gate: qa-auditor (opus). Browser check of the booking.php summary with a joined type at
Liwonde. Ship to both hotels.

**Step 4 - Cancellation & no-show policies.** Confirm steps 1-2 are SHIPPED. Read CANCELLATION_POLICY_SPEC.md in full.
Every owner question is answered (Q1 online cancel with an "I accept" tick: yes; Q2 auto-release of unpaid: no fee; Q3
pending: no fee; tiers charge at FEWER THAN X days; waive permission admin + manager by default, grantable). It
includes F2 (guest cancels the whole party: only rows under the proven email; company-billed parties see "contact the
hotel") and §4's corrected stamp placements. ONE Opus builder; money gate: dept-accounting-lead; then one qa-auditor
(opus). Model policy case (a): ONE Fable 5.2 spec check of CANCELLATION_POLICY_SPEC.md (effort high, exact files,
one verdict) BEFORE the builder starts. Ship with the switch OFF. Migration 085 runs at LIWONDE only; ask the owner before running it at Rosalyn's,
with the exact command. Ship to both hotels.

**Step 5 - F10 guest emails.** Read GUEST_BOOKING_ALIGNMENT_PLAN.md §2 F10. Add "booked rate x N nights", the rate plan
line and every room reference for groups as an appended block from buildBookingEmailVariables (config/email.php), the
same mechanism step 4 added for `{{cancellation_policy_text}}`. Stored DB templates are live data: never edit them.
Builder: dept-guest-site-lead (Opus); money gate: dept-accounting-lead. No test emails at Rosalyn's; Liwonde test mode
only. Ship to both hotels.

**Step 6 - Staff guides + help search.** (Owner pre-approved this run as part of the queue, 2026-11-14.) Read
`.claude/GUIDES_AI_SYNC.md` (every pending entry, incl. those carried over from the old SYNC QUEUE) to list what changed
for staff. Expected: group documents (per-room invoice,
receipt per room, group charge), kept cancellation fees, Hotel Settings change log + settings page order/search, guest
lookup fixes, History price note on online bookings, cancellation & no-show policies (settings, tiers "fewer than X
days", locked at booking, fee shown before cancel, lower/waive permission, guest whole-booking cancel online), guest
email additions. Agent: guides-kb-lead (haiku). Update ONLY the matching pages in docs/guides/ (expect 07, 13, 14, 19,
faq) in plain staff language, with a worked cancellation-fee example (MWK 100,000 x 3 nights). Verify the KB search
(includes/guide-knowledge-base.php) answers: "how do I cancel with a fee", "can I waive a cancellation fee", "where do
I set the cancellation policy", "who changed this setting". Rosalyn's has no gym: keep gym pages hidden there. No
AI-looking wording. Docs only, no DB. One haiku review. Ship to both hotels and mark the guides part of each entry
done in GUIDES_AI_SYNC.md.

**Step 7 - AI Assistant sync.** (Owner pre-approved as part of the queue.) Only dept-ai-lead (Opus) builds or tests
the Assistant. Read `.claude/GUIDES_AI_SYNC.md` (Assistant part of every pending entry) and CANCELLATION_POLICY_SPEC.md
§1, §3 and §5. Grep includes/ai/
(ai-tools.php, ai-money.php and the read tools) for every place that shows money owed, refunds, cancellations, no-shows,
group totals, booking History or settings. Update only where a shipped change makes an answer wrong or incomplete.
It must answer correctly:
- the cancellation fee for a booking, from its LOCKED policy, with booked rate x nights, the tier and the days before
  arrival (old bookings follow today's rules)
- kept fees and no-show charges in "still owed"
- group totals per room
- who changed a hotel setting and when, old -> new, secrets never shown

Rails stay:
- preview-then-confirm for every action
- permissions respected (`waive_cancellation_fee`)
- never "none/0" without a checked total
- no hard-coded hotel facts
- the Assistant may quote a fee but never cancels, lowers or waives one without the staff member's confirm

Add eval cases for each new answer. Run only the Assistant suites, at LIWONDE (cheapest top-3 models, minimal live
tests). One opus review. Ship to both hotels and mark the Assistant part of each entry done in GUIDES_AI_SYNC.md.
