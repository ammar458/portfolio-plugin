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

        // Hide grid immediately if there's a hash, to prevent flash
        if (hasHash) {
            $grid.addClass('hash-pending');
        }

        $grid.isotope({
            itemSelector: '.item-portafolio',
            layoutMode: 'fitRows'
        });

        // Click handler
        $(document).on('click', '.filtro', function(e) {
            e.preventDefault();
            var filtro = $(this).data('filtro') || $(this).attr('data-filtro');
            var href   = $(this).attr('href');

            $grid.isotope({ filter: filtro });
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
            if ($grid) $grid.removeClass('hash-pending').addClass('hash-ready');
            return false;
        }

        var $btn = $('.filtro[data-filtro=".' + hash + '"]');
        if (!$btn.length) {
            if ($grid) $grid.removeClass('hash-pending').addClass('hash-ready');
            return false;
        }

        if (!$grid || !$grid.data('isotope')) return false;

        $grid.isotope({ filter: '.' + hash });
        $('.filtro').removeClass('activo');
        $btn.addClass('activo');
        $grid.removeClass('hash-pending').addClass('hash-ready');
        return true;
    }

    // Start waiting as soon as this script runs
    waitForIsotope();

})(jQuery);