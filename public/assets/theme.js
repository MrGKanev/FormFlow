(function () {
    var storageKey = 'formflow-theme';
    var root = document.documentElement;

    function getStoredTheme() {
        try {
            return localStorage.getItem(storageKey);
        } catch (error) {
            return null;
        }
    }

    function storeTheme(theme) {
        try {
            localStorage.setItem(storageKey, theme);
        } catch (error) {
        }
    }

    function setTheme(theme) {
        var nextTheme = theme === 'dark' ? 'dark' : 'light';
        root.dataset.theme = nextTheme;

        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            var nextLabel = nextTheme === 'dark' ? 'Light' : 'Dark';
            var label = button.querySelector('[data-theme-label]');

            button.setAttribute('aria-pressed', nextTheme === 'dark' ? 'true' : 'false');
            button.setAttribute('aria-label', 'Switch to ' + nextLabel.toLowerCase() + ' theme');

            if (label !== null) {
                label.textContent = nextLabel;
            }
        });
    }

    setTheme(getStoredTheme() || root.dataset.theme || 'light');

    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var nextTheme = root.dataset.theme === 'dark' ? 'light' : 'dark';

            storeTheme(nextTheme);
            setTheme(nextTheme);
        });
    });
}());
