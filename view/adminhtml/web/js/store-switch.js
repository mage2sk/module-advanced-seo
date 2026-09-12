define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        $(element).on('change', function () {
            var form = $(element).closest('form');

            if (form.length) {
                form.get(0).submit();
            }
        });
    };
});
