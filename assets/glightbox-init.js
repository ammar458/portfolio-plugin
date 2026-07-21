/**
 * Detect a video's real aspect ratio (as a CSS ratio string like "16/9")
 * so the lightbox can size itself to the exact video instead of a fixed bucket.
 */
function detectVideoRatio(href, triggerEl) {
    // Trust the PHP-stamped attribute first (exact ratio detected server-side,
    // e.g. from a pasted iframe's width/height)
    if (triggerEl) {
        var ratio = triggerEl.getAttribute('data-video-ratio');
        if (ratio) return ratio;
    }

    if (!href) return '16/9';

    // YouTube Shorts
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

    // Run on every slide change (and on open)
    function onSlideReady(data) {
        var slide = data.slide || data.slideNode;
        if (!slide) return;
        if (!slide.classList.contains('gslide-video')) return;

        // Find the trigger element that opened this slide to read data-video-ratio
        var index = data.index != null ? data.index : (data.slideIndex != null ? data.slideIndex : null);
        var triggerEl = null;
        if (index !== null) {
            var els = document.querySelectorAll('.glightbox');
            if (els[index]) triggerEl = els[index];
        }

        // Get href from the trigger or from the video iframe src already in the DOM
        var href = '';
        if (triggerEl) href = triggerEl.getAttribute('href') || '';
        if (!href) {
            var iframe = slide.querySelector('iframe');
            if (iframe) href = iframe.src || '';
        }

        var ratio = detectVideoRatio(href, triggerEl);
        applyVideoRatio(slide, ratio);

        // Server only had a guessed ratio for this Vimeo video - try to get
        // the real one from the browser instead.
        if (triggerEl && triggerEl.getAttribute('data-video-ratio-guess') === '1' && /vimeo\.com/i.test(href)) {
            fetchVimeoRatioClientSide(href, slide);
        }
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
