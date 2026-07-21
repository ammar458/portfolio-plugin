/**
 * Detect video format from a URL and return a format key:
 *   'short'     - YouTube Shorts (9:16 vertical)
 *   'landscape' - Standard widescreen YouTube / Vimeo (16:9)
 *   'square'    - Instagram-style square (1:1)  — fallback for unknown
 */
function detectVideoFormat(href, triggerEl) {
    // Trust the PHP-stamped attribute first (already detected server-side)
    if (triggerEl) {
        var fmt = triggerEl.getAttribute('data-video-format');
        if (fmt) return fmt;
    }

    if (!href) return 'landscape';

    // YouTube Shorts
    if (/youtube\.com\/shorts\//i.test(href)) return 'short';
    if (/youtube\.com\/embed\//i.test(href) && /shorts/i.test(href)) return 'short';

    // Vimeo — default landscape (no reliable short-form detection without API)
    if (/vimeo\.com/i.test(href)) return 'landscape';

    // Standard YouTube
    if (/youtube\.com|youtu\.be/i.test(href)) return 'landscape';

    // Local video files — assume landscape
    if (/\.(mp4|webm|ogg|mov)(\?|$)/i.test(href)) return 'landscape';

    return 'landscape';
}

/**
 * Apply a CSS class to the active slide based on video format.
 * Removes any previously applied format class first.
 */
var FORMAT_CLASSES = ['gvideo-format-short', 'gvideo-format-landscape', 'gvideo-format-square'];

function applyVideoFormatClass(slide, format) {
    if (!slide) return;
    FORMAT_CLASSES.forEach(function(cls) { slide.classList.remove(cls); });
    slide.classList.add('gvideo-format-' + format);
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

        // Find the trigger element that opened this slide to read data-video-format
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

        var format = detectVideoFormat(href, triggerEl);
        applyVideoFormatClass(slide, format);
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
