/**
 * Panth AdvancedSEO — live SERP preview.
 *
 * Binds to any [name="meta_title"] and [name="meta_description"] inputs on the
 * page and updates the SERP preview card live as the user types. Truncates
 * values to approximate Google SERP limits so the admin sees what Google will.
 */
define(['jquery'], function ($) {
    'use strict';

    var TITLE_MAX_PX = 580;
    var DESC_MAX_CHARS = 156;

    function approxPixelWidth(text) {
        var w = 0, i;
        for (i = 0; i < text.length; i++) {
            var c = text.charAt(i);
            if ('mwMW'.indexOf(c) >= 0) { w += 11.5; }
            else if ('ilItjf.,:;\''.indexOf(c) >= 0) { w += 4; }
            else { w += 7.2; }
        }
        return w;
    }

    function truncateByPx(text, maxPx) {
        var out = '', i;
        for (i = 0; i < text.length; i++) {
            if (approxPixelWidth(out + text.charAt(i)) > maxPx) {
                return out.replace(/\s+\S*$/, '') + '…';
            }
            out += text.charAt(i);
        }
        return out;
    }

    function truncateByChars(text, max) {
        if (text.length <= max) { return text; }
        return text.substring(0, max).replace(/\s+\S*$/, '') + '…';
    }

    return function () {
        var $root = $('[data-panth-seo-serp]');
        var $title = $root.find('[data-panth-seo-serp-title]');
        var $desc = $root.find('[data-panth-seo-serp-description]');

        function update() {
            var t = ($('input[name*="meta_title"]').val() || '').toString();
            var d = ($('textarea[name*="meta_description"]').val() || '').toString();
            if (t) { $title.text(truncateByPx(t, TITLE_MAX_PX)); }
            if (d) { $desc.text(truncateByChars(d, DESC_MAX_CHARS)); }
        }

        $(document).on('input change', 'input[name*="meta_title"], textarea[name*="meta_description"]', update);
        update();
    };
});
