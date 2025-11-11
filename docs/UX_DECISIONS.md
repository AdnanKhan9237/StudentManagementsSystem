# UX Decisions

## Command Palette vs. Sidebar – Account Actions

- Decision: Keep the command palette focused on navigation and non-destructive actions. Place destructive or session-affecting actions (e.g., `Log Out`) in the persistent sidebar’s Account section.
- Rationale:
  - Reduces duplication that confuses users when the same “remove”/logout appears in two high-visibility places.
  - Prevents accidental activation from search; users expect quick nav in palette and account management in sidebar.
  - Improves accessibility: predictable placement of session actions in a consistent, keyboard-accessible sidebar.
- Implementation:
  - `partials/command_palette.php`: removed `Log Out` from `$actions`, keeping `Change Password`.
  - `partials/sidebar.php`: continues to provide both `Change Password` and `Log Out` in the Account section.
- State Management:
  - Theme selection persists via `localStorage` (`assets/js/sidebar.js`).
  - Command palette remains stateless beyond open/close; logout triggers server-side session handling via `logout.php`.
- Accessibility:
  - Sidebar items are anchor elements that are reachable via keyboard with visible focus outlines from the design system.
  - Command palette maintains ARIA roles (listbox/option), search input `aria-label`, and Esc to close.

## Testing & Regression Protection

- Added `tests/CommandPaletteTest.php` which asserts the palette actions exclude `Log Out` while the sidebar continues to provide it.
- This guards against future reintroductions of duplicate removal/session actions in the palette.

