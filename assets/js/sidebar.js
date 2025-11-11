(() => {
  // Apply theme from localStorage (persisted across pages)
  const saved = localStorage.getItem('sos_theme');
  if (saved === 'light') {
    document.documentElement.setAttribute('data-theme', 'light');
  } else {
    document.documentElement.removeAttribute('data-theme');
  }

  const btnDark = document.getElementById('themeDark');
  const btnLight = document.getElementById('themeLight');
  if (btnDark) btnDark.addEventListener('click', () => {
    localStorage.setItem('sos_theme', 'dark');
    document.documentElement.removeAttribute('data-theme');
  });
  if (btnLight) btnLight.addEventListener('click', () => {
    localStorage.setItem('sos_theme', 'light');
    document.documentElement.setAttribute('data-theme', 'light');
  });
})();

