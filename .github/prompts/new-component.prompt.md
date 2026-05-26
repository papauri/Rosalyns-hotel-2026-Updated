---
description: "Build a fully responsive UI component — card, grid, section, modal, form, nav. Works on 320px phones through 4K. Invoke: /new-component"
name: "New Responsive Component"
argument-hint: "Component description (e.g. 'amenities card grid for the rooms page' or 'mobile nav drawer for the header')"
agent: "agent"
model: "Claude Sonnet 4.5 (copilot)"
---

Build a fully responsive, fluid UI component for this project.

**Input**: $args

## Rules — non-negotiable

1. **Mobile-first CSS**: base styles for 320px, scale up with `min-width` queries only
2. **No hardcoded px widths** on layout elements — grid `minmax()`, `%`, or `clamp()`
3. **All font sizes**: `clamp(min, fluid, max)` — never fixed px
4. **Touch targets**: `min-height: 44px` on every interactive element
5. **Inputs**: `font-size: max(16px, 1rem)` — prevents iOS zoom
6. **Images**: `width: 100%; height: auto` + `loading="lazy"` + explicit `width`/`height` attributes
7. **Full-screen layouts** (KDS/POS): use `dvh`/`dvw`, never `vh`/`vw`
8. **Safe area**: `env(safe-area-inset-*)` on any fixed/full-screen container
9. **CSS variables only** — no hardcoded hex, no hardcoded spacing in px
10. **BEM naming** — `.block__element--modifier`

## Breakpoints to test mentally before writing
- 320px — small Android phone
- 480px — large phone portrait
- 768px — tablet portrait / iPad
- 1024px — tablet landscape
- 1280px — laptop
- 2560px — 4K

## What to build

1. HTML structure (PHP snippet or standalone HTML block)
2. CSS in the correct `css/` file (components/ or sections/ depending on scope)
   - Add `@import` to `css/main.css` if it's a new file
3. Any JS interaction in a separate `js/` file (Vanilla ES6+, no jQuery)

## After building
- Run `php -l` if PHP was touched, then get_errors
- One sentence: what was built and where the files are.
