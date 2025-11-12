document.addEventListener('DOMContentLoaded', function () {
    const dropdownToggles = document.querySelectorAll('.app-sidebar .dropdown-toggle');

    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            const parent = this.parentElement;
            if (parent.classList.contains('active')) {
                parent.classList.remove('active');
            } else {
                // Close all other open dropdowns
                document.querySelectorAll('.app-sidebar .nav-dropdown.active').forEach(openDropdown => {
                    openDropdown.classList.remove('active');
                });
                parent.classList.add('active');
            }
        });
    });

    // Add keyboard navigation support
    const navLinks = document.querySelectorAll('.app-sidebar .nav-link');
    navLinks.forEach(link => {
        link.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (this.classList.contains('dropdown-toggle')) {
                    this.click();
                } else {
                    window.location.href = this.href;
                }
            }
        });
    });

    // Handle mobile menu toggle if needed
    const sidebar = document.querySelector('.app-sidebar');
    if (sidebar && window.innerWidth <= 767) {
        // Add mobile menu functionality
        sidebar.setAttribute('role', 'navigation');
        sidebar.setAttribute('aria-label', 'Main navigation');
    }
});
