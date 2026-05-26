---
description: "Deep audit of a PHP file or entire folder — finds security issues, N+1 queries, missing CSRF, XSS risks, dead code. Invoke: /audit"
name: "Security & Quality Audit"
argument-hint: "File or folder to audit (e.g. 'api/' or 'admin/payments.php')"
agent: "agent"
model: "Claude Sonnet 4.5 (copilot)"
tools: [read, edit, execute, search]
---

Audit the specified file(s) for security vulnerabilities and quality issues, then fix everything found.

**Target**: $args

## What to check and fix

### Security (fix immediately — never just report)
- [ ] Any SQL without PDO prepared statements → rewrite with `?` placeholders
- [ ] Any `htmlspecialchars()` missing on output of user/DB data → add it
- [ ] State-changing forms missing CSRF token → add token generation + validation
- [ ] `$_GET`/`$_POST` used without sanitisation → add `filter_input()` / `intval()` / `trim()`
- [ ] `die()` or `exit()` with raw error messages visible to users → replace with proper error handling
- [ ] API endpoints without auth check → add ApiAuth
- [ ] File uploads without type/size validation → add validation

### Performance
- [ ] N+1 queries inside loops → rewrite as single JOIN or subquery
- [ ] Missing `getCache()` / `setCache()` on expensive repeated queries → add caching
- [ ] `SELECT *` on large tables → replace with specific column list

### Code quality
- [ ] `mysqli_*` anywhere → replace with PDO
- [ ] `console.log()` in JS → remove
- [ ] Variables declared in try block and used outside without pre-initialisation → fix

## Process
1. Read the file(s)
2. List every issue found
3. Fix ALL of them in the same turn
4. Run `php -l` + get_errors on every edited file
5. Fix any new errors introduced
6. One sentence: what issues were found and fixed.
