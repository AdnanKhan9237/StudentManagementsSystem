# UI Style Guide

This project uses Bootstrap only. No custom CSS files. Everything must be responsive.

## CSS Load Order
- Load `Bootstrap` CSS first.
- Load `Font Awesome` for icons.
// No custom CSS files are used. Rely on Bootstrap utilities and components.

## Responsiveness
- Use Bootstrap grid (`container`, `row`, `col-` classes) for layout.
- Prefer responsive utilities: `d-none d-md-block`, `text-sm`, `mt-3`, etc.
- Images and media should use `img-fluid` and responsive wrappers.
- Avoid fixed widths; use `%`, `rem`, or Bootstrap spacing utilities.

## Custom CSS
// No custom CSS. Prefer Bootstrap components and utility classes for layout and spacing.

## Components
- Forms: use `.form-control`, `.form-check`, `.btn` variants.
- Navigation: use `.navbar`, `.nav`, and responsive toggles.
- Cards: use `.card` components with spacing utilities.

## Icons
- Use `Font Awesome` icons with `<i class="fa-solid fa-...">` inline in buttons and labels.

## Scripts
- Include `Bootstrap Bundle` JS at the end of the document before closing `</body>` or via CDN.

## Accessibility
- Ensure sufficient contrast; use ARIA labels for interactive icons.
- Preserve keyboard navigation for all interactive controls.

## Example Includes
```html
<!-- Bootstrap + FA -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<!-- Custom overrides -->
<link rel="stylesheet" href="/assets/css/style.css">

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
```