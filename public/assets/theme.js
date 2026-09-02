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

    var nav = document.querySelector('[data-app-nav]');
    var navTrigger = document.querySelector('[data-nav-open]');

    function setNavigation(open) {
        document.body.classList.toggle('nav-open', open);
        if (navTrigger !== null) {
            navTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (open && nav !== null) {
            var firstLink = nav.querySelector('a');
            if (firstLink !== null) {
                firstLink.focus();
            }
        }
    }

    document.querySelectorAll('[data-nav-open]').forEach(function (button) {
        button.addEventListener('click', function () { setNavigation(true); });
    });

    document.querySelectorAll('[data-nav-close]').forEach(function (button) {
        button.addEventListener('click', function () { setNavigation(false); });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setNavigation(false);
        }

        if (event.key === '/' && !event.metaKey && !event.ctrlKey && !event.altKey) {
            var activeTag = document.activeElement ? document.activeElement.tagName : '';
            if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(activeTag)) {
                var search = document.querySelector('input[type="search"]');
                if (search !== null) {
                    event.preventDefault();
                    search.focus();
                }
            }
        }
    });

    document.querySelectorAll('[data-copy-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = document.getElementById(button.getAttribute('data-copy-target'));
            if (target === null || !navigator.clipboard) {
                return;
            }
            navigator.clipboard.writeText(target.value || target.textContent || '').then(function () {
                var original = button.textContent;
                button.textContent = 'Copied';
                button.classList.add('is-success');
                window.setTimeout(function () {
                    button.textContent = original;
                    button.classList.remove('is-success');
                }, 1600);
            });
        });
    });

    var selectAll = document.querySelector('[data-select-all]');
    if (selectAll !== null) {
        var rowChecks = Array.prototype.slice.call(document.querySelectorAll('[data-row-select]'));
        selectAll.addEventListener('change', function () {
            rowChecks.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
        });
        rowChecks.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var checkedCount = rowChecks.filter(function (item) { return item.checked; }).length;
                selectAll.checked = rowChecks.length > 0 && checkedCount === rowChecks.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < rowChecks.length;
            });
        });
    }

    document.querySelectorAll('[data-confirm]').forEach(function (control) {
        control.addEventListener('click', function (event) {
            if (!window.confirm(control.getAttribute('data-confirm') || 'Are you sure?')) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-confirm-action]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var action = form.querySelector('[name="bulk_action"]');
            if (action !== null && action.value === form.getAttribute('data-confirm-action')) {
                if (!window.confirm('Delete the selected submissions? This cannot be undone.')) {
                    event.preventDefault();
                }
            }
        });
    });

    var savedFilters = document.querySelector('[data-saved-filters]');
    if (savedFilters !== null) {
        var filterStorageKey = 'formflow-saved-filters';
        var filterList = savedFilters.querySelector('[data-filter-list]');
        var filterName = savedFilters.querySelector('[data-filter-name]');

        function readFilters() {
            try {
                var parsed = JSON.parse(localStorage.getItem(filterStorageKey) || '[]');
                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
        }

        function writeFilters(filters) {
            try {
                localStorage.setItem(filterStorageKey, JSON.stringify(filters));
            } catch (error) {
            }
        }

        function renderFilters() {
            var filters = readFilters();
            filterList.textContent = '';

            if (filters.length === 0) {
                var empty = document.createElement('span');
                empty.className = 'muted';
                empty.textContent = 'No saved views yet.';
                filterList.appendChild(empty);
                return;
            }

            filters.forEach(function (filter, index) {
                var item = document.createElement('span');
                item.className = 'saved-filter-chip';
                var link = document.createElement('a');
                link.href = '/admin' + (filter.query ? '?' + filter.query : '');
                link.textContent = filter.name;
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Remove saved view ' + filter.name);
                remove.textContent = '×';
                remove.addEventListener('click', function () {
                    filters.splice(index, 1);
                    writeFilters(filters);
                    renderFilters();
                });
                item.appendChild(link);
                item.appendChild(remove);
                filterList.appendChild(item);
            });
        }

        savedFilters.querySelector('[data-save-filter]').addEventListener('click', function () {
            var name = filterName.value.trim();
            if (name === '') {
                filterName.focus();
                return;
            }
            var params = new URLSearchParams(window.location.search);
            params.delete('page');
            var filters = readFilters().filter(function (filter) { return filter.name !== name; });
            filters.unshift({ name: name, query: params.toString() });
            writeFilters(filters.slice(0, 10));
            filterName.value = '';
            renderFilters();
        });

        renderFilters();
    }

    var templateData = document.getElementById('form-template-data');
    if (templateData !== null) {
        var templates = {};
        try {
            templates = JSON.parse(templateData.textContent || '{}');
        } catch (error) {
        }

        document.querySelectorAll('[data-template-choice]').forEach(function (choice) {
            choice.addEventListener('change', function () {
                var values = templates[choice.value] || {};
                var applied = document.querySelector('[data-template-applied]');
                if (applied !== null) {
                    applied.value = '1';
                }
                Object.keys(values).forEach(function (name) {
                    var field = document.querySelector('[name="' + name + '"]');
                    if (field === null) {
                        return;
                    }
                    if (field.type === 'checkbox') {
                        field.checked = values[name] === '1' || values[name] === true;
                    } else {
                        field.value = values[name];
                    }
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
        });
    }

    document.querySelectorAll('[data-feature-toggle]').forEach(function (toggle) {
        var panel = document.getElementById(toggle.getAttribute('data-feature-toggle'));
        if (panel === null) {
            return;
        }

        function syncFeaturePanel() {
            panel.hidden = !toggle.checked;
            panel.querySelectorAll('input, select, textarea, button').forEach(function (control) {
                control.disabled = !toggle.checked;
            });
        }

        toggle.addEventListener('change', syncFeaturePanel);
        syncFeaturePanel();
    });
}());
