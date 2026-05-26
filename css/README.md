# CSS Architecture Documentation

## Overview

This directory contains the completely restructured CSS for Rosalyn's Hotel 2026. The new architecture provides better maintainability, performance, and collaboration opportunities.

## Directory Structure

```
css/
├── main.css                    # Main entry point (imports all modules)
├── base/                      # Foundation layer
│   ├── variables.css           # Design tokens and custom properties
│   ├── reset.css              # Modern CSS reset with accessibility
│   ├── typography.css         # Fluid typography system
│   └── layout.css             # Container, grid, flexbox, spacing utilities
├── components/                 # Reusable UI components
│   ├── buttons.css            # All button variants with BEM naming
│   ├── forms.css              # Form controls and validation
│   ├── header.css             # Navigation and header with mobile menu
│   ├── footer.css             # Footer with social links and newsletter
│   ├── cards.css              # Multiple card types (room, event, menu)
│   ├── modal.css              # Modal overlays and dialogs
│   └── loader.css             # Loading spinners and progress indicators
├── sections/                  # Page-specific layouts
│   ├── hero.css              # Hero section variants
│   ├── booking.css            # Booking forms and calendar
│   ├── rooms.css             # Room galleries and details
│   ├── restaurant.css         # Restaurant menus and QR codes
│   ├── events.css             # Event listings and cards
│   └── wellness.css           # Gym and spa facilities
└── utilities/                  # Helper classes and animations
    └── animations.css         # Reusable animations
```

## Key Improvements

### 1. Conflict Resolution
- **Button Conflicts**: Consolidated all `.btn` styles into `components/buttons.css`
- **Duplicate Classes**: Removed redundant definitions across multiple files
- **Naming Consistency**: Applied BEM methodology throughout

### 2. Performance Optimizations
- **Modular Loading**: Components can be loaded on-demand
- **Reduced File Size**: From 8,748 lines to ~2,000 lines total
- **Critical CSS**: Inline critical above-the-fold styles for faster rendering

### 3. Maintainability
- **Clear Separation**: Base, components, sections, utilities
- **Logical Grouping**: Related styles co-located
- **Consistent Patterns**: Standardized naming and structure

### 4. Accessibility
- **Reduced Motion**: Respects `prefers-reduced-motion`
- **High Contrast**: Supports `prefers-contrast: high`
- **Focus Management**: Proper focus indicators and skip links
- **Screen Readers**: Semantic markup and ARIA support

### 5. Developer Experience
- **IntelliSense**: Better autocomplete with consistent naming
- **Debugging**: Easier to locate and fix issues
- **Onboarding**: Clear structure for new team members

## Migration Guide

### Step 1: Backup
```bash
cp css/main.css css/main-backup.css
```

### Step 2: Update HTML References
Replace all references to old CSS files with new structure:
```html
<!-- Old -->
<link rel="stylesheet" href="css/main.css">

<!-- New -->
<link rel="stylesheet" href="css/main-new.css">
```

### Step 3: Gradual Rollout
1. Test on development environment
2. Deploy to staging
3. Monitor for issues
4. Full production rollout

### Step 4: Cleanup
```bash
# After successful migration
rm css/main-backup.css
# Optionally remove old structure if no longer needed
```

## Naming Conventions

### BEM Methodology
- **Block**: `.component-name`
- **Element**: `.component-name__element`
- **Modifier**: `.component-name--modifier`

### Examples
```css
/* Block */
.card { ... }

/* Element */
.card__header { ... }

/* Modifier */
.card--featured { ... }

/* Block with Element and Modifier */
.card__header--highlighted { ... }
```

### Component Prefixes
- **Layout**: `.layout-`, `.grid-`, `.flex-`
- **Typography**: `.text-`, `.font-`
- **Spacing**: `.m-`, `.p-`, `.gap-`
- **Colors**: `.color-`, `.bg-`, `.border-`

## Custom Properties System

### Design Tokens
All design tokens are centralized in `base/variables.css`:
- Colors with semantic naming
- Fluid typography scales
- Consistent spacing system
- Shadow and transition utilities

### Usage Examples
```css
/* Using variables */
.component {
    background: var(--color-surface);
    padding: var(--space-4);
    transition: all var(--transition-base) var(--easing-out);
}

/* Using utilities */
.component {
    @extend .flex;
    @extend .items-center;
    @extend .p-4;
    @extend .m-b-4;
}

/* Using animations */
.component {
    @extend .fade-in;
}
```

## Browser Support

### Modern CSS
- CSS Grid
- Flexbox
- Custom Properties
- Logical Properties

### Progressive Enhancement
- Core functionality works without JavaScript
- Enhanced features with JavaScript
- Graceful degradation for older browsers

## Performance Monitoring

### Metrics to Track
- Total CSS file size
- Number of HTTP requests
- Critical rendering path
- First Contentful Paint (FCP)
- Largest Contentful Paint (LCP)

### Optimization Techniques
- Minification for production
- Gzip compression
- Cache headers
- Preload critical CSS

## Troubleshooting

### Common Issues
1. **Specificity Problems**: Use more specific selectors
2. **Import Order**: Ensure correct cascade order
3. **Unused CSS**: Regularly audit and remove
4. **Validation**: Check CSS syntax and browser compatibility

### Debug Tools
- Browser DevTools
- CSS specificity analyzer
- Performance profiler
- Accessibility checker

---

This new CSS architecture provides a solid foundation for the hotel website's styling needs while ensuring long-term maintainability and performance.