---
description: "Scaffold a complete new customer-facing page with hero, sections, cards, animations. Invoke: /new-page"
name: "New Customer Page"
argument-hint: "Page name and purpose (e.g. 'spa.php — spa & wellness services page')"
agent: "agent"
model: "Claude Sonnet 4.5 (copilot)"
---

Scaffold a complete new customer-facing page for this project.

**Input**: $args

## What to build

1. Create the PHP page file following the public page template in copilot-instructions.md:
   - `config/database.php` + `config/base-url.php` at top
   - `$seo_data` array defined before doctype
   - Google Fonts + Font Awesome 6.7.2 + `css/main.css`
   - `includes/header.php`, `includes/footer.php`, `includes/modal.php`
   - `js/page-transitions.js` + `js/scroll-reveal.js` deferred at bottom
   - Real PDO data fetching with `getCache()` / `setCache()` caching
   - Minimum 3 sections using `editorial-section landing-section` + `section-header` pattern
   - All images: `loading="lazy"`, hero image: eager
   - `data-lazy-reveal` on all sections

2. Create `css/sections/$slug.css` with all page styles:
   - No hardcoded hex — CSS variables only
   - BEM class names
   - `clamp()` for all font sizes
   - Card hover: `translateY(-4px)` + `var(--shadow-xl)`
   - Add `@import 'sections/$slug.css';` to `css/main.css`

3. After creating files:
   - Run `php -l $file.php` — fix errors
   - Run get_errors — fix intelephense warnings
   - Only report back once both pass

4. One sentence: what was created.
