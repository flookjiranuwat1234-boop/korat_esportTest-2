(function () {
    function closeAlert(alert) {
        alert.classList.add('opacity-0', 'translate-y-1');
        window.setTimeout(function () {
            alert.remove();
        }, 220);
    }

    function initFlashMessages() {
        document.querySelectorAll('.flash-alert').forEach(function (alert) {
            var closeButton = alert.querySelector('.flash-alert-close');
            if (closeButton) {
                closeButton.addEventListener('click', function () {
                    closeAlert(alert);
                });
            }

            var autoHide = Number(alert.getAttribute('data-auto-hide') || 0);
            if (autoHide > 0) {
                window.setTimeout(function () {
                    if (document.body.contains(alert)) closeAlert(alert);
                }, autoHide);
            }
        });
    }

    function removeStrayNewlineText() {
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        var textNode;
        var strayNodes = [];

        while ((textNode = walker.nextNode())) {
            if (textNode.nodeValue.trim() === '`r`n') {
                strayNodes.push(textNode);
            }
        }

        strayNodes.forEach(function (node) {
            node.remove();
        });
    }

    function initPageCleanup() {
        removeStrayNewlineText();
        new MutationObserver(removeStrayNewlineText).observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    function hidePageScrollbar() {
        document.documentElement.style.scrollbarWidth = 'none';
        var style = document.createElement('style');
        style.textContent = [
            'html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; width: 0; height: 0; }',
            '@media (min-width: 640px) and (max-width: 1600px) {',
            '  html, body { overflow-x: hidden; }',
            '  main, .content, .admin-content { min-width: 0; max-width: 100%; }',
            '  img, video, svg { max-width: 100%; }',
            '  .public-nav, .admin-nav { gap: 1rem; padding-left: 1.25rem; padding-right: 1.25rem; }',
            '  .public-nav a, .admin-nav a, .admin-nav span { font-size: 0.78rem; }',
            '  .overflow-x-auto { max-width: 100%; overflow-x: auto; }',
            '  .overflow-x-auto > table { width: 100% !important; min-width: 0 !important; table-layout: fixed; }',
            '  .overflow-x-auto > table th, .overflow-x-auto > table td { white-space: normal; overflow-wrap: anywhere; word-break: break-word; }',
            '  .members-table { font-size: 0.72rem; }',
            '  .members-table th:nth-child(1), .members-table td:nth-child(1) { width: 15%; }',
            '  .members-table th:nth-child(2), .members-table td:nth-child(2) { width: 14%; }',
            '  .members-table th:nth-child(3), .members-table td:nth-child(3) { width: 8%; }',
            '  .members-table th:nth-child(4), .members-table td:nth-child(4) { width: 10%; }',
            '  .members-table th:nth-child(5), .members-table td:nth-child(5) { width: 13%; }',
            '  .members-table th:nth-child(6), .members-table td:nth-child(6) { width: 12%; white-space: nowrap; }',
            '  .members-table th:nth-child(7), .members-table td:nth-child(7) { width: 8%; }',
            '  .members-table th:nth-child(8), .members-table td:nth-child(8) { width: 8%; }',
            '  .members-table th:nth-child(9), .members-table td:nth-child(9) { width: 12%; }',
            '  .members-table td:nth-child(6) span { display: inline-flex; white-space: nowrap; min-width: max-content; }',
            '  .members-table td:last-child > div { gap: 0.35rem; }',
            '  .members-table-scroll .members-table td:last-child > div { flex-wrap: wrap; }',
            '}'
        ].join('');
        document.head.appendChild(style);

        var notebookTableStyle = document.createElement('style');
        notebookTableStyle.textContent = '@media (min-width: 1367px) and (max-width: 1600px) {' +
            '.members-table th:nth-child(7), .members-table td:nth-child(7),' +
            '.members-table th:nth-child(8), .members-table td:nth-child(8) { display: none; }' +
            '.members-table th:nth-child(1), .members-table td:nth-child(1) { width: 18%; }' +
            '.members-table th:nth-child(2), .members-table td:nth-child(2) { width: 17%; }' +
            '.members-table th:nth-child(3), .members-table td:nth-child(3) { width: 10%; }' +
            '.members-table th:nth-child(4), .members-table td:nth-child(4) { width: 12%; }' +
            '.members-table th:nth-child(5), .members-table td:nth-child(5) { width: 16%; }' +
            '.members-table th:nth-child(6), .members-table td:nth-child(6) { width: 13%; }' +
            '.members-table th:nth-child(9), .members-table td:nth-child(9) { width: 14%; }' +
            '}';
        document.head.appendChild(notebookTableStyle);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initFlashMessages();
            initPageCleanup();
            hidePageScrollbar();
        });
    } else {
        initFlashMessages();
        initPageCleanup();
        hidePageScrollbar();
    }
}());
