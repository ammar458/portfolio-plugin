document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('.portfolio-dashboard');
    if (!root) return;

    var tabs  = root.querySelectorAll('.pd-tab');
    var views = root.querySelectorAll('.pd-view');

    function activate(filter) {
        var visibleView = null;

        tabs.forEach(function (tab) {
            tab.classList.toggle('is-active', tab.getAttribute('data-pd-filter') === filter);
        });

        views.forEach(function (view) {
            var isMatch = view.getAttribute('data-pd-view') === filter;
            view.hidden = !isMatch;
            if (isMatch) visibleView = view;
        });

        if (typeof window.refreshPortfolioLightbox === 'function') {
            var triggers = visibleView ? visibleView.querySelectorAll('.glightbox') : [];
            window.refreshPortfolioLightbox(triggers);
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            activate(tab.getAttribute('data-pd-filter'));
        });
    });

    // Re-assert the "ALL" view's triggers as the active lightbox set once the
    // page has settled - glightbox-init.js's own initial pass runs first and
    // (having no notion of tabs) marks every dashboard item active, including
    // ones sitting in still-hidden category views.
    setTimeout(function () { activate('*'); }, 50);
});
