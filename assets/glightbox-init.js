/**
 * Detect a video's real aspect ratio (as a CSS ratio string like "16/9") from
 * its href. PHP encodes the server-detected ratio directly into the trigger's
 * URL as a gvratio query param.
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

/**
 * Vimeo and YouTube both accept simple postMessage commands without needing
 * their full player SDK loaded. Used to stop a slide's video the instant the
 * user navigates away from it: GLightbox only auto-pauses videos routed
 * through its bundled Plyr player, which video slides here deliberately skip
 * (see initGLightbox) - without this they'd just keep playing, muted, behind
 * whatever slide comes next.
 */
function pauseVideoIframe(slide) {
    if (!slide) return;
    var iframe = slide.querySelector('iframe');
    if (!iframe || !iframe.contentWindow) return;

    var src = iframe.src || '';
    if (/vimeo\.com/i.test(src)) {
        iframe.contentWindow.postMessage(JSON.stringify({ method: 'pause' }), '*');
    } else if (/youtube(-nocookie)?\.com/i.test(src)) {
        iframe.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'pauseVideo', args: [] }), '*');
    }
}

function initGLightbox() {
    if (typeof GLightbox !== 'function') return;

    // Video slides are given data-type="external" (see shortcode-galeria.php)
    // instead of "video" - GLightbox always hands "video"-type slides to its
    // bundled Plyr player regardless of the plyr.enabled setting (this build
    // never actually reads that flag), which means an extra third-party
    // script fetch plus a round trip through Vimeo's oEmbed API before
    // anything renders, and it rebuilds the iframe src from scratch,
    // dropping our gvratio marker and any privacy hash. "external" renders
    // as a plain iframe using our href exactly as given - faster to first
    // paint and no query-string stripping.
    var lightbox = GLightbox({
        selector: '.glightbox',
        autoplayVideos: true,
        touchNavigation: true,
        loop: true,
        zoomable: true,
        arrows: true
    });

    // Read the ratio off the trigger href and stamp it onto the slide as a
    // CSS custom property - our own CSS uses it to size the box to the
    // video's real aspect ratio (see estilos.css).
    function onSlideBeforeLoad(data) {
        var href = (data.slideConfig && data.slideConfig.href) || (data.trigger && data.trigger.href) || '';
        if (!/[?&]gvratio=/.test(href)) return;

        var ratio = detectVideoRatio(href);
        applyVideoRatio(data.slide, ratio);

        // Server only had a guessed ratio for this Vimeo video - try to get
        // the real one from the browser instead.
        if (/[?&]gvguess=1/.test(href) && /vimeo\.com/i.test(href)) {
            fetchVimeoRatioClientSide(href, data.slide);
        }
    }

    lightbox.on('slide_before_load', onSlideBeforeLoad);

    lightbox.on('slide_before_change', function (data) {
        if (data && data.prev) pauseVideoIframe(data.prev.slide);
    });

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
