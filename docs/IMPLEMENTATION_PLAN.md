# Implementation Plan: Palette & Sidebar Action Unification

## Goals
- Eliminate duplicate removal/session actions across interfaces.
- Maintain feature parity, improve UX clarity, and enforce accessibility.
- Add tests to prevent regression.

## Current State (Analyzed)
- `partials/command_palette.php` included `Change Password` and `Log Out` in actions.
- `partials/sidebar.php` includes both in the Account section.
- The design system (`assets/css/design-system.css`) provides visible focus and theme tokens.
- Theme persistence handled by `assets/js/sidebar.js` using `localStorage`.

## Decisions
- Command palette focuses on navigation and non-destructive actions.
- Sidebar hosts session-affecting actions (e.g., `Log Out`) in a predictable location.
- Documentation and tests safeguard this boundary.

## Deliverables
- Action boundary implemented: `Log Out` removed from palette actions.
- UX documentation: `docs/UX_DECISIONS.md` (added).
- Unit tests: `tests/CommandPaletteTest.php` (added) and `tests/SidebarTest.php` (added).
- Progress log: `docs/PROGRESS_LOG.md` (added).

## Tasks & Timeline
1) Code audit and decision (Complete)
   - Review palette and sidebar; decide action boundary.
   - Timeline: Day 0.

2) Implement changes (Complete)
   - Remove `Log Out` from palette; keep in sidebar.
   - Timeline: Day 0.

3) Documentation (Complete)
   - Record decision, rationale, accessibility, and regression strategy.
   - Timeline: Day 0.

4) Unit tests (Complete)
   - Palette test ensures `Log Out` excluded; sidebar test ensures `Log Out` present.
   - Timeline: Day 0.

5) Preview and QA (Complete)
   - Verify dashboard behavior; ensure accessibility affordances remain.
   - Timeline: Day 0.

6) Optional Enhancements (Pending by request)
   - Unified confirmation flow for `Log Out` via modal.
   - Centralize nav schema (single source for destinations).
   - Lightweight e2e smoke checks via headless browser.

## Resources Needed
- Environment: `PHP >= 7.4`, local dev server (`php -S`), browser.
- Time: ~2–4 hours for audit, implementation, documentation, and unit tests.
- Personnel: 1 developer familiar with the codebase.

## Success Criteria
- Command palette no longer shows `Log Out`.
- Sidebar retains `Log Out` and `Change Password` with visible focus.
- Tests pass: `CommandPaletteTest.php`, `SidebarTest.php`.
- Accessibility unaffected: keyboard navigation, ARIA labels, contrast.
- Theme persistence remains functional across pages.

## Risks & Mitigations
- Future duplication reintroduced: mitigated by unit tests.
- User expectation mismatch: documented UX decisions; can enable optional confirmation.
- Accessibility regression: rely on existing design-system tokens and patterns.

