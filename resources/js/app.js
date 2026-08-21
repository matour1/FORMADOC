// Thème sombre/clair (design system FORMADOC)
document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('formadoc-theme');
    if (saved === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
});

// Toggle de thème (bouton .theme-toggle)
document.addEventListener('click', (e) => {
    const toggle = e.target.closest('.theme-toggle');
    if (!toggle) return;

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const next = isDark ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next === 'dark' ? 'dark' : 'light');
    localStorage.setItem('formadoc-theme', next);
});

// Sidebar mobile (bouton .navbar-menu-toggle)
document.addEventListener('click', (e) => {
    const toggle = e.target.closest('.navbar-menu-toggle');
    if (!toggle) return;

    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    sidebar?.classList.toggle('open');
    overlay?.classList.toggle('open');
});

// Fermeture sidebar mobile via overlay
document.addEventListener('click', (e) => {
    if (e.target.classList?.contains('sidebar-overlay')) {
        document.querySelector('.sidebar')?.classList.remove('open');
        e.target.classList.remove('open');
    }
});
