(function($) {

    var $grid;
    var hasHash = window.location.hash && window.location.hash !== '#all';

    // Wait until Isotope AND jQuery are both available, then init
    function waitForIsotope() {
        if (typeof $.fn.isotope !== 'function') {
            setTimeout(waitForIsotope, 100);
            return;
        }
        initPortfolioFilters();
    }

    function initPortfolioFilters() {

        $grid = $('.grid-portafolio');
        if (!$grid.length) return;

        // Remove category buttons that have no matching cards in the rendered
        // grid. This also catches posts that WordPress counts for a term but
        // the gallery skips because required image/video data is missing.
        $('.filtro[data-filtro]').each(function() {
            var selector = $(this).attr('data-filtro');
            if (!selector || selector === '*') return;

            if ($grid.find(selector).length === 0) {
                $(this).remove();
            }
        });

        function refreshFilteredLightbox() {
            if (typeof window.refreshPortfolioLightbox !== 'function') return;

            var isotope = $grid.data('isotope');
            var activeTriggers = [];

            if (isotope && isotope.filteredItems) {
                isotope.filteredItems.forEach(function(item) {
                    $(item.element).find('.glightbox').each(function() {
                        activeTriggers.push(this);
                    });
                });
            } else {
                $grid.find('.item-portafolio:visible .glightbox').each(function() {
                    activeTriggers.push(this);
                });
            }

            window.refreshPortfolioLightbox(activeTriggers);
        }

        // Isotope updates filteredItems for every category change. Rebuild the
        // lightbox from that exact list so hidden topics cannot enter the slider.
        $grid.on('arrangeComplete.portfolioLightbox', refreshFilteredLightbox);

        // Hide grid immediately if there's a hash, to prevent flash
        if (hasHash) {
            $grid.addClass('hash-pending');
        }

        $grid.isotope({
            itemSelector: '.item-portafolio',
            layoutMode: 'fitRows'
        });

        // Ensure the initial ALL view (or the first hash-filtered view) uses
        // the correct lightbox element list even if arrangeComplete fired early.
        setTimeout(refreshFilteredLightbox, 50);

        // Click handler
        $(document).on('click', '.filtro', function(e) {
            e.preventDefault();
            var filtro = $(this).data('filtro') || $(this).attr('data-filtro');
            var href   = $(this).attr('href');

            $grid.isotope({ filter: filtro });
            refreshFilteredLightbox();
            $('.filtro').removeClass('activo');
            $(this).addClass('activo');

            if (history.pushState) {
                history.pushState(null, null, href);
            }
        });

        // Back/forward
        $(window).on('hashchange', function() {
            applyHashFilter();
        });

        // Poll until filter applies successfully
        if (hasHash) {
            var tries = 0;
            var poll = setInterval(function() {
                tries++;
                if (applyHashFilter() || tries >= 30) {
                    clearInterval(poll);
                }
            }, 100);
        }
    }

    function applyHashFilter() {
        var hash = (window.location.hash || '').replace('#', '').trim();

        if (!hash || hash === 'all') {
            if (!$grid || !$grid.data('isotope')) return false;

            $grid.isotope({ filter: '*' });

            var allIsotope = $grid.data('isotope');
            var allTriggers = [];
            if (allIsotope && allIsotope.filteredItems) {
                allIsotope.filteredItems.forEach(function(item) {
                    $(item.element).find('.glightbox').each(function() {
                        allTriggers.push(this);
                    });
                });
            }
            if (typeof window.refreshPortfolioLightbox === 'function') {
                window.refreshPortfolioLightbox(allTriggers);
            }

            $('.filtro').removeClass('activo');
            $('.filtro[data-filtro="*"]').addClass('activo');
            $grid.removeClass('hash-pending').addClass('hash-ready');
            return true;
        }

        var $btn = $('.filtro[data-filtro=".' + hash + '"]');
        if (!$btn.length) {
            if ($grid) $grid.removeClass('hash-pending').addClass('hash-ready');
            return false;
        }

        if (!$grid || !$grid.data('isotope')) return false;

        $grid.isotope({ filter: '.' + hash });
        var isotope = $grid.data('isotope');
        var activeTriggers = [];
        if (isotope && isotope.filteredItems) {
            isotope.filteredItems.forEach(function(item) {
                $(item.element).find('.glightbox').each(function() {
                    activeTriggers.push(this);
                });
            });
        }
        if (typeof window.refreshPortfolioLightbox === 'function') {
            window.refreshPortfolioLightbox(activeTriggers);
        }
        $('.filtro').removeClass('activo');
        $btn.addClass('activo');
        $grid.removeClass('hash-pending').addClass('hash-ready');
        return true;
    }

    // Start waiting as soon as this script runs
    waitForIsotope();

})(jQuery);