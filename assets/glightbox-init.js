/**
 * Detect a video's real aspect ratio (as a CSS ratio string like "16/9") from
 * its href. PHP encodes the server-detected ratio directly into the trigger's
 * URL as a gvratio query param. This must be read off the *trigger* element's
 * href (via slide_before_load), not the rendered slide's iframe src: GLightbox
 * always hands "video"-type slides to its bundled Plyr player (see
 * initGLightbox below), which rebuilds the iframe src from scratch - keeping
 * only the numeric video ID - so gvratio never survives onto the actual
 * iframe.
 */
function detectVideoRatio(href) {
    if (!href) return '16/9';

    var ratioMatch = href.match(/[?&]gvratio=([^&]+)/);
    if (ratioMatch) return decodeURIComponent(ratioMatch[1]);

    // Fallback heuristics only apply to hrefs without a gvratio param
    if (/youtube\.com\/shorts\//i.test(href)) return '9/16';
    if (/youtube\.com\/embed\//i.test(href) && /shorts/i.test(href)) return '9/16';

    return '16/9';
}

/**
 * Stamp the active slide with a CSS custom property holding its aspect
 * ratio; CSS reads --gvideo-ratio to size the box to fit exactly.
 */
function applyVideoRatio(slide, ratio) {
    if (!slide) return;
    slide.style.setProperty('--gvideo-ratio', ratio);
}

/**
 * Client-side fallback for when the server couldn't confirm a Vimeo video's
 * real ratio (e.g. its outbound oEmbed request got blocked on that host).
 * The visitor's own browser calls Vimeo's oEmbed API directly - it isn't
 * subject to the server's network restrictions - and corrects the guessed
 * ratio once the real one comes back. Cached in-memory per page view so
 * reopening the same slide doesn't refetch.
 */
var _vimeoRatioCache = {};

function fetchVimeoRatioClientSide(href, slide) {
    var idMatch = href.match(/vimeo\.com\/(?:video\/)?(\d+)/i);
    if (!idMatch) return;

    var pageUrl = 'https://vimeo.com/' + idMatch[1];
    var hashMatch = href.match(/[?&]h=([0-9a-zA-Z]+)/);
    if (hashMatch) pageUrl += '/' + hashMatch[1];

    if (_vimeoRatioCache[pageUrl]) {
        applyVideoRatio(slide, _vimeoRatioCache[pageUrl]);
        return;
    }

    fetch('https://vimeo.com/api/oembed.json?url=' + encodeURIComponent(pageUrl))
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (data && data.width && data.height) {
                var ratio = data.width + '/' + data.height;
                _vimeoRatioCache[pageUrl] = ratio;
                applyVideoRatio(slide, ratio);
            }
        })
        .catch(function () { /* server's guessed fallback stays in place */ });
}

function initGLightbox() {
    if (typeof GLightbox !== 'function') return;

    // Note: plyr.enabled is passed for documentation/forward-compat, but this
    // bundled GLightbox build never actually reads that flag - "video"-type
    // slides always go through its Plyr player regardless. The ratio fix
    // below (slide_before_load) works with that reality rather than around it.
    var lightbox = GLightbox({
        selector: '.glightbox',
        autoplayVideos: true,
        touchNavigation: true,
        loop: true,
        zoomable: true,
        arrows: true,
        videosWidth: '960px',
        plyr: { enabled: false }
    });

    // Read the ratio off the *trigger* href before Plyr gets constructed for
    // this slide (slide_before_load fires ~200ms ahead of it for video
    // slides - plenty of time). Two things read this ratio:
    //  - our own CSS, via the --gvideo-ratio custom property on the slide
    //  - GLightbox's internal resize() math and Plyr's initial box shape,
    //    both of which read settings.plyr.config.ratio (otherwise stuck on
    //    Plyr's hardcoded default of "16:9", which is exactly why portrait
    //    videos were being boxed as landscape and cropped to fit).
    function onSlideBeforeLoad(data) {
        var slideConfig = data.slideConfig || {};
        if (slideConfig.type !== 'video') return;

        var href = slideConfig.href || (data.trigger && data.trigger.href) || '';
        var ratio = detectVideoRatio(href);

        applyVideoRatio(data.slide, ratio);
        lightbox.settings.plyr.config.ratio = ratio.replace('/', ':');

        // Server only had a guessed ratio for this Vimeo video - try to get
        // the real one from the browser instead.
        if (/[?&]gvguess=1/.test(href) && /vimeo\.com/i.test(href)) {
            fetchVimeoRatioClientSide(href, data.slide);
        }
    }

    lightbox.on('slide_before_load', onSlideBeforeLoad);

    return lightbox;
}

var _glightboxInstance = null;

document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function() { _glightboxInstance = initGLightbox(); }, 5);
});

// Re-init if Elementor or AJAX injects content dynamically
var _glightboxObserver = new MutationObserver(function() {
    if (!document.querySelector('.glightbox')) return;
    if (_glightboxInstance) return; // already running
    _glightboxInstance = initGLightbox();
});
_glightboxObserver.observe(document.body, { childList: true, subtree: true });
