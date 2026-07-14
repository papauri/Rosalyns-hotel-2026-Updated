# COST_LOG — Rosalyn's Hotel 2026 build system

> Append-only. One row per agent dispatch (build-planner, specialist, ui-designer,
> qa-auditor, codebase-scout). Written by the `/build-loop` orchestrator, not by the
> dispatched agents themselves.
>
> **Estimates are a heuristic** (`chars ÷ 4` on the drafted prompt + a fixed per-model
> overhead for system prompt/tool defs + an assumed output budget by task type) — not a
> real tokenizer count. Treat the Est. columns as ballpark, the Actual column as ground
> truth (`subagent_tokens` returned by the Agent tool on completion).
>
> **Cost tier** is relative API-list-price weighting used only as a proxy for "how much
> of a Pro-plan usage allowance this burns" — Anthropic doesn't publish the exact Pro
> internal weighting formula, so treat the tier as directional, not literal billing.
> Haiku = 1x baseline · Sonnet ≈ 4x Haiku · Opus ≈ 19x Haiku (approx., from public API
> list pricing ratios).
>
> High-cost threshold (Pro plan, calibrated 2026-07-15): **35,000 estimated tokens**
> (≈15% of a typical Pro 5-hour window, itself an approximation). Any single dispatch
> estimated above this must be confirmed with the owner before running — see
> `.claude/skills/build-loop/SKILL.md`.

## Log

| Timestamp | Task ID | Agent | Model | Est. In | Est. Out | Est. Total | Tier | Actual Tokens | Accuracy | High-Cost Flag |
|---|---|---|---|---|---|---|---|---|---|---|
| 2026-07-15 #1 | R4-02b1 | frontend-specialist | sonnet | 2600 | 6000 | 8600 | sonnet | 59437 | 691% | — |
| 2026-07-15 #2 | R4-02b1 | qa-auditor | haiku | 1200 | 1500 | 2700 | haiku | 40433 | 1497% | — |
| 2026-07-15 #3 | R4-02b2 | frontend-specialist | sonnet | 3000 | 72000 | 75000 | sonnet | 128478 | 171% | HIGH-COST — owner confirmed proceed |
| 2026-07-15 #4 | R4-02b2 | qa-auditor | haiku | 2000 | 3000 | 40000 | haiku | 62338 | 156% | HIGH-COST — covered by prior owner approval for this batch |
| 2026-07-15 #5 | R4-02b3 | frontend-specialist | sonnet | 2500 | 55000 | 57500 | sonnet | 97076 | 169% | HIGH-COST — covered by prior owner approval for this batch |
| 2026-07-15 #6 | R4-02b3 | qa-auditor | haiku | 2000 | 3000 | 40000 | haiku | 32812 | 82% | HIGH-COST — covered by prior owner approval for this batch |
| 2026-07-15 #7 | R4-02c | frontend-specialist | sonnet | 3000 | 80000 | 83000 | sonnet | 159368 | 192% | HIGH-COST — covered by prior owner approval for this batch |
| 2026-07-15 #8 | R4-02c | qa-auditor | haiku | 2500 | 3500 | 45000 | haiku | 75047 | 167% | HIGH-COST — covered by prior owner approval for this batch |
| 2026-07-15 #9 | R4-04 | build-planner | opus | 3500 | 16000 | 19500 | opus | 41250 | 212% | — |
