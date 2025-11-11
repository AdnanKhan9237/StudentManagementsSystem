# SOS Technical Training Institute — Design System

This document defines the responsive design system applied across all pages. It uses a mobile‑first approach with CSS variables, consistent typography, spacing, and component styles, delivered via `assets/css/design-system.css`.

## Brand & Colors
- Primary: `--brand-primary: #2bb673`
- Secondary: `--brand-secondary: #0ea5e9`
- Backgrounds: `--bg`, `--surface`, `--card`
- Text: `--text`, `--text-muted`
- Status: `--success`, `--warning`, `--danger`, `--accent`

Light mode overrides are provided via `html[data-theme="light"]` or `body.light`.

## Typography
- Font: Inter, System UI fallback
- Scale: `--font-size-xs` → `--font-size-3xl`
- Headings: `h1/h2/h3` mapped to the scale with strong weight

## Spacing & Radius
- Spacing scale tokens: `--space-1` → `--space-10`
- Default radius: `--radius: 10px`
- Shadows: `--shadow-sm`, `--shadow-md`

## Breakpoints (mobile‑first)
- `--bp-sm: 576px` (phones)
- `--bp-md: 768px` (small tablets)
- `--bp-lg: 992px` (large tablets / small laptops)
- `--bp-xl: 1200px` (desktops)

## Layout Patterns
- `.container-fluid` manages horizontal rhythm via responsive padding and max‑width at desktop
- `.content-grid` utility: responsive grid with `cols-2` and `cols-3` variants
- `.page-header` provides consistent section separation

## Components
- Cards: Bootstrap `.card` re‑skinned with brand surface, borders, and radius
- Buttons: Primaries/semantic variants mapped to brand tokens
- Tables: Thead/background/borders aligned to tokens; `.table-striped` tuned for dark/light
- Forms: `.form-control`, `.form-select` with accessible focus states
- Badges & Alerts: status colors standardized with rounded pills and subtle surfaces

## Accessibility
- `:focus-visible` outlines for keyboard navigation
- Color contrast tuned to meet WCAG AA across text/background combinations
- `.sr-only` utility for screen reader‑only labels

## Usage
1. Include `assets/css/design-system.css` after Bootstrap and Font Awesome.
2. Use standard Bootstrap structure; the design system refines visuals globally.
3. Prefer mobile‑first structure; avoid fixed widths. Use `.content-grid` for responsive panels.
4. Optional light theme: set `document.documentElement.dataset.theme = 'light'`.

## Change Management
Updates to tokens or components should be made in `assets/css/design-system.css` and reviewed visually on key pages: dashboard, attendance, students, and notifications.

