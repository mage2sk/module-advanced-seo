define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $root = $(element),
            $text = $root.find('[data-panth-crawl-progress-text]'),
            $bar = $root.find('[data-panth-crawl-progress-bar]'),
            labels = config.labels || {},
            interval = parseInt(config.interval, 10) || 5000,
            timer = null;

        if (!config.statusUrl) {
            return;
        }

        function fill(template, data) {
            return (template || '')
                .replace('%1', data.crawled)
                .replace('%2', data.max_pages)
                .replace('%3', data.queued);
        }

        function render(data) {
            var crawled = parseInt(data.crawled, 10) || 0,
                max = parseInt(data.max_pages, 10) || 0,
                percent = max > 0 ? Math.min(100, Math.round((crawled / max) * 100)) : 0;

            if (data.stopping) {
                $text.text(labels.stopping || '');
                return;
            }

            if (data.status === 'pending') {
                $text.text(labels.pending || '');
                $bar.css('width', '0%');
                return;
            }

            $text.text(fill(labels.running, {
                crawled: crawled,
                max_pages: max,
                queued: parseInt(data.queued, 10) || 0
            }));
            $bar.css('width', percent + '%');
        }

        function stop() {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        function poll() {
            $.ajax({
                url: config.statusUrl,
                dataType: 'json',
                cache: false
            }).done(function (data) {
                if (!data || data.error) {
                    stop();
                    return;
                }

                if (!data.active) {
                    stop();
                    window.location.reload();
                    return;
                }

                render(data);
            }).fail(function () {
                stop();
            });
        }

        timer = window.setInterval(poll, interval);
        poll();
    };
});
