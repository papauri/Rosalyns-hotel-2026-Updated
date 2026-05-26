---
description: "Debug and fix a broken PHP file — runs php -l and get_errors in a loop until clean. Invoke: /fix-php"
name: "Fix PHP File"
argument-hint: "Path to the broken PHP file (e.g. admin/bookings.php)"
agent: "agent"
model: "Claude Sonnet 4.5 (copilot)"
---

Fix all errors in the specified PHP file until it is completely clean.

**Input**: $args (file path)

## Loop until clean

Repeat this cycle until zero errors:

1. Run `php -l $file` — if syntax error, locate and fix it, go to step 1
2. Run get_errors on the file — for each intelephense error/warning:
   - Undefined variable from `require_once` include: add `/** @var type $var */` after the include
   - Variable possibly undefined: pre-initialise before the try/catch block
   - Wrong type: fix the type or add a cast
   - Fix all warnings, go to step 1
3. Only stop when BOTH `php -l` passes AND get_errors returns zero issues

## Report back
One sentence: what errors were found and fixed.
