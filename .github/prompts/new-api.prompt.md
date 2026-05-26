---
description: "Scaffold a complete new REST API endpoint with auth, rate limiting, response format. Invoke: /new-api"
name: "New API Endpoint"
argument-hint: "Endpoint name and method (e.g. 'POST api/room-service.php — submit a room service order')"
agent: "agent"
model: "Claude Sonnet 4.5 (copilot)"
---

Scaffold a complete new API endpoint for this project.

**Input**: $args

## What to build

1. Create `api/$slug.php` with full structure:
   - `config/database.php`, `config/security.php`, `sendSecurityHeaders()`
   - `Content-Type: application/json` header
   - CORS preflight OPTIONS handler
   - ApiAuth if it's a protected endpoint
   - Input validation with sanitisation (`htmlspecialchars`, `filter_var`, `intval`)
   - PDO prepared statements only — never string-concatenated SQL
   - Consistent response: `['success' => true, 'data' => ...]` or `['success' => false, 'error' => ..., 'code' => ...]`
   - Correct HTTP status codes (200/201/400/401/403/404/429/500)
   - `rh_log_event()` on errors

2. After creating:
   - Run `php -l api/$slug.php` — fix errors
   - Run get_errors — fix warnings
   - Only report back once both pass

3. One sentence: what endpoint was created and what it does.
