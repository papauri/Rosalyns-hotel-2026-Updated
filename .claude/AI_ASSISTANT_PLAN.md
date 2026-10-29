# AI Assistant ("Assistant") for the admin portal — build plan

> Executor: **Claude Opus 5.5** (owner instruction 2026-10-26: "leave all coding to Claude Opus 5.5").
> On approval, copy this file to `.claude/AI_ASSISTANT_PLAN.md`, add one line to `.claude/TASKS.md` "Open"
> and one entry to `.claude/PATCH_QUEUE.md` (owner-approved FEATURE), then build phase by phase.
> Applies to **both hotels** (Rosalyn's + Liwonde, identical except front end/settings). Test on Liwonde first.

## Context

The owner wants an in-portal AI lead: a multi-agent assistant that knows hospitality, concierge work and
this hotel's live data (bookings, rooms, guests, sales, who is logged in and their preferences), answers
and analyses anything, helps boost sales, and can carry out front-desk work — including making a full
booking — from a chat panel inside the admin portal. It must be switchable off completely, limited to chosen
staff, run on the owner's own API key from any of five providers with live model discovery and a connection
test, and **must not break anything**. Stock, POS, payments/refunds, accounting, users and settings stay
out of its write reach.

### Owner decisions (2026-10-26, via questions)
1. **Writes = preview, then staff confirm.** The AI never saves anything by itself. It produces an action card
   (what will change, server-worked price); the staff member taps **Confirm**.
2. **Allowed actions:** create booking; edit/cancel booking; check-in/check-out; housekeeping status and
   maintenance jobs; send existing guest email/WhatsApp templates. Each also needs the staff member's own
   existing permission for that action.
3. **Providers:** Anthropic, OpenAI, Google (Gemini), xAI, DeepSeek. Settings page notes DeepSeek is China-hosted.
4. **Guest data:** superseded by decision 5 — personal data no longer leaves the server at all.
5. **Local-first (owner, 2026-10-26 follow-up):** "most of the data stays on the local DB and is not sent out
   to any cloud even though reasoning uses the AI model. Its own little LLM that learns and grows
   autonomously, self-patching and healing, learning from mistakes. Fully ready with a kill switch."
   → see **Local-first data**, **Learning loop**, **Self-healing** and **Kill switches** below.

## Behaviour & scope (owner, 2026-10-26 second follow-up)

- **Stays in its lane:** answers only about this hotel, hospitality and concierge work (operations, guests,
  sales/revenue, F&B, events, local area for guests, how to use the portal). Anything else → one friendly line
  ("I can only help with the hotel and guest matters") and an offer of something it can do. Enforced three
  ways: system prompt rule; a cheap topic check by the Lead on each new request; eval questions that must be
  declined (e.g. homework, coding, politics, personal advice).
- **Web access, only to answer questions:** tool `web_lookup` (read-only) for things like flight times, weather,
  local attractions, opening hours, exchange rates, competitor public rates, hospitality best practice. Uses
  the provider's built-in web search where available (Anthropic web search tool, OpenAI web search, Gemini
  Google Search grounding, xAI live search); otherwise disabled. The outbound query passes the scrubber — no
  guest/staff data in searches. Never posts, logs in, books or submits anything on the web. Sources shown as
  links under the reply. Own on/off switch in settings (part of kill switch 4).
- **Asks until it understands:** if a request is ambiguous or missing a needed detail (dates, which guest, which
  room), it asks one short, specific follow-up at a time (with tap-to-answer options where possible) and keeps
  asking until it has enough — it never guesses a booking detail.
- **Direct, not wordy:** default reply = the answer first, at most ~3 short sentences or a short list; figures
  as a compact table. A "More detail" button asks for the longer version. Enforced via prompt + `max_tokens`
  per reply + eval checks on length.
- **Adapts to the person logged in:** keeps a local per-user profile in `ai_memory` (scope `user`): preferred
  name, language, tone (formal/casual), detail level, usual tasks, role habits, times they work. Learned
  automatically from how they write and what they accept/ignore; applied every turn. Each person can see,
  edit or reset their own profile in the panel ("What I've learned about you"). Stored locally only.
- **Personality:** an all-rounder — friendly, warm and professional, like a seasoned hotel duty manager and
  concierge in one. Plain staff English, no jargon, no emojis unless the person uses them.

## Extras picked by the owner (2026-10-26, third follow-up)

**Security**
- **PIN/password on risky confirms:** cancel, check-out and sending guest email/WhatsApp require the staff
  member to re-enter their password (or existing PIN if the user has one — reuse the POS/station PIN helper if
  present, Phase 0 checks) on the action card. Verified server-side in `ai-action.php confirm`; 5 wrong tries
  lock actions for that user for 15 min.
- **Tamper-evident audit + weekly digest:** each `ai_actions` and outbound-request log row stores
  `prev_hash` + `row_hash` (SHA-256 chain); `scripts/ai-health.php` verifies the chain and raises an incident if
  broken. Weekly email (existing `sendEmail()` in `config/email.php`) to `ai_settings` holders: actions taken,
  who confirmed, cost, incidents, what left the hotel (token counts, no data). Owner-only recipients — not a guest
  message.
- **Idle lock + logout wipe:** panel locks after `ai_idle_lock_minutes` (default 5) without activity — re-open
  needs a tap + password if an action card is pending; conversations bound to the login session id; logout and
  session expiry purge that session's `ai_vault` rows (AI tables only, `DELETE … WHERE session_id = ?`).
- Not picked: outbound host allowlist / injection filter as a separate layer. Baseline stays: guest text and web
  results are passed as quoted untrusted data, nothing executes without a human confirm, keys never logged.

**Staff**
- **Proactive alerts & daily brief:** `scripts/ai-alerts.php` (every 15 min, local SQL only — no cloud call)
  writes alerts to `ai_alerts`: arrivals today with balance owed, overbooking/unassigned rooms for tonight,
  returning guests (≥2 past stays), dirty/inspecting rooms within 2 h of an arrival, dates in the next 30 days
  under the occupancy threshold, open maintenance blocking a sold room. Shown as a badge on the panel, filtered by
  each user's permissions. The morning brief (per-user pref) summarises them in 3–5 lines.
- **Draft replies for enquiries & reviews:** new conference/event enquiries and new reviews get a "Draft reply"
  in the panel (Concierge specialist; tokenised); sending uses the existing send path via a confirm card (PIN).
  Review replies only where the existing reviews screen supports a reply.
- **Reports on request:** tool `make_report` maps to existing exports only (`api/reports-export.php`,
  `admin/api/end-of-day-pdf.php`, existing CSV/PDF buttons) — returns a download link generated by the existing
  code under the user's permissions; email-to-self via existing senders. No new figures.

**Sales (Revenue specialist)**
- **Rate suggestions per date:** read-only `rate_suggestions(range)` from pace vs same period last year,
  occupancy on the books, lead time, lost demand and events calendar → table of date / current rate / suggested /
  why. A manager applies it on the normal rates screen; there is no tool that writes prices.
- **Upsell finder:** arrivals in the next 7 days without upgrade/breakfast/transfer/gym (Rosalyn's has no gym —
  respect module state), with suggested offer + ready message (send via confirm card).
- **Review insights:** themes and sentiment from `reviews` (+ scraped review sources already stored by
  `admin/api/review-scraper.php`), ranked fixes, monthly trend.
- **Lost-demand tracker:** additive logging of public availability searches that returned no room and of
  declined/lost enquiries into a new `demand_log` table (date range, guests, room type, reason; **no personal
  data**). Touches the public availability path → `dept-guest-site-lead` + `dept-rooms-lead` review; the insert is
  try/catch-wrapped so a logging failure can never block a guest search. Feeds rate suggestions.

**Guests:** staff only for now. A website/WhatsApp concierge would be a separate future plan.

## Owner direction (2026-10-26, after Phase 1 shipped) — governs Phases 2–5

Owner: "let AI be the main man, there should be no reason to check most things through manual clicks. It
should be everything to the users and pretty much something they will be obsessed with while being also the
most cheapest helpers token wise. No wastage but extra high reasoning naturally because of learning and
intelligence."

How every later phase applies it (`dept-ai-lead` designs to this; hard limits above are unchanged):
- **Assistant-first.** Any question staff would answer by clicking through screens (arrivals, who owes, room
  board, occupancy, revenue, guest history, what to do next) must be answerable in chat with the same figures
  as the screen. Actions keep one-click preview → Confirm; no manual form filling. Where the chat can't act
  (stock/POS/payments etc.) it gives the answer plus a one-tap link to the exact screen, pre-filtered.
- **Proactive, not just reactive.** Shift-start briefing, live nudges (late arrivals, unpaid check-outs,
  dirty rooms with arrivals due), suggested next replies as tap chips, remembers each person's habits.
- **Cheapest per answer — no waste:** (1) answer locally first with zero tokens when possible (learned
  playbooks, KB, deterministic read tools for exact lookups, short-TTL cache of identical read queries per
  user/permission); (2) route: the **Fast** model (cheapest, e.g. Haiku 5.5) classifies and handles short
  replies, the **Primary** model only for reasoning/analysis/multi-step work; (3) frozen system prompt +
  deterministic tool list with prompt caching, volatile data after the cache breakpoint; (4) tools return
  compact JSON (ids/tokens, no prose), only the tools the user's permissions allow; (5) history summarised,
  never resent whole; (6) per-route effort: low for chat/lookups, high/xhigh only for analysis and planning.
  Usage page shows cost per answer and the local-hit rate.
- **A swarm of internal leads, "the brains behind the brains" of the shift** (owner, same day: "a swarm of
  different leads within it that give us the full scoop / full tea of details in our hotel... super
  intelligent and fully autonomous... depending on who you are logged in as"). Phase 3's specialists mirror
  the hotel's departments: Front Desk & Rooms, Housekeeping & Maintenance, Restaurant & Bar, Money
  (accounting reads), Stock (reads), Events/Conference/Gym, Revenue & Sales, Guest voice (reviews, enquiries,
  concierge), System Guide. Each sees only what the logged-in user may see. They watch on their own (read
  side is fully autonomous: scans, spots, explains, ranks) and bring the user a **role-shaped "full tea"**:
  - Owner/admin → *where am I losing money*: unpaid/overdue balances, discounts/comps/overrides, voids and
    refunds, wastage and stock variance, budget overspend, unsold nights vs pace, cancellations, cost of
    no-shows — each with the amount, the cause and who/what drove it.
  - Manager → shift health across departments and what needs a decision now.
  - Receptionist → *where am I losing time / what did I miss*: due-outs not checked out, arrivals not checked
    in or with no room assigned, balances to collect before departure, unconfirmed/tentative bookings expiring,
    unanswered enquiries and emails, rooms not ready for the next arrival.
  - Housekeeping / maintenance / restaurant staff → their own queue, blockers and slow spots.
  Every point carries a one-tap fix (action card or deep link). "Fully autonomous" applies to watching,
  analysing and preparing; anything that changes data is still one-tap Confirm (hard limits unchanged).
- **Knows exactly who it is talking to** (owner, same day). Each turn carries the user's role, permission
  keys and enabled modules; the tool list is filtered to them, and every handler re-checks. When asked for
  something the person may not see, it **declines politely in one line** ("That's outside what your account
  can see — a manager or admin can help with that"), never hints at the figure, never leaks it through a
  total/aggregate/comparison, and never suggests a workaround. It can always tell the user what they *can*
  do (`my_access`).
- **Guides & KB expert** (owner, same day). Knows every staff guide and the KB: answers "how do I…", and
  **summarises a whole guide or section on request** (`how_to` + `summarise_guide`), limited to guides the
  user may see (`rh_kb_allowed_guides()` + module/permission of the guide's screen), with the guide link.
  Summaries come from the guide text only (no invented steps).
- **Never guesses** (owner, same day: "never guess answers just to get out of a sticky situation, if it's
  not in the database like an amount or booking etc don't make things up"). Every amount, booking,
  reference, date, count, name or status in a reply must come from a tool result in this turn; if the tool
  finds nothing it says so plainly ("I can't find a booking for that name — check the spelling or the
  dates?") and offers the next step. No estimates dressed as facts, no "probably", no filling gaps from
  general knowledge. Enforced in code: a reply-check flags numbers/references not present in this turn's
  tool results and the reply is regenerated or the figure removed; tested with empty-result fixtures.
- **Real conversation, within the hotel's realm** (owner, same day). It chats naturally — follow-ups,
  "and yesterday?", small talk, encouragement during a busy shift, hospitality advice — staying on the
  hotel, guests, the shift and the portal; anything else gets a friendly one-line steer back.
- **Look: knowledge and strength, minimalist, powerful in stature** (owner, same day: no sparkles or
  magic-wand icons "like a typical AI UI"). No sparkle/wand/robot/star/brain icons, no gradients, glows,
  shimmer, emoji or bouncing "typing" dots; no "AI"/"smart"/"magic" wording. Instead: the portal's own
  design tokens, solid surfaces, strong clear type hierarchy, generous spacing, one accent colour, crisp
  1px dividers; findings set out like a briefing document (headline figure, cause, source, one-tap link);
  a calm plain status line while it works ("Checking today's departures…") naming the real step; quiet,
  confident wording. Launcher stays the plain "Assistant" button (`fa-comments`).
- **High reasoning through learning, not bigger prompts.** The learning loop turns confirmed outcomes and
  corrections into compact lessons/playbooks that make later answers both smarter and cheaper (a learned
  playbook replaces a long reasoning call). Eval set tracks quality *and* tokens per answer; a change that
  raises cost without raising the eval score is rejected.

## Local-first data (the cloud model reasons; the hotel's data stays home)

The cloud model is used as a **reasoning engine only**. It plans which local tools to call; PHP runs them
against the local DB; only the minimum it needs to reason comes back to it, already de-identified.
- **Pseudonymisation vault** (`includes/ai/ai-vault.php`, table `ai_vault`, per conversation): every guest
  name, email, phone, booking reference, staff name and address in a tool result is swapped for a token
  (`[GUEST_7]`, `[EMAIL_7]`, `[BK_31]`, `[STAFF_2]`) before it leaves. The model answers in tokens; the server
  swaps real values back in **locally** before the panel shows the reply. The provider never sees who anyone is.
- **Outbound scrubber:** the staff member's own typed message is scrubbed the same way before sending
  (emails/phones by pattern; guest and staff names by matching the local DB). Card numbers, ID/passport
  numbers and addresses are dropped, never tokenised.
- **Aggregate, don't dump:** analytics tools compute figures locally (occupancy %, ADR, counts, totals) and
  return small summaries, never raw tables. Row-level results are capped (default 20 rows, tokenised).
- **Booking creation with no PII out:** the model proposes "book [GUEST_NEW] into Deluxe 3–5 Nov, 2 adults";
  the action card holds the real name/email/phone, filled **locally** (from the vault or typed into the card).
- **Everything learned, logged and remembered is stored locally** (tables below); conversations are stored
  with tokens + the local vault, so history replays without re-sending data.
- **Optional fully-offline provider:** a 6th provider type `local` = any OpenAI-compatible server on the
  hotel's own network (e.g. Ollama / LM Studio on a spare PC: `http://192.168.x.x:11434/v1`). Selecting it
  means **nothing** leaves the building. Same adapter as `openai_compatible`; settings card warns it needs a
  capable machine and is slower. Cloud stays the default because shared hosting cannot run a model.
- Settings show a plain **"What leaves the hotel"** panel: a live sample of the last request as the provider
  saw it (tokens only), so the owner can check it.
- Privacy level setting: `Strict` (default — tokens + aggregates only) / `Standard` (tokens, more rows).
  There is no "send everything" level.

## Learning loop (it gets better every day — locally, without retraining a model)

A model's weights can't be retrained from PHP, so "its own little LLM that learns" is built as a **local
memory the model reads every turn**, grown automatically:
- `ai_memory` table (id, kind `fact|preference|lesson|playbook|glossary`, scope `hotel|user|page|tool`, text,
  source, confidence, uses, wins, losses, status `active|probation|retired`, created/updated). Retrieved per
  turn with a local keyword/BM25 search (reuse the `rh_kb_*` tokeniser/stemmer from
  `includes/guide-knowledge-base.php`) — no cloud embeddings. Top ~8 items go in the prompt.
- **Learns from staff:** corrections in chat ("no, breakfast starts 6:30") become `fact` candidates;
  thumbs up/down on every reply; per-user style becomes `preference`.
- **Learns from mistakes:** every failed tool call, refused/failed action, wrong figure flagged by a thumbs-down,
  or confirm that the user cancelled is written to `ai_incidents` with context. A nightly
  `scripts/ai-reflect.php` job (Fast model, tokenised data only) reviews the day's incidents and wins and writes
  `lesson` items ("check joined rooms before quoting Family Suite"), and promotes repeated successful action
  sequences to `playbook` items.
- **Self-scoring:** each memory item tracks wins/losses when used; items that keep failing are retired
  automatically; new items start on `probation` and go `active` after N wins. Owner can view, edit, pin or
  retire any item on an "Assistant memory" tab, and **Reset learning** (archives, never deletes).
- **Model upgrades:** the weekly refresh notices a newer model in the chosen family, runs the local eval set
  (below) against it, and switches the role over only if it scores ≥ the current one — logged, one-click undo.
- **Local eval set** (`includes/ai/evals.json` + `scripts/ai-eval.php`): ~40 fixed questions with known
  answers from local data (arrivals today, quote for X, "adjust stock" must refuse…). Used for model
  upgrades and to check learning hasn't made it worse; a drop auto-reverts the newest lessons.
- Guardrail: learned memory can only change **what the model knows and how it words things** — never its
  tool list, permissions, limits or the confirm step. Those live in code.

## Self-healing (it fixes its own running state; it never rewrites code)

- Provider down / timeout / rate-limited → retry once → Fallback model → next provider with a key; banner
  "running on backup model". Key rejected → pauses that provider and notifies `ai_settings` holders.
- Model retired by the provider (404 model-not-found) → auto-select the next-ranked model in the same role
  from the live list, re-run the eval set, notify.
- Tool keeps erroring (e.g. a column changed after an update) → circuit breaker disables that one tool for
  1 h, the assistant says it can't reach that data, an `ai_incidents` row is raised with the error.
- Daily `scripts/ai-health.php`: test call per configured provider, eval smoke (5 questions), tool self-test
  (each read tool with safe sample args), budget check; results on the settings page.
- **No self-patching of code.** Rewriting PHP on a live hotel server without review would break the
  "must not break anything" rule and the ship gate in CLAUDE.md. Instead, incidents that need a code fix are
  written up automatically (plain fault + evidence) to an "Assistant faults" list the owner can forward as a
  PATCH — the normal review/test/ship path then fixes it in both hotels.

## Kill switches (layered — any one stops it)

1. **Settings master switch** (`ai_enabled`) — panel, endpoints, crons all stop at once.
2. **Module switch** `ai_assistant` in Module Settings (works even if the AI Settings page has a problem).
3. **Per-person**: remove `ai_assistant_use` / `ai_assistant_actions` on the user-permissions screen.
4. **Separate switches** for *Actions* (chat stays, no action cards), *Learning* (memory frozen, read-only),
   *Cloud providers* (local provider only).
5. **Command-line switch** `php scripts/ai-kill.php on|off` for when the portal itself is unreachable.
6. **Automatic pause**: monthly budget reached, or ≥5 failed/cancelled actions in an hour, or the eval smoke
   score drops below threshold → assistant pauses itself and notifies `ai_settings` holders.
Switching off never deletes conversations, memory or keys; switching back on resumes where it was.

### Hard limits (non-negotiable, enforced in code, tested)
- No raw SQL tool, no file/shell tool, no settings/users/permissions/backup tools.
- **No writes** to stock, recipes, POS orders/tabs, payments, refunds, invoices, credit notes, EOD/shift close,
  rates/prices, deals. Those domains are **read-only** (and only if the user can already see them).
- The AI sees and does only what the logged-in user could see/do on screen: every tool checks
  `hasPermission()` + `moduleEnabled()` for the current user before running.
- Every write goes through the **existing** endpoint the screen button already uses (same CSRF, permission,
  locks, money rules, emails, timeline). No new write logic for bookings/money.
- Off by default after migration in both hotels → shipping it changes nothing until the owner switches it on.
- UI rule from memory: **no sparkle/wand/robot icons, no "smart"/"AI magic" labels.** Launcher is a plain
  "Assistant" button with `fa-comments`. Never claim MRA reporting/submission anywhere (system prompt says so).

## Architecture

```
admin page (any) ─ Assistant panel (admin/includes/ai-panel.php, outside #rh-admin-page so it survives SPA nav)
      │  POST JSON + CSRF
      ▼
admin/api/ai-chat.php ──► includes/ai/ai-agents.php  (orchestrator → specialists, tool loop)
                              ├─ ai-context.php   (hotel profile, user, prefs, page, live snapshot, masking)
                              ├─ ai-tools.php     (registry: read tools run; action tools only PROPOSE)
                              ├─ ai-providers.php (5 adapters → one normalised chat/tool-call shape)
                              └─ ai-core.php      (switches, settings, key encryption, budgets, logging)
action card "Confirm" ─► admin/api/ai-action.php (re-checks owner/expiry/permission, returns replay payload)
      └─ browser POSTs to the EXISTING endpoint (e.g. admin/bookings.php action=modify_booking) with the
         user's own session + CSRF, then reports the result back to ai-action.php → AI sees the outcome.
```

**Why the "replay" bridge:** `admin/bookings.php` (POST, lines ~120–152) already validates CSRF, blocks
deleted bookings, and enforces per-action permission via `rhBookingActionPermission()` before any of its
actions (`update_status`, `modify_booking`, `checkout`, `checkout_assess`, `resend_email`,
`send_invoice_email`, `extend_stay`, `assign_individual_room`, …). Replaying the same request from the
browser means the AI path is byte-for-byte the screen path — nothing to drift, nothing new to break.

## Phase 0 — Map (read-only, no code; append findings as §Map to this plan)

For every action and every read tool, record exact endpoint/helper, file:line, POST fields, permission key,
success/error response shape. Use `.claude/CODE_INDEX.md` + Grep + offset reads only.
- Bookings actions: `admin/bookings.php` handlers listed above; check-in path (status `checked-in` via
  `update_status`? or `admin/check-in*.php`); checkout = `checkout_assess` then `checkout` only when nothing
  is owed (`BALANCE_TOLERANCE`), else hand off to the existing settlement modal.
- Create booking: `admin/create-booking.php` `isset($_POST['create_booking'])` (line ~299), idempotency via
  `client_uuid` (`idem_normalize_uuid` / `idem_find_existing_booking`), success = redirect to
  `booking-details.php?id=N` (line ~1369). Full form field list (room lines, occupancy, children, status,
  individual room/auto-assign). **The only change allowed in this file:** an additive JSON response branch
  (`$_POST['response'] === 'json'` → `{success, booking_id, reference}` / `{success:false, error}`) at the
  redirect/error points. No logic changes. Payment section fields are never sent by the AI.
- Housekeeping/maintenance: `admin/housekeeping.php`, `admin/room-maintenance.php` AJAX actions.
- Messages: `resend_email` in bookings.php; WhatsApp senders in `includes/whatsapp-functions.php` — find the
  admin endpoint that calls `sendBookingConfirmedWhatsApp` etc.
- Read sources: `checkAvailability()` (`includes/booking-functions.php:484`), `checkRoomAvailability`,
  `ga_plan` (`includes/guest-allocation.php`), `rh_stay_totals` / `rh_child_rule` (`includes/pricing.php`),
  `admin/api/global-search.php`, `rh_kb_search()` + `rh_kb_allowed_guides()` (`includes/guide-knowledge-base.php`),
  `rh_kb_sys_help_for_page()` (`includes/guide-system-knowledge.php`), presence (`admin/includes/presence-lib.php`,
  table from migration 064), accounting/dashboard/occupancy report helpers (find the functions the existing
  report screens use — **AI figures must come from the same functions the screens use** so numbers match).

## Phase 1 — Foundation (ship; AI still off)

**Migration** `admin/migrations/070_ai_assistant.php` (additive only, idempotent `CREATE TABLE IF NOT EXISTS`,
follow `064_admin_presence.php` style; runs through `admin/migrations/migrate.php`):
- `ai_providers` (provider PK, api_key_enc, key_last4, status, last_test_at, last_test_ms, last_test_message)
- `ai_models` (id, provider, model_id, label, released_at, context_tokens, price_in_per_mtok,
  price_out_per_mtok, capability_rank, source `catalog|live`, is_selected, role `primary|fast|fallback|extra`, sort)
- `ai_conversations` (id, user_id, title, page_path, created_at, updated_at, archived)
- `ai_messages` (id, conversation_id, role, agent, content, tool_name, tool_args_json, tool_result_json
  (truncated 8 KB), provider, model_id, tokens_in, tokens_out, cost_usd, latency_ms, created_at)
- `ai_actions` (id, conversation_id, user_id, action_key, params_json, preview_json, status
  `proposed|confirmed|executed|failed|cancelled|expired`, client_uuid, result_json, expires_at, executed_at)
- `ai_user_prefs` (user_id PK, prefs_json: tone, reply length, language, nickname, briefing on/off)
- `ai_usage_daily` (day, user_id, provider, model_id, requests, tokens_in, tokens_out, cost_usd; unique key)
- Seed `enabled_modules` row `ai_assistant` (is_enabled = 0) and settings (group `ai`): `ai_enabled=0`,
  `ai_user_daily_token_cap`, `ai_monthly_budget_usd`, `ai_rate_limit_per_10min=30`, `ai_retention_days=0` (keep).

**Permissions** — add to `getAllPermissions()` / groups in `admin/includes/permissions.php` (+ page map in
`getPermissionForPage`, module map in `getModuleForPage`/`getPermissionsForModule` for `ai_assistant`):
`ai_assistant_use` (chat), `ai_assistant_actions` (may confirm action cards), `ai_settings` (keys/models/
budget), `ai_usage_view`. Defaults (`getDefaultPermissionsForRole`): admin = all four; manager = use + actions
+ usage; everyone else none — owner grants per person on the existing user-permissions screen.
`ASSUMPTION:` these defaults; logged in BUILD_PLAN.md.

**Kill switch** = module `ai_assistant` off (Module Settings) **or** `ai_enabled=0`. When off: no panel markup,
both endpoints return 404 JSON, crons exit, nav item hidden. Turning off never deletes data.

**Settings page** `admin/ai-settings.php` (gated `ai_settings`; nav under System/Integrations, same pattern as
`admin/facebook-settings.php`):
1. Master switch + plain status line ("On — using Gemini, 3 people have access").
2. **Providers** — five cards (Anthropic, OpenAI, Google, xAI, DeepSeek): paste key (never echoed back; show
   `••••last4`), **Test connection** (list models + a 5-token "Reply OK" call; shows ms and plain result),
   Remove key. DeepSeek card notes China hosting. Keys encrypted with a new `rh_ai_encrypt/decrypt` that
   mirrors `facebookEncryptSecret()` (`includes/facebook-functions.php:42`, AES-256-CBC, salt from
   `APP_ENCRYPTION_SALT`, context `|ai`, prefix `ai1:`). Never logged, never sent to the browser.
3. **Models** — after a successful test the live list loads; pick **up to 5** for the chosen provider and give
   each a role: Primary (thinking), Fast (routing/short replies), Fallback (if primary fails), Extra.
   Shows price per million tokens and "newest" badge. **Check for newer models** button.
4. Budgets & limits (daily per-person token cap, monthly cost cap, rate limit), retention.
5. Who has access (read-only list of users holding `ai_assistant_use`, link to user permissions).
6. Usage table (by person/day/model, est. cost) — gated `ai_usage_view`.
Every column/card gets a plain helper line (memory rule).

**Model discovery ("latest models from the top 5 providers")** — `includes/ai/ai-catalog.php`:
- Ships `includes/ai/model-catalog.json` (per provider: model ids, label, rank, context, prices, release date)
  so the page is useful before any key exists. Executor fills it from the providers' own docs at build time
  (load the `claude-api` skill for Anthropic ids/prices; never guess ids).
- Source of truth once a key exists: each provider's **official model-list API** (not page scraping — fragile
  and blocked by the providers): Anthropic `GET /v1/models`; OpenAI `GET /v1/models`; Gemini
  `GET /v1beta/models` filtered to `generateContent`; xAI `GET /v1/models` (+ `/v1/language-models` for
  prices); DeepSeek `GET /models`. Filter out embeddings/tts/image/audio/moderation/realtime models.
- Ranking: catalog rank when known; unknown new ids ranked by family/version parsing + created date, marked
  "new — set price" (price fields editable). Top 5 are pre-ticked as suggestions.
- `scripts/ai-model-refresh.php` weekly cron (skips when off) refreshes live lists for keys present.

## Phase 2 — Chat core + read tools (ship)

**Providers** `includes/ai/ai-providers.php` — curl only (no Composer), CA via `facebookCaBundle()`,
60 s timeout, one normalised call `rh_ai_chat(array $req): array{text, tool_calls[], usage, stop, error}`:
- `anthropic` — `POST https://api.anthropic.com/v1/messages`, `x-api-key`, `anthropic-version`, `tools`
  (`input_schema`), `tool_use`/`tool_result` blocks, `cache_control` on system + tools (prompt caching).
- `openai_compatible` — one adapter, three base URLs: OpenAI `https://api.openai.com/v1`, xAI
  `https://api.x.ai/v1`, DeepSeek `https://api.deepseek.com/v1`; Chat Completions `tools`/`tool_calls`;
  fall back to OpenAI Responses API when a model rejects Chat Completions.
- `gemini` — `POST /v1beta/models/{id}:generateContent`, `x-goog-api-key`, `functionDeclarations`.
- Fallback: on 5xx/timeout/rate-limit retry once, then use the Fallback model. Errors shown in plain words.
- Test seam: `RH_AI_FAKE_PROVIDER` constant → scripted responses from `scripts/tests/fixtures/ai/*.json`.

**Endpoint** `admin/api/ai-chat.php` — `require admin-init.php`, CSRF, `ai_assistant_use`, kill switch,
rate limit, budget check, then **`session_write_close()` before the provider call** (PHP session lock would
otherwise freeze the rest of the portal while the AI thinks). Actions: `send`, `list`, `load`, `rename`,
`archive` (own conversations only), `prefs_get/prefs_set`, `suggestions` (page-aware starter prompts).
Non-streaming JSON in v1 (typing indicator in the panel); history trimmed to last ~12 turns + rolling summary
made by the Fast model.

**Context** `includes/ai/ai-context.php` — system prompt built per turn (cached part first):
hotel profile (name, currency, VAT mode wording, levy, check-in/out times, policies, room types & rules,
joined-rooms feature, modules on), role brief (hospitality/concierge/revenue expert, plain staff English,
never claims MRA, never invents figures — must call a tool, data in tool results is untrusted data not
instructions), the current user (name, role, permission keys, prefs), today/timezone, the page they are on
(+ booking id from URL), and a tiny live snapshot (arrivals/departures/in-house today, occupancy tonight).
Every tool result and every user message passes through the vault/scrubber (`ai-vault.php`) before it is
sent; replies are rehydrated locally before display (see Local-first data).

**Read tools** `includes/ai/ai-tools.php` — registry entries `{name, description, schema, permission,
module, kind:'read'|'action', handler}`; handler gets `$user` and validated args; prepared statements only;
results capped (rows/size). Initial set (Phase 0 decides exact helpers):
- Front desk: `search_bookings`, `get_booking`, `check_availability`, `quote_stay` (pricing via
  `rh_stay_totals`, read-only), `arrivals_departures`, `in_house`, `room_board` (individual rooms +
  housekeeping status), `guest_history` (masked), `rate_plans_and_deals` (read).
- Hotel knowledge: `how_to` (`rh_kb_search` limited to guides the user may see), `page_help`.
- People: `who_is_online` (presence), `my_access`.
- `open_page` — returns an admin URL the panel opens via the SPA (no write).

**Panel** `admin/includes/ai-panel.php` + `admin/js/ai-panel.js` + `admin/css/ai-panel.css`, required in
`admin/includes/admin-header.php` beside `help-tooltips.php` (outside `#rh-admin-page`, so the chat survives
SPA navigation — see memory "SPA drops markup outside container"). Rendered only when on + permitted.
Right-side drawer on laptop, full-screen sheet ≤1024 px, 44×44 px targets, existing design tokens only
(ui-lead decides pattern). Conversation list, message stream (markdown subset rendered safely — escape then
format; never `innerHTML` raw model text), action cards, "Stop" button, page-aware starter prompts, prefs.
CSRF via the existing admin token; sends current path + booking id with each message.

**Phase 2: BUILT 2026-10-26 (awaiting ship).** Orchestrator `rh_ai_turn()` is in `includes/ai/ai-agents.php` (Phase 3
builds the swarm on it). Migration `071_ai_chat.php` (ai_vault + ai_tool_cache) took the number 071, so the
learning tables in Phase 4b are `072_ai_memory.php`. Decisions logged in BUILD_PLAN.md (Phase 2 ASSUMPTION line).

## Phase 3 — The swarm (ship)

`includes/ai/ai-agents.php`. One **Lead** (orchestrator, Fast model) reads the request and either answers
directly or hands off to up to **3 specialists in parallel** (`curl_multi`), then merges their findings with
the Primary model. Specialists = a prompt + a tool subset; all share the same permission-checked registry.

| Specialist | Knows / does | Tools |
|---|---|---|
| Front Desk | reservations, availability, check-in/out, rooms, guests | front-desk reads + booking/housekeeping action proposals |
| Revenue & Sales | occupancy, ADR, RevPAR, pace vs last year, pickup, lead time, cancellations, unsold nights, source mix, events/conference pipeline, deal ideas | read-only analytics (same functions as report screens); **recommends**, never changes prices/deals |
| Concierge | guest experience, local area, itineraries, upsell ideas, drafting guest replies in the hotel's voice | hotel profile, booking reads, message drafts → send proposal |
| Operations (read-only) | housekeeping queue, maintenance, F&B top sellers, low stock alerts, gym expiries, EOD status | read-only reports; **no stock/POS/payment writes exist in its tool list** |
| System Guide | "how do I…" in this portal | `how_to`, `page_help`, `open_page` |

Caps per user turn: ≤3 specialists, ≤8 tool rounds, ≤N tokens (setting); every message row records agent,
model, tokens and cost into `ai_messages` + `ai_usage_daily`. Optional "Morning briefing" prompt (pref) =
Lead + Revenue + Front Desk summary on first open of the day.
**Superseded in scope by "Owner direction" (top of plan):** specialists follow the department list there and
the briefing is role-shaped ("full tea": money leaks for owner, time leaks / missed check-outs for reception).
Briefing data is gathered by deterministic read tools first (zero tokens) and only the ranked findings go to
the model to explain.

**Phase 3: BUILT 2026-10-26 (awaiting ship).** Leads + consult_team in `includes/ai/ai-agents.php`; department reads and
the zero-token briefing finders in `includes/ai/ai-insights.php` (registered in `ai-tools.php`); `rh_ai_chat_multi()`
(curl_multi) in `ai-providers.php`; reply-check (`rh_ai_reply_unsupported`) enforces "Never guesses"; status line
replaces the typing dots. Decisions in BUILD_PLAN.md (Phase 3 ASSUMPTION line). No migration (072 stays for Phase 4b).

## Phase 4 — Actions with confirm (ship; accounting lead reviews cancel/checkout)

`includes/ai/ai-actions.php` registry: `{key, permission (same as the screen button, e.g. via
rhBookingActionPermission), preview(args) (read-only, server-computed), replay(args) → {url, fields}}`.
Action tools only insert an `ai_actions` row (`proposed`, 15-min expiry, fresh `client_uuid`) and return the
card; **they never write business data.**

| Action | Existing path replayed | Notes |
|---|---|---|
| Create booking | `admin/create-booking.php` (`create_booking`, `response=json`, `client_uuid`) | one room type, 1+ rooms, guests/children, status pending/tentative/confirmed; price shown from `rh_stay_totals`; no payment fields. Group/multi-type or payment → "Open in Create Booking" pre-filled hand-off |
| Edit booking | `bookings.php` `modify_booking` / `extend_stay` / `admin_update_checkout_date` / room change | preview shows old → new and new total |
| Cancel booking | `bookings.php` `update_status=cancelled` | preview shows what existing cancel rules retain; refunds stay with staff |
| Check-in | existing check-in action (Phase 0) | room assignment via `assign_individual_room` if needed |
| Check-out | `checkout_assess` → `checkout` only if nothing owed | owed → button opens the existing settlement modal |
| Housekeeping / maintenance | `housekeeping.php` / `room-maintenance.php` actions | status change, new job |
| Send email / WhatsApp | `bookings.php` `resend_email`, `send_invoice_email`, WhatsApp endpoint | preview shows template + recipient (masked) |

`admin/api/ai-action.php`: `confirm` (owner = current user, status `proposed`, not expired, user still has
`ai_assistant_actions` + the action's own permission, re-runs preview and refuses if price/availability
changed → new card) → returns replay payload; `report` (client posts the target endpoint's JSON result →
`executed`/`failed`, `logActivity(... 'via Assistant')`, timeline note where the screen adds one) ;
`cancel`. Double-tap safe: `client_uuid` idempotency on create; confirm is single-use (row lock).
Prompt-injection guard: guest-written text (special requests, reviews, enquiries) reaches the model only as
quoted data; nothing it says can execute without a human tap.

## Phase 4b — Learning loop + self-healing (ship)

**Owner add (2026-10-26):** model refresh must not depend on cron. On the first admin page load of the day by ANY signed-in account (whether or not they hold Assistant permissions), if the Assistant is on, a provider key exists and the last refresh is over 7 days old (or never), refresh the live model lists without slowing the page (after the response is sent / a background request), single-flight via a lock so two logins don't both run it; failures logged quietly, never shown to non-settings users. The weekly cron stays optional.
Migration `071_ai_memory.php` (`ai_memory`, `ai_incidents`, `ai_feedback`, `ai_vault`, `ai_eval_runs` —
additive; `ai_vault` may instead land in 070 since Phase 2 needs it). Build `ai-vault.php` in Phase 2 (local-
first is required from the first chat), memory retrieval + feedback in this phase, then `ai-reflect.php`,
`ai-health.php`, `ai-eval.php`, `ai-kill.php`, circuit breaker, auto-pause, model auto-upgrade with eval gate,
"Assistant memory" and "Assistant faults" tabs on the settings page.

## Phase 4c — Owner extras (ship, in this order)

1. PIN on risky confirms + idle lock/logout wipe + audit hash chain (security first; `dept-patch-lead` review).
2. Proactive alerts + daily brief; reports on request.
3. Draft replies; upsell finder; review insights.
4. Lost-demand logging (guest-site touch, separate commit), then rate suggestions on top of it.
5. Weekly digest email.
Migration `072_ai_extras.php`: `ai_alerts`, `demand_log`, hash columns on `ai_actions`/request log, `session_id`
on `ai_vault` (additive).

## Phase 5 — Polish (ship)

Usage dashboard, monthly budget warning (in-app notice to `ai_settings` holders at 80 %/100 %; at 100 % chat
pauses with a plain message), model refresh cron, optional retention purge script (`DELETE … WHERE
created_at < ?` on AI tables only, runs only when `ai_retention_days > 0`), guides via `guides-kb-lead`
(new `docs/guides/` page "Using the Assistant" + "Setting up the Assistant"), KB synonyms.

## Files

New: `admin/migrations/070_ai_assistant.php`, `admin/ai-settings.php`, `admin/api/ai-chat.php`,
`admin/api/ai-action.php`, `admin/includes/ai-panel.php`, `admin/js/ai-panel.js`, `admin/css/ai-panel.css`,
`includes/ai/{ai-core,ai-providers,ai-catalog,ai-context,ai-vault,ai-memory,ai-tools,ai-agents,ai-actions}.php`,
`includes/ai/model-catalog.json`, `includes/ai/evals.json`, `admin/migrations/071_ai_memory.php`,
`scripts/{ai-model-refresh,ai-reflect,ai-health,ai-eval,ai-kill}.php`,
`scripts/tests/ai_assistant_test.php`, `scripts/tests/fixtures/ai/*.json`, guide pages.
Edited (additive only): `admin/includes/permissions.php`, `admin/includes/admin-header.php` (one require),
`admin/logout.php` (one vault-purge line), `admin/create-booking.php` (JSON response branch only; no
`config/database.php` edit — see §Map "Module check fails OPEN"),
nav entry for AI Settings. Same files in Liwonde (`C:\Users\john-paul.chirwa\OneDrive\MSP\Liwonde_Sun_Hotel_2026`).

## Team & gates (owner 2026-10-26: "this is only going to be handled by the AI lead")

**`dept-ai-lead` (Opus 5.5, `.claude/agents/dept-ai-lead.md`) owns this feature alone**: builds every phase,
reviews its own money/booking/security touch points against the other departments' rules (it reads their
invariants; it does not hand work to them), owns the Assistant's guide pages, and runs the completion
guardrail below. No other department lead builds or edits AI files. Kept from CLAUDE.md because they are the
ship gate, not ownership: `qa-auditor` diff review, `php -l`, own test file, headless browser check.
`ASSUMPTION:` the AI lead may still *ask* (read-only) `dept-accounting-lead` to check a figure if a number on
screen and in chat disagree; it never hands them the build. One commit per phase, both hotels, pushed to `main`.

## Continuous train & test + completion guardrail (owner 2026-10-26)

Owner: "It will constantly train and test data. It will have a guardrail where it eventually says this
feature is now complete and there is nothing else to patch."
- **Constant training** (no model retraining — see Learning loop): every confirmed action, thumbs up/down,
  correction and incident feeds `ai_memory` daily; nightly `ai-reflect.php` turns them into lessons/playbooks.
- **Constant testing:** nightly full eval (`ai-eval.php`, all of `evals.json`) + the 15-min alerts job's tool
  self-test; every incident that gets fixed adds a **regression question** to the eval set (`source=incident`),
  so the test set grows with real mistakes. All runs use tokenised data only and log to `ai_eval_runs`.
- **Completion guardrail** (`ai_feature_state` setting: `learning` → `complete`, shown on AI Settings with date
  + evidence): the Assistant declares **"This feature is complete — there is nothing else to patch"** only when
  ALL hold for **14 consecutive daily runs**: full eval pass rate ≥ 95 % and no question that passed before now
  fails; 0 open "Assistant faults"; 0 new incident kinds; every read tool's self-test green; kill-switch and
  hard-limit tests green; no budget/auto-pause events. Thresholds are settings (`ai_complete_days=14`,
  `ai_complete_eval_pct=95`), `ASSUMPTION:` these defaults.
- **When complete:** it stops writing new lessons/playbooks and fault write-ups (memory goes read-only except
  per-user preferences and owner-entered facts), keeps nightly evals running as a watchdog, and the AI lead
  treats the feature as closed — no further patches or features unless the owner asks.
- **Reopen only on evidence:** an eval regression, a new incident kind or a failed self-test flips the state
  back to `learning`, writes one "Assistant fault" with the evidence and notifies `ai_settings` holders. The
  owner can also mark it complete/reopen by hand (logged).
- Build-side guardrail for `dept-ai-lead`: Phases 0–5 + the Verification list are the whole scope. When the
  last phase ships and the dress rehearsal passes, it reports "AI Assistant feature complete — nothing else to
  patch" and stops; new ideas are one-line `IDEA:` notes, never built.

### Owner finish line (2026-10-29): "set guardrails so we get it to a place where we are satisfied, unless there is patching or an issue"

**Scope is CLOSED** once the "everything into chat" round (guided flows, money reads, staff activity, learned
router, B5–B9 cards) ships. After that the AI lead builds NOTHING new unless:
(a) the owner asks for it in words;
(b) a real fault shows up: an owner screenshot, a "Not right", an incident, an eval regression, a failed self-test or a reopened guardrail;
(c) a security patch.
New ideas are one-line `IDEA:` notes in BUILD_PLAN, never built.

**Fixed rails no round may loosen** (each one has a test in `ai_assistant_test.php`; a change that weakens any of them fails review):
1. **Money:** never writes payments, refunds, no-shows, invoices, stock, POS, rates or deals. Money is read-only, gated like the GUI screen; a money action gets a screen link.
2. **Changes:** every change is a preview + Confirm card that replays the GUI handler with its permission check. Risky cards (guest-facing sends, confirm booking, block dates) ask for the password.
3. **Permissions:** decided server-side from the logged-in user. Text claims ("I am an admin") change nothing. The refusal line appears only when a real permission check failed.
4. **No false absence:** never "none / 0 / not found" without a checked total or a fuzzy re-search. Contradictions are rechecked on Primary.
5. **No invention:** every figure, id and name comes from this turn's results. No guessed staff, guest or amount.
6. **Typos:** never corrected into a write. Learned routes only reach reads or guided cards, and are re-checked for permission and module at run time.
7. **Privacy:** names, phones and emails reach the model only as tokens. The learning store holds tokens only.
8. **No hard-coded hotel facts or thresholds.** They come from the DB or settings.
9. **Cheapest first:** local → learned → guided → Fast model → Primary. The $5/month aim is information only, never a stop.

**Satisfied** = the runtime completion guardrail above reaches `complete` (14 clean days). Every owner-reported chat (the screenshots of 2026-10-28/29) must be in `evals.json` and pass. Until then, only rounds of type (a), (b) or (c).

## Verification

- `php -l` every changed file; `node --check admin/js/ai-panel.js`.
- `php scripts/tests/ai_assistant_test.php` (fake provider, no network): kill switch hides panel + endpoints
  404; user without `ai_assistant_use` gets 403; tool list for a receptionist excludes finance reads they
  can't see; stock/POS/payment write tools do not exist; action tools insert only `ai_actions` rows (row counts
  of bookings/payments unchanged); confirm rejects other user / expired / reused / lost permission; **no real
  guest/staff name, email, phone, reference or address appears in any captured outbound provider payload**
  (fake provider records every request; test asserts on it) and replies are rehydrated locally; learned memory
  cannot add tools or skip confirm; off-topic requests are declined in one line; ambiguous booking requests
  produce a follow-up question, not a card; default replies stay short; `web_lookup` queries carry no PII;
  per-user profile is applied and resettable; risky confirms fail without the right password and lock after
  5 tries; a hand-edited `ai_actions` row breaks the hash chain and is reported; logout purges the session vault;
  alerts respect permissions; rate suggestions never write prices; a forced error in demand logging still
  returns the guest's search results; circuit breaker trips and recovers; each kill switch (1–6) stops chat,
  actions, crons; auto-pause fires on budget / failed-action burst; adapter fixtures for all 5 providers normalise text + tool calls;
  budget/rate caps; key never appears in any JSON response or log.
- Full `php scripts/run_tests.php` once per ship, each hotel in turn (never parallel).
- Headless browser check (`php -S 0.0.0.0:8000` + `scripts/tests/browser_check.mjs`): AI Settings page, panel
  open/close, survives SPA navigation, phone/tablet widths.
- **Liwonde dress rehearsal (live keys, owner present):** enable, paste a key, Test connection, pick 5 models,
  ask "arrivals today?" (matches Bookings screen), "how can I lift next month's occupancy?", create a booking
  for test guest `johnpaulchirwa@gmail.com` → Confirm → booking identical to a manual one in
  `admin/bookings.php` (price, VAT, levy, timeline) → edit dates → cancel; ask it to "adjust stock" → it
  declines and points to the Stock screen; switch module off → panel gone. Then repeat the switch-on at
  Rosalyn's only when the owner says so.

## §Map — Phase 0 findings (2026-10-26, Rosalyn's line numbers; re-grep Liwonde before porting)

Line numbers drift: CODE_INDEX was stale for `permissions.php` (2,207 lines now) and `bookings.php` (9,034).
Every builder re-greps the function/action name before an offset read. Liwonde's `.claude/` is an older copy
(no patch/pwa/backup leads); orchestration docs live in Rosalyn's, code ships to both.

### Shared plumbing
- **CSRF:** `generateCsrfToken()` / `validateCsrfToken($t)` in `config/security.php:178/191`; admin pages get
  `$csrf_token` from `admin/admin-init.php:72`; JS reads `window._rhCsrf` or `meta[name="csrf-token"]`
  (`bookings.php:3343`). Field name is always `csrf_token`.
- **AJAX = JSON:** screens decide JSON by header `X-Requested-With: XMLHttpRequest` (`bookings.php:29`
  `isAjaxRequest()`, `housekeeping.php:22`, `room-maintenance.php:25` `$isAjax`). The replay bridge must send
  that header. Shape everywhere: `{success: bool, message: string, ...}`; bookings.php answers unhandled/early
  returns with JSON too (`bookings.php:2603–2617`); errors are HTTP 200 + `success:false`.
- **Permissions:** `hasPermission(int $uid, string $key)` `permissions.php:1171` (unknown key → false + log);
  `getAllPermissions()` :310 (entries `'key' => ['label','page',…]`), `getPermissionGroups()` :1113,
  `getDefaultPermissionsForRole()` :1149, `getPermissionForPage()` :1486, `getModuleForPage()` :1589,
  `rh_module_key_enabled()` :1707, `getPermissionsForModule()` :1773, `logActivity($uid,$action,$details)` :1413,
  `rhBookingActionPermission($action,$status)` :2189 (checkin→`checkin_guest`; checkout/assess/settle→
  `checkout_guest`; cancel/noshow→`cancel_booking`; payment→`payment_add`; adjust_dates/confirm/convert/folio
  charges→`edit_booking`; anything else → null = page permission only).
- **Page/module gate:** `admin-init.php:78–115` checks page permission, then module (admins bypass module).
- **⚠ Module check fails OPEN:** `moduleEnabled()` (`config/database.php:1225`) returns **true** for unknown keys
  and when the table is missing, and its seed list (:1250–1269) inserts rows with `is_enabled=1`.
  → **Do NOT add `ai_assistant` to that seed list** (Files list said "module seed list only" — dropped). Migration
  070 does `INSERT IGNORE … is_enabled=0`. Kill-switch helper `rh_ai_enabled()` must be **fail-closed**: true only
  when setting `ai_enabled === '1'` AND an `enabled_modules` row `ai_assistant` exists with `is_enabled=1`
  (direct query, not `moduleEnabled()`) AND no auto-pause flag. No edit to `config/database.php` at all.
- **Settings:** `getSetting($key,$default)` `config/database.php:1057`. `BALANCE_TOLERANCE` (0.01) :69.
- **Encryption pattern:** `facebookEncryptSecret()/facebookDecryptSecret()` `includes/facebook-functions.php:42/61`;
  salt `APP_ENCRYPTION_SALT` falls back to `'DEFAULT_SALT_CHANGE_IN_ENV'` (:47) — AI Settings must warn and
  refuse to store a key while the fallback is in use. `facebookCaBundle()` :125 for curl CA.
- **Email:** `sendEmail($to,$toName,$subject,$html,$text='')` `config/email.php:127`.
- **Sessions:** `includes/admin-session.php:49` `rh_admin_session_start()` (8 h idle; regenerates the id on
  expiry :61). Logout `admin/logout.php` (presence clear :26–28, `session_destroy()` :41). Vault purge = one
  additive line in `admin/logout.php` before :41 + nightly sweep of `ai_vault` rows whose session is gone
  (expiry changes the id, so a hook in admin-session.php is not reliable).
- **Migrations:** latest `069_…`; new `070_ai_assistant.php`. Format = return array with `'check'` and `'up'`
  closures, `CREATE TABLE IF NOT EXISTS`, InnoDB utf8mb4 + COMMENT (`064_admin_presence.php:20–37`).
- **Panel slot:** `admin/includes/admin-header.php:473–475` (help FAB `require help-tooltips.php`, outside
  `#rh-admin-page`, which opens at :482). Panel `require` goes right after :475.
- **Presence:** table `admin_presence` (user_id PK, last_seen_at, current_page, session_started_at, ip,
  user_agent_short; migrations 064 + 065). `admin/includes/presence-lib.php`: `rh_presence_rows($pdo,$withIp)`
  :347, `rh_presence_can_view($role)` :30, `rh_presence_page_title()` :67. `who_is_online` =
  `rh_presence_rows($pdo,false)` gated by `rh_presence_can_view`, names tokenised.
- **PIN:** no staff PIN helper exists. Risky confirms use the **account password**: `password_verify($pw,
  admin_users.password_hash)` — same pattern as the POS manager override `admin/pos.php:2001`. Lockout counter
  is new (small `ai_confirm_fails` table or column in `ai_user_prefs`).

### Actions (replay to existing endpoints with the user's session + `csrf_token` + XHR header)
| Action | Endpoint · action | POST fields | Permission (server) | Result / notes |
|---|---|---|---|---|
| Check-in | `admin/bookings.php` · `update_status` (:661) | `id`, `status=checked-in`, `checkin_note`?, `confirm_checkin_room_not_ready`? | `checkin_guest` | `validateCheckIn()` (`database.php:2757`) + `evaluateCheckInRoomReady()` (:2856). Room not ready → `{success:false, message, needs_confirm_room}` → card asks a **second** confirm, never auto-sent |
| Assign room | `bookings.php` · `assign_individual_room` (:556) | `booking_id`, `individual_room_id`, `allow_child_policy_override`?, `child_policy_override_note`? | page `bookings` | `{success,message}`; joined rooms handled inside (~:590–610) |
| Cancel | `bookings.php` · `update_status` | `id`, `status=cancelled`, `cancellation_reason`, `scope=single\|group` | `cancel_booking` | `cancelRoomBookingSettled()` (`database.php:9685`) / `rh_cancel_booking_group()` (:9969). **No read-only cancel preview exists**: the screen shows only `getCancellationRefundModeLabel()` (:9636). AI preview = that label + paid/owed from `getBookingFolioSummary($id)` (:9271) + group size via `rh_booking_group_ids()` (:9957); no computed refund figure. Card asks single vs whole group like the screen |
| Check-out | `bookings.php` · `checkout_assess` (:1518) then `checkout` (:1455) | assess `id`; checkout `id` (never `confirm_checkout_with_balance`) | `checkout_guest` | assess → `{success, balance_due, can_override, settlement:{needed,type}}`. AI proposes checkout only when `settlement.needed=false` and `balance_due <= BALANCE_TOLERANCE`; else card = "Open checkout on Bookings" (settlement modal). Mirrors JS `checkoutBooking()` (~:5068) |
| Quick edit | `bookings.php` · `modify_booking` (:1000) | `id`, any of `guest_name, guest_email, guest_phone, guest_country, check_in_date, check_out_date, number_of_guests, adult_guests, child_guests, special_requests, individual_room_id`, `note` | `edit_booking` **and** `quick_modify_booking` (inline :1004) | refuses closed statuses, child-count change, in-house date change, past check-in (~:1030–1080). Anything else → hand-off to Edit Booking |
| Extend stay | `bookings.php` · `extend_stay` (:2335) | `booking_id`, `new_checkout` | page `bookings` | checked-in only; `conflicts` on clash |
| Change checkout date | `bookings.php` · `admin_update_checkout_date` (:2454) | `booking_id`, `new_checkout`, `reason` | `edit_booking` + admin-only check (~:2461) | |
| Room type change | `bookings.php` · `room_change_options` (:2317 read) / `upgrade_room_type` (:2554) | `booking_id`, `new_room_id`, `pricing`, `reason_code`, `note`, `send_email` | `edit_booking` | preview via `rh_room_change_quote()` (`includes/booking-room-change.php:100`) |
| Resend email | `bookings.php` · `resend_email` (:192) | `booking_id`, `email_type` (booking_received, booking_confirmed, tentative_confirmed, tentative_converted, booking_cancelled, booking_reminder, invoice), `cc_emails`? | page `bookings` | type/status rules ~:231–290 |
| Send invoice | `bookings.php` · `send_invoice_email` (:1287) | `booking_id` | page `bookings` | sender result JSON |
| WhatsApp | `admin/booking-details.php` is the only admin page calling the booking senders (`includes/whatsapp-functions.php`: `sendBookingConfirmedWhatsApp` :711, `sendBookingInvoiceWhatsApp` :825, `sendRoomQuotationWhatsApp` :898) | Phase 4 greps its POST branch | — | if that branch cannot answer JSON → "Open booking" hand-off in v1 |
| Housekeeping job | `admin/housekeeping.php` · `add_assignment` (:726), `update_assignment` (:879), `mark_started` (:1166), `mark_complete` (:1178) | add: `individual_room_id, assignment_type, priority, due_date, scheduled_time, assigned_to, estimated_duration, notes, status` (recurring fields never sent) | `housekeeping` (page gate :15) | `{success:true,message}` (:875, :1045) |
| Maintenance job | `admin/room-maintenance.php` · `add_schedule` (:538), `update_schedule` (:741), `mark_started` (:1148), `mark_complete` (:1173) | add: `individual_room_id, title, description, maintenance_type, priority, due_date, start_date, end_date, block_room, linked_booking_id, assigned_to, estimated_duration, notes, status` | `room_maintenance` (:18) | `{success:true,message}` (:737, :942). `block_room=1` takes a room off sale → shown in red on the card |
| Room status | `admin/individual-rooms.php` · `update_status` (:442) | Phase 4 greps | page perm | clean/dirty/inspecting of one room |
| Create booking | `admin/create-booking.php` · `isset($_POST['create_booking'])` (:299) | below | page `create_booking` (`permissions.php` ~:337) | redirect only today → JSON branch |
| (not used) | `admin/booking-details.php` `booking_action` (:81; cases checkin :485, checkout :541, cancel :697) | | | bookings.php is the single replay target for status changes |

**Create booking detail.** CSRF :301; idempotency :306–324 (`idem_normalize_uuid` / `idem_find_existing_booking`
`includes/idempotency.php:29/45`, per-uuid `GET_LOCK`). Fields read: `guest_name, guest_email, guest_phone,
guest_country, guest_address, check_in_date, check_out_date, number_of_guests, child_guests, occupancy_type,
booking_status, room_id, rooms_quantity, room_line_room_id[], room_line_qty[], room_line_occupancy[],
room_line_guest_name[], individual_room_id, auto_assign_room, special_requests, admin_notes, group_reference,
send_email, send_quotation, quotation_valid_days, quotation_notes, client_uuid, create_booking`. **AI never
sends:** `payment_received, amount_collected, payment_method, price_override, room_line_override,
apply_credit_note_ids, apply_credit_override, apply_credit_override_reason, confirm_overpay`. The JSON branch
(`($_POST['response'] ?? '') === 'json'`, additive) must cover **four** exits: (1) GET_LOCK busy 409 HTML
(:310–318) → `{success:false,error:'still_saving'}`; (2) duplicate-uuid redirect (:320–324) →
`{success:true,booking_id,reference,duplicate:true}`; (3) success redirect (:1369) →
`{success:true,booking_id:$primary_id,reference:$primary_ref}`; (4) catch (:1371–1377) →
`{success:false,error:$error}` before the page renders. No other line changes.

### Read sources
- **Availability:** `checkRoomAvailability($roomId,$ci,$co,$excludeBookingId=null,$childGuests=0,$childRoomsNeeded=1)`
  `config/database.php:4806` — what booking.php (:458), create-booking (:141, :732) and `api/availability.php`
  (:128) use. `checkAvailability()` (`includes/booking-functions.php:484`) is an older wrapper — not used.
  Party fit: `ga_plan($room,$totalGuests,$childGuests)` `includes/guest-allocation.php:456`.
- **Quote — no single helper exists.** Server price is inline in `create-booking.php:600–660`: occupancy column
  (single/double/triple; joined-room combo override) → `applyDynamicPricing($pdo,$roomId,$ci,$co,$nights,$price)`
  (`includes/pricing.php:39`: final_price, rate_plan_id/label, discount_amount) → `× nights` +
  `rh_child_supplement($childRule,$seatedChildren,$nights,$price)` (rule `rh_child_rule()` `pricing.php:520`) →
  `rh_stay_totals($amount,'price')` (`pricing.php:169`: net, vat, levy, total_with_vat, rates). `quote_stay`
  calls the same helpers in the same order (read-only). Test: its total equals a real create-booking POST inside
  a rolled-back transaction. After each AI booking, `ai-action.php report` compares saved `total_with_vat` to the
  card and raises an incident on a difference > `BALANCE_TOLERANCE`. `api/availability.php` pricing ignores
  occupancy/children → not used.
- **Booking money:** `getBookingFolioSummary($id)` `config/database.php:9271`.
- **Search:** `admin/api/global-search.php` (GET `q`, `api-init.php`, `{success,q,groups}`; KB at :330) —
  `search_bookings` reuses its SQL, not HTTP.
- **KB:** `rh_kb_search($q,$limit=10,$state=null)` `includes/guide-knowledge-base.php:848`,
  `rh_kb_allowed_guides($state)` :759, tokeniser/stemmer `rh_kb_query_terms` / `rh_kb_stem` (reused for
  `ai_memory` BM25); `rh_kb_sys_help_for_page($page,$index,$limit)` `includes/guide-system-knowledge.php:390`.
- **KPIs:** shared `rh_occupancy_pct($sold,$sellable)` `config/database.php:3904`, `rh_adr($roomRevInclVat,
  $roomsSold)` :3913, `rh_cancellations_between()` :4108; the SQL around them is inline in `admin/reports.php`
  (~500–700) and `admin/end-of-day-report.php` (~140–230). Analytics tools use the export functions in
  `api/reports-export.php` (`exportOverviewReport` :138, `exportRevenueReport` :261, `exportBookingsReport` :519,
  `exportOccupancyReport` :583 …) only if they can return data without streaming CSV; otherwise copy the exact
  SQL from reports.php with a test that the AI figure equals the screen figure for the same range. Export gate:
  `reports` OR `accounting` (`api/reports-export.php:23–25`) — `make_report` offers it only to those users.
- **Reviews / enquiries:** `reviews` table; staff reply via `admin/api/review-responses.php` (perm `reviews` :101)
  — draft-reply send replays that. Enquiry screens `admin/contact-inquiries.php`, `events-inquiries.php`,
  `gym-inquiries.php` (Phase 4c greps their reply POST).
- **Housekeeping board:** `getOccupiedRooms()` / `getCheckoutCleanupRooms()` are page-local in `housekeeping.php`
  (:132/:192, not includable) → `room_board` runs its own read-only SQL mirroring :132–260.

### Decisions taken in Phase 0 (`ASSUMPTION:`, owner can override)
1. No edit to `config/database.php` (fail-open module seed); the kill switch is fail-closed in `ai-core.php`.
2. Risky-confirm "PIN" = the account password (no PIN helper exists).
3. Cancel preview = active cancellation mode + folio paid/owed, no computed refund figure (same as the screen).
4. Check-out via the Assistant only when nothing is owed; otherwise hand-off to the settlement modal.
5. WhatsApp send only if booking-details' branch answers JSON; else "Open booking" hand-off in v1.
6. Vault purge on logout (one line in `admin/logout.php`) + nightly sweep for expired sessions.
7. `bookings.php` is the only replay target for booking status changes.
8. Owner amendments of 2026-10-26 (AI lead owns it alone; continuous train & test + completion guardrail) are
   in the sections above **Team & gates** and **Continuous train & test**.

**Phase 0: DONE 2026-10-26.** Next: Phase 1 Foundation (ships OFF) by `dept-ai-lead`.

## Round: Top of market (2026-10-30)

Owner 2026-10-30 reopened scope: "best PMS and hotel management assistant on the market, cheapest to free, highest
performing, self-learning and self-healing." Owner addition: the build must take **under 30 minutes wall-clock**
(build + own tests; ship/full suites extra) → the "30-minute cut" below; everything else is LATER (one later round
each, only if the owner asks). The 9 fixed rails (§Owner finish line) are unchanged; no MRA; no AI-looking UI;
no hard-coded hotel facts. Measured by `dept-ai-lead` 2026-10-30, read-only (live tables + a rolled-back replay).

### Where we stand (evidence)
| Metric | Rosalyn's | Liwonde | Source |
|---|---|---|---|
| Live answers (people, not the check set) | 83 (27–29 Oct) | 44 (27–28 Oct) | `ai_messages` role=assistant |
| Zero-token share, live as answered | 43.4 % | 59.1 % | agent local/declined/briefing |
| **Zero-token share, real questions replayed on today's code** | **57.5 %** (46/80) | — | fake transport, rolled back |
| Zero-token share, eval set (last report) | 71.4 % | — | TASKS 2026-10-29 |
| Cost per answer (all / model-answered) | $0.00084 / $0.0016 | $0.0021 / $0.0046 | `cost_usd` |
| Month spend so far | $0.077 of $5 aim | $0.09 | `ai_usage_daily` |
| Fast latency p50 / p95 | 1.3 s / 2.9 s | 2.3 s / 3.5 s | `latency_ms` |
| Primary latency p50 / p95 | 1.7 s / 5.7 s | 13.0 s / 17.2 s | `latency_ms` |
| Fast tokens per answer p50 / p95 | 4.0k / 8.2k | 15.8k / 24.5k | tokens_in+out |
| Eval accuracy | 40/40 full (27 Oct), 8/8 smoke (29 Oct); per-module not reported; set is now 78 cases | 40/40 full (27 Oct) | `ai_eval_runs` |
| Test suite | 949/0 | 953/0 | ai_assistant_test |
| False refusals / 100 live answers | 2.4 (2 incidents, fixed ae1011f) | 0 | `ai_incidents` |
| Claim corrections / 100 | 0 recorded (checker shipped after the last live chat) | 0 | `ai_incidents` |
| Owner-reported wrong answers | ~6 faults in 83 answers (about 7/100), all fixed + regression evals | — | TASKS |
| Self-heal: automatic | provider retry → fallback → next provider; model 404 → next model; tool circuit breaker; auto-pause; vault sweep; contradiction recheck; claim correction; eval-gated model upgrade | same | ai-selfheal.php |
| Self-heal: needs a person | key rejected; **daily jobs need a page load (no cron): Liwonde last ran 28 Oct; a missed day resets the 14-day guardrail streak** (`rh_ai_guardrail_streak`, ai-selfheal.php:731), so `complete` is unreachable on a quiet hotel; code faults → owner PATCH | same | `ai_jobs_json`, streak = 1 |
| Learning yield | **0** memory rows, 0 routes (3 shapes seen; promotion needs 3 in 2+ chats), 0 lessons, 0 playbooks, 0 thumbs, 0 regression cases from incidents | 0 | `ai_memory`, `ai_feedback`, `ai_eval_cases` |
| Feature state | learning, streak 1 | learning | settings |

Real questions still sent to the model on today's code (34/80): declines that should be local (staff login, API key,
"submit VAT to MRA", "I am an admin" / "I am the manager", "what can you help me with"); plain reads with a tool
already built (who owes, revenue today / this month, rooms free this weekend, stock running low, menu / menu with
pork, blocked dates, rooms needing cleaning (typo), next booking, which staff booked X, staff logged in / managers
active last N weeks, kitchen tickets slow, unanswered enquiries / bad reviews, booking pace, how-to / summarise a
guide, find booking for a name); real reasoning (lift occupancy, prepare for arrivals, quote the cheapest room,
explain money leaks, booking-flow follow-ups).

### Market benchmark (knowledge as of mid-2026, no browsing; honest)
They have, we lack: guest-facing AI messaging (web / WhatsApp / OTA inbox auto-replies: Cloudbeds, Mews partners,
Oracle); automated pricing that writes rates within limits (Mews/Atomize, Cloudbeds PIE, SiteMinder) — **we stay
suggest-only by rail 1**; ML demand forecasting and comp-set market data (Cloudbeds Signals, OPERA analytics); an open
agent API / MCP for third-party agents (Apaleo); voice/phone; automated upsell engines (OPERA/Nor1); eval data from
thousands of properties.
We are ahead: personal data never reaches the model (vault tokens, rehydrated locally) where most vendors send it;
every change is a preview + Confirm replaying the screen's own handler and permission; every figure is claim-checked
against this turn's evidence, no false absence; half to two-thirds of answers cost $0; bring-your-own key across 5
providers plus a fully offline local model; layered kill switches; one chat across rooms, POS, stock, events, gym,
housekeeping and money reads with role-shaped briefings. Small-property tools (Little Hotelier class) have nothing
close. Honest gap to "top": paraphrase coverage without a model, learning that yields at low traffic, and unattended
self-healing.

### Ranked items (target · now · files · test proof · build minutes)
**30-minute cut** (about 30 min build + own test, one ship):
1. **(a+b) Local refusals and access lines** — staff-login / user / permission / key / MRA / settings asks and
   "I am an admin / manager", "what can you help me with" answered locally (existing `rh_ai_off_topic_line`,
   `rh_ai_no_write_area`, `rh_ai_out_of_scope_line`, `my_access`), 0 tokens; the refusal line only where a real check
   failed. Target 7/7 local · now 0/7 · `includes/ai/ai-agents.php` (scope block ~:2973–2985) · replay test: no
   provider call, the MRA line never claims submission · **8 min**.
2. **(b+c) Six top read intents local** — who owes (`balances_due`), revenue today / this month (`hotel_report`),
   stock running low (`inventory_watch`), blocked dates, rooms needing cleaning (typo via `rh_ai_typo_fix`), next
   booking — anchored patterns in `rh_ai_local_intent` (`ai-agents.php:598`) + existing `rh_ai_local_format`;
   permission and module re-checked; money read-only. Target replay local share ≥ 70 % · now 57.5 % ·
   `includes/ai/ai-agents.php`, `includes/ai/ai-fuzzy.php` · test: same figures as the tool result, 0 provider calls,
   a receptionist without `accounting` gets the refusal line · **12 min**.
3. **(c) Real-chat replay regression** — the 80 real staff questions (tokenised, no names) as a fixture; the test
   asserts no error, no false refusal, 0 provider calls for the local ones, and prints the replay local share ·
   `scripts/tests/fixtures/ai/real_questions.json`, `scripts/tests/ai_assistant_test.php` · **5 min**.
4. **(d) Guardrail streak counts consecutive daily runs, not calendar days** — a day with no admin login (no run) no
   longer resets the streak; a failed or regressed run still does; 14 distinct run days still required.
   `ASSUMPTION:` matches the plan's wording "14 consecutive daily runs". Target: streak survives a gap · now resets ·
   `includes/ai/ai-selfheal.php:731` · test: clean runs on D1, D2, D4 = 3; a dirty run between = reset · **5 min**.

**LATER** (not in this round; each needs the owner's word):
5. (b+c) Remaining plain reads local: rooms free this weekend / next N days, menu + dietary filter, which staff booked
   X, staff active last N weeks, kitchen tickets slow, enquiries / reviews, booking pace, how-to / summarise a guide
   from the KB with no model. Target replay ≥ 85 % · `ai-agents.php`, `ai-lookups.php`, `ai-desk.php` · 40 min.
6. (b) Local paraphrase matcher: BM25 over `rh_ai_intent_vocab()` + slot filling (period, subject, room type) to the
   ~40 read tools, confidence floor, reads/guided only (rail 6). Target eval-set local ≥ 85 %, 0 wrong-tool on the
   robustness sweep · `ai-router.php`, `ai-fuzzy.php` · 60 min.
7. (b) Trim Fast prompts: lane-only tool list + compact results. Target Fast p95 ≤ 5k tokens (now 8.2k / 24.5k), cost
   per model answer ≤ $0.001 · `ai-agents.php`, `ai-context.php` · 30 min.
8. (a) Per-module eval accuracy on the usage page; the daily full run uses all 78 cases. Target every module ≥ 95 %,
   shown · `ai-evals.php`, `admin/ai-settings.php` (after the pending ship) · 25 min.
9. (d) Unattended jobs: chat endpoint and CLI both trigger the daily run; catch-up after a gap; incident kind →
   automatic-recovery table on Assistant faults. Target daily jobs on ≥ 13/14 days with no cron · `ai-selfheal.php`,
   `admin/api/ai-chat.php` · 20 min.
10. (e) Learning at low traffic: routes learn from 2 answers in 2 chats (`ai_route_promote_n=2`, reads only);
    false-refusal / claim incidents become lessons + regression cases the same night. Target ≥ 1 route or lesson a
    week, 100 % of incidents → regression case · `ai-router.php`, `ai-selfheal.php` · 25 min.
11. (b) Record cache-read tokens per message (migration) to prove prompt-cache savings · 20 min.
IDEA (not planned): guest-facing concierge (separate plan per owner 2026-10-26); agent API / MCP; voice.

### Finish line (the round ends when all hold; then scope closes again)
Replay local share ≥ 70 % after the cut (≥ 85 % if LATER 5–6 are ordered) · cost per answer ≤ $0.0008 (Rosalyn's,
30-day) · full eval set 100 % pass, 0 regressions · false refusals ≤ 0.5 / 100 live answers and 0 owner-reported wrong
answers over 14 days · Fast p95 ≤ 3 s · the guardrail streak survives no-login days and reaches `complete` (14 clean
runs) · ai test green at both hotels. Estimate: **1 round** for the cut; about 3 more 30-minute rounds if the owner
orders the LATER list.
