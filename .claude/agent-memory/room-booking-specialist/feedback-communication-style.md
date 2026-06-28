---
name: feedback-communication-style
description: User wants concise outcome summaries, not code dumps; invest effort in senior-level systems reasoning
metadata:
  type: feedback
---

Do not show the actual code changes / diffs in responses. Give a concise summary of WHAT was fixed and WHY it matters.

**Why:** showing code line-by-line wastes the user's credit; they can read the diff themselves. They explicitly want that budget spent on higher-value thinking.

**How to apply:**
- Report results as outcome summaries (what changed, impact, any follow-ups/risks) — skip code snippets unless a specific exact string is load-bearing (e.g. a signature the user asked for, or a bug being pointed out).
- Spend the saved effort reasoning like a master/principal systems developer: anticipate edge cases, concurrency/data-integrity issues, scope/precedence rules, downstream effects, and propose the cleanest architectural option rather than the quickest patch.
- Still name the files touched (absolute paths) so the user can review, just don't paste their contents.
