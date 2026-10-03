/**
 * Panth AdvancedSEO -- Live SERP Preview UI Component.
 *
 * Extends uiComponent and observes the meta_title, meta_description, and
 * url_key fields on the product / category edit form. Renders a real-time
 * Google-style SERP snippet so administrators can judge title/description
 * length and readability before saving.
 *
 * Pixel-width estimation mimics Google's rendering of the ~20 px Arial title
 * font; it uses a per-character lookup with wide/narrow/average buckets.
 */
define([
    'uiComponent',
    'uiRegistry',
    'ko'
], function (Component, registry, ko) {
    'use strict';

    /**
     * Approximate pixel width of a string rendered in Google's SERP title font
     * (~20 px Arial). Uses three buckets: wide, narrow, and average characters.
     *
     * @param {string} text
     * @returns {number}
     */
    function approxPixelWidth(text) {
        var width = 0,
            i, ch;

        for (i = 0; i < text.length; i++) {
            ch = text.charAt(i);
            if ('mwMW@'.indexOf(ch) >= 0) {
                width += 11.5;
            } else if ('ilItjf.,:;\'|![](){}'.indexOf(ch) >= 0) {
                width += 4;
            } else if (ch === ' ') {
                width += 4.5;
            } else {
                width += 7.2;
            }
        }

        return width;
    }

    /**
     * Truncate text by pixel width, breaking at the last word boundary.
     *
     * @param {string} text
     * @param {number} maxPx
     * @returns {string}
     */
    function truncateByPx(text, maxPx) {
        if (approxPixelWidth(text) <= maxPx) {
            return text;
        }

        var out = '',
            i;

        for (i = 0; i < text.length; i++) {
            if (approxPixelWidth(out + text.charAt(i)) > maxPx) {
                return out.replace(/\s+\S*$/, '') + '\u2026';
            }
            out += text.charAt(i);
        }

        return out;
    }

    /**
     * Truncate text by character count, breaking at the last word boundary.
     *
     * @param {string} text
     * @param {number} max
     * @returns {string}
     */
    function truncateByChars(text, max) {
        if (text.length <= max) {
            return text;
        }

        return text.substring(0, max).replace(/\s+\S*$/, '') + '\u2026';
    }

    return Component.extend({
        defaults: {
            /** @type {string} Knockout template alias */
            template: 'Panth_AdvancedSEO/serp-preview',

            /** @type {string} Store base URL injected via PHP plugin */
            baseUrl: '',

            /** @type {string} "product" or "category" */
            entityType: 'product',

            /** @type {number} Max pixel width for title (Google SERP) */
            titleMaxPx: 580,

            /** @type {number} Soft character limit for title */
            titleMaxChars: 60,

            /** @type {number} Soft character limit for description */
            descriptionMaxChars: 160,

            /** @type {number} Max pixel width for description (Google SERP) */
            descriptionMaxPx: 920,

            /** @type {Object} Internal observable data */
            serpTitle: '',
            serpUrl: '',
            serpDescription: '',
            titleCharCount: 0,
            descCharCount: 0,
            titlePxPercent: 0,
            descPxPercent: 0,
            titleOverLimit: false,
            descOverLimit: false,
            rawTitle: '',
            rawDescription: '',
            rawUrlKey: ''
        },

        /** @inheritdoc */
        initObservable: function () {
            this._super();

            this.observe([
                'serpTitle',
                'serpUrl',
                'serpDescription',
                'titleCharCount',
                'descCharCount',
                'titlePxPercent',
                'descPxPercent',
                'titleOverLimit',
                'descOverLimit',
                'rawTitle',
                'rawDescription',
                'rawUrlKey'
            ]);

            return this;
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
            this._bindFields();
            this._updatePreview();

            return this;
        },

        /**
         * Bind to meta_title, meta_description, and url_key fields via
         * uiRegistry. Uses insertChild-style waiting so the preview works
         * regardless of field load order.
         *
         * @private
         */
        _bindFields: function () {
            var self = this;

            /*
             * Product form indices use "meta_title", "meta_description", "url_key".
             * Category form uses the same field names but with an underscore-style
             * data scope. We search by index which works for both.
             */
            var titleIndices = ['meta_title'];
            var descIndices = ['meta_description'];
            var urlKeyIndices = ['url_key'];

            this._watchField(titleIndices, function (value) {
                self.rawTitle(value || '');
                self._updatePreview();
            });

            this._watchField(descIndices, function (value) {
                self.rawDescription(value || '');
                self._updatePreview();
            });

            this._watchField(urlKeyIndices, function (value) {
                self.rawUrlKey(value || '');
                self._updatePreview();
            });
        },

        /**
         * Watch a form field by its index name. Subscribes to the field's
         * value observable once it appears in the UI registry.
         *
         * @param {string[]} indices
         * @param {Function} callback
         * @private
         */
        _watchField: function (indices, callback) {
            var resolved = false;

            indices.forEach(function (index) {
                if (resolved) {
                    return;
                }

                registry.async(function (component) {
                    return component.index === index;
                })(function (field) {
                    if (resolved) {
                        return;
                    }
                    resolved = true;

                    if (typeof field.value === 'function') {
                        /* Set initial value */
                        callback(field.value());

                        /* Subscribe to changes */
                        field.value.subscribe(function (newVal) {
                            callback(newVal);
                        });
                    }
                });
            });
        },

        /**
         * Recalculate all SERP preview observables from the raw field values.
         *
         * @private
         */
        _updatePreview: function () {
            var title = this.rawTitle ? this.rawTitle() : '';
            var description = this.rawDescription ? this.rawDescription() : '';
            var urlKey = this.rawUrlKey ? this.rawUrlKey() : '';

            /* Title */
            var titlePx = approxPixelWidth(title);
            var titlePct = Math.min(100, Math.round((titlePx / this.titleMaxPx) * 100));

            this.serpTitle(title ? truncateByPx(title, this.titleMaxPx) : 'Page Title');
            this.titleCharCount(title.length);
            this.titlePxPercent(titlePct);
            this.titleOverLimit(titlePx > this.titleMaxPx);

            /* URL */
            var urlPath = urlKey ? urlKey.replace(/\s+/g, '-').toLowerCase() : '';
            var displayUrl = this.baseUrl;
            if (urlPath) {
                displayUrl += ' \u203A ' + urlPath.replace(/\//g, ' \u203A ');
            }
            this.serpUrl(displayUrl);

            /* Description */
            var descPx = approxPixelWidth(description);
            var descPct = Math.min(100, Math.round((descPx / this.descriptionMaxPx) * 100));

            this.serpDescription(
                description
                    ? truncateByChars(description, this.descriptionMaxChars)
                    : 'Your meta description will appear here. Make it compelling to improve click-through rate.'
            );
            this.descCharCount(description.length);
            this.descPxPercent(descPct);
            this.descOverLimit(description.length > this.descriptionMaxChars);
        },

        /**
         * Returns a CSS-compatible width string for the title progress bar.
         *
         * @returns {string}
         */
        getTitleBarWidth: function () {
            return this.titlePxPercent() + '%';
        },

        /**
         * Returns a CSS-compatible width string for the description progress bar.
         *
         * @returns {string}
         */
        getDescBarWidth: function () {
            return this.descPxPercent() + '%';
        },

        /**
         * Returns the CSS class for the title progress bar colour.
         *
         * @returns {string}
         */
        getTitleBarClass: function () {
            var pct = this.titlePxPercent();
            if (pct > 100) {
                return 'panth-serp-bar-over';
            }
            if (pct > 85) {
                return 'panth-serp-bar-warn';
            }
            return 'panth-serp-bar-ok';
        },

        /**
         * Returns the CSS class for the description progress bar colour.
         *
         * @returns {string}
         */
        getDescBarClass: function () {
            var pct = this.descPxPercent();
            if (pct > 100) {
                return 'panth-serp-bar-over';
            }
            if (pct > 85) {
                return 'panth-serp-bar-warn';
            }
            return 'panth-serp-bar-ok';
        }
    });
});
