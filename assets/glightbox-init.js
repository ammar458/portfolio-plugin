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
