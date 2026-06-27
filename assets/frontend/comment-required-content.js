(function () {
    'use strict';

    var settings = window.PurePressCommentRequiredContent || {};

    if (!settings.endpoint) {
        return;
    }

    function replaceBody(container, html, unlocked) {
        var body = container.querySelector('.purepress-comment-required__body');
        var loading = container.querySelector('.purepress-comment-required__loading');

        if (!body) {
            return;
        }

        body.innerHTML = html;
        container.classList.remove('is-loading');
        container.classList.toggle('is-unlocked', Boolean(unlocked));

        if (loading) {
            loading.hidden = true;
        }
    }

    function showError(container) {
        replaceBody(
            container,
            '<div class="purepress-comment-required__placeholder"><span class="purepress-comment-required__icon" aria-hidden="true"></span><span class="purepress-comment-required__text">' + escapeHtml(settings.errorText || '内容加载失败，请稍后重试。') + '</span></div>',
            false
        );
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function requestContent(container) {
        var token = container.getAttribute('data-token') || '';
        var loading = container.querySelector('.purepress-comment-required__loading');
        var body;
        var formData;

        if (!token || !window.fetch || !window.URLSearchParams) {
            return;
        }

        body = container.querySelector('.purepress-comment-required__body');

        if (body && loading) {
            container.classList.add('is-loading');
            loading.hidden = false;
        }

        formData = new URLSearchParams();
        formData.append('token', token);

        window.fetch(settings.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: formData.toString()
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (response) {
                if (!response || !response.data || typeof response.data.html !== 'string') {
                    showError(container);
                    return;
                }

                replaceBody(container, response.data.html, response.data.unlocked === true);
            })
            .catch(function () {
                showError(container);
            });
    }

    function boot() {
        var containers = document.querySelectorAll('[data-purepress-comment-required="1"]');

        containers.forEach(function (container) {
            requestContent(container);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
        return;
    }

    boot();
}());
