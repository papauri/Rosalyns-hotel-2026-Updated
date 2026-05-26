---
description: "Scaffold a complete new admin page with sidebar, header, table, and modal. Invoke: /new-admin-page"
name: "New Admin Page"
argument-hint: "Page name and purpose (e.g. 'conference-inquiries — list and manage conference booking inquiries')"
agent: "agent"
model: "Claude Sonnet 4.5 (copilot)"
---

Scaffold a complete new admin page for this project.

**Input**: $args

## What to build

1. Create `admin/$slug.php` following the admin page template in copilot-instructions.md exactly:
   - `require_once '../admin-init.php'` + `/** @var array $user */`
   - Sidebar nav + admin-content + admin-header + admin-container layout
   - Font Awesome 6.7.2 from cdnjs + admin-responsive-enhancements.css
   - Real PDO queries (no placeholders)
   - Filter bar + sortable table with Edit/View/Delete inline buttons
   - renderModal() for any forms — never window.confirm or window.alert
   - CSRF token on every form

2. After creating the file:
   - Run `php -l admin/$slug.php` — fix any errors before continuing
   - Run get_errors on the file — fix any intelephense warnings
   - Only report back once both pass

3. Report back in ONE sentence: what file was created and what it does.
