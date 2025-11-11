# Progress Log: Palette & Sidebar Action Boundary

## 2025-11-11
- Audited `partials/command_palette.php` and `partials/sidebar.php` for duplicated actions.
- Decision: keep `Log Out` only in the sidebar; palette retains non-destructive actions.
- Implemented change in `command_palette.php`: removed `Log Out` from `$actions`.
- Added unit test `tests/CommandPaletteTest.php` to enforce exclusion of `Log Out`.
- Added unit test `tests/SidebarTest.php` to ensure sidebar retains `Log Out`.
- Documented rationale and accessibility considerations in `docs/UX_DECISIONS.md`.
- Previewed `http://localhost:8000/dashboard.php` to validate UI and behavior.

## Next (pending by request)
- Optional: add modal confirmation for `Log Out` for explicit consent.
- Optional: centralize route definitions to avoid future duplication.

