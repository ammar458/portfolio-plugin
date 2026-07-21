/**
 * Detect a video's real aspect ratio (as a CSS ratio string like "16/9") from
 * its URL. PHP encodes the server-detected ratio directly into the video URL
 * as a gvratio query param (ignored by the player) - read straight off the
 * rendered slide's own iframe src, this is unambiguous and needs no lookup
 * back to a trigger element.
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

    var lightbox = GLightbox({
        selector: '.glightbox',
        autoplayVideos: true,
        touchNavigation: true,
        loop: true,
        zoomable: true,
        arrows: true,
        videosWidth: '960px'
    });

    // GLightbox inserts the video iframe asynchronously (it defers slide
    // setup behind an internal script-load check, even when nothing actually
    // needs loading), so it may not exist yet when slide_after_load fires.
    // Applying the ratio from an href we read too early would silently and
    // permanently default to 16:9. Handle it whenever the iframe actually
    // shows up instead of assuming it's already there.
    function applyRatioFromSlide(slide) {
        var iframe = slide.querySelector('iframe');
        if (iframe) {
            useHref(slide, iframe.src || '');
            return;
        }
        var observer = new MutationObserver(function () {
            var found = slide.querySelector('iframe');
            if (found) {
                observer.disconnect();
                useHref(slide, found.src || '');
            }
        });
        observer.observe(slide, { childList: true, subtree: true });
        // Safety net: stop watching even if no iframe ever appears (e.g. an
        // image slide misclassified, or the slide gets closed/destroyed).
        setTimeout(function () { observer.disconnect(); }, 8000);
    }

    function useHref(slide, href) {
        var ratio = detectVideoRatio(href);
        applyVideoRatio(slide, ratio);

        // Server only had a guessed ratio for this Vimeo video - try to get
        // the real one from the browser instead.
        if (/[?&]gvguess=1/.test(href) && /vimeo\.com/i.test(href)) {
            fetchVimeoRatioClientSide(href, slide);
        }
    }

    // Run on every slide change (and on open)
    function onSlideReady(data) {
        var slide = data.slide || data.slideNode;
        if (!slide) return;
        if (!slide.classList.contains('gslide-video')) return;

        applyRatioFromSlide(slide);
    }

    lightbox.on('slide_after_load', onSlideReady);
    lightbox.on('slide_changed',    function(data) { onSlideReady(data.current || data); });

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
