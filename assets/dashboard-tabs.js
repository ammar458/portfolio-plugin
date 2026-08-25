document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('.portfolio-dashboard');
    if (!root) return;

    var tabs  = root.querySelectorAll('.pd-tab');
    var views = root.querySelectorAll('.pd-view');

    // Mirrors [portfolio_filtros]'s own hash convention (#all, #<slug>) so
    // tabs are shareable/bookmarkable links like .../our-work/#web-design.
    function filterForHash(hash) {
        hash = (hash || '').replace('#', '').trim();
        return (!hash || hash === 'all') ? '*' : hash;
    }

    function hashForFilter(filter) {
        return filter === '*' ? '#all' : '#' + filter;
    }

    function activate(filter, updateHash) {
        var hasMatch = false;
        tabs.forEach(function (tab) {
            if (tab.getAttribute('data-pd-filter') === filter) hasMatch = true;
        });

        // Unknown/stale hash (category renamed or removed) - fall back to
        // ALL instead of leaving every view hidden.
        if (!hasMatch) filter = '*';

        var visibleView = null;
        tabs.forEach(function (tab) {
            tab.classList.toggle('is-active', tab.getAttribute('data-pd-filter') === filter);
        });
        views.forEach(function (view) {
            var isMatch = view.getAttribute('data-pd-view') === filter;
            view.hidden = !isMatch;
            if (isMatch) visibleView = view;
        });

        if (updateHash && history.pushState) {
            history.pushState(null, null, hashForFilter(filter));
        }

        if (typeof window.refreshPortfolioLightbox === 'function') {
            var triggers = visibleView ? visibleView.querySelectorAll('.glightbox') : [];
            window.refreshPortfolioLightbox(triggers);
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            activate(tab.getAttribute('data-pd-filter'), true);
        });
    });

    // Back/forward navigation between tab states.
    window.addEventListener('hashchange', function () {
        activate(filterForHash(window.location.hash), false);
    });

    // Apply whatever tab the URL points at on load (e.g. a deep link to
    // .../our-work/#web-design), without adding a redundant history entry.
    activate(filterForHash(window.location.hash), false);

    // Re-run once the page has settled - glightbox-init.js's own initial
    // pass runs after this and (having no notion of tabs) marks every
    // dashboard item active, including ones sitting in still-hidden
    // category views. Re-asserting here restores the correct active set.
    setTimeout(function () { activate(filterForHash(window.location.hash), false); }, 50);
});
