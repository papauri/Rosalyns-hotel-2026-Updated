---
description: "Add a new section to an existing customer-facing page — section header, content, CSS, animations. Invoke: /new-section"
name: "New Page Section"
argument-hint: "Page file and section description (e.g. 'index.php — add a testimonials section after the rooms section')"
agent: "agent"
model: "Claude Sonnet 4.5 (copilot)"
---

Add a new section to an existing customer-facing page.

**Input**: $args

## What to build

1. Read the target PHP file to understand existing structure and CSS imports
2. Add the section HTML using the standard pattern:
```html
<section class="editorial-section landing-section" data-lazy-reveal>
  <div class="section-header">
    <span class="section-header__label">UPPERCASE LABEL</span>
    <h2 class="section-header__title">Heading</h2>
    <p class="section-header__description">Supporting copy</p>
  </div>
  <!-- content -->
</section>
```
3. Add all CSS for the section to the relevant `css/sections/*.css` file (not inline in PHP)
   - CSS variables only — no hardcoded hex
   - BEM class names
   - `clamp()` for font sizes
   - Card hover: `translateY(-4px)` + `var(--shadow-xl)`
   - `@media (prefers-reduced-motion: no-preference)` guard on animations
4. After editing:
   - Run `php -l $file` — fix errors
   - Run get_errors — fix warnings
5. One sentence: what section was added and where.
