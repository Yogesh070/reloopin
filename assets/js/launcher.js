/* global rlLauncher, jQuery */
/**
 * reLoopin Loyalty — Floating Launcher Widget
 *
 * Handles:
 *  - Open / close toggle (keyboard + outside-click)
 *  - Lazy AJAX data fetch on first open  (wp_send_json_success/error)
 *  - In-panel history view with pagination and back navigation
 *  - Copy-to-clipboard for the referral URL
 *
 * All <button> elements in the HTML carry type="button" to prevent
 * accidental form submission on WooCommerce pages (cart / checkout / account).
 */
(function ($) {
    'use strict';

    var btn   = document.getElementById('rl-launcher-btn');
    var panel = document.getElementById('rl-launcher-panel');

    if (!btn || !panel) { return; }

    // ── Top-level state elements ─────────────────────────────────────────────
    var stateLoading  = panel.querySelector('.rl-state-loading');
    var stateGuest    = panel.querySelector('.rl-state-guest');
    var stateLoggedin = panel.querySelector('.rl-state-loggedin');
    var stateHistory  = panel.querySelector('.rl-state-history');
    var stateError    = panel.querySelector('.rl-state-error');

    // ── History sub-elements ─────────────────────────────────────────────────
    var histLoading    = stateHistory && stateHistory.querySelector('.rl-history-loading');
    var histList       = stateHistory && stateHistory.querySelector('.rl-history-list');
    var histEmpty      = stateHistory && stateHistory.querySelector('.rl-history-empty');
    var histErr        = stateHistory && stateHistory.querySelector('.rl-history-err');
    var histPagination = stateHistory && stateHistory.querySelector('.rl-history-pagination');
    var histPrev       = stateHistory && stateHistory.querySelector('.rl-hist-prev');
    var histNext       = stateHistory && stateHistory.querySelector('.rl-hist-next');
    var histPageInfo   = stateHistory && stateHistory.querySelector('.rl-hist-page-info');

    var dataLoaded  = false;
    var historyPage = 1;
    var historySize = 10;

    // ── Display helpers ──────────────────────────────────────────────────────
    // Use explicit values; never rely on CSS cascade fallback.

    var STATE_DISPLAY = {
        flex:  ['rl-state-loading','rl-state-guest','rl-state-loggedin','rl-state-history'],
        block: ['rl-state-error'],
    };

    function showState(target) {
        [stateLoading, stateGuest, stateLoggedin, stateHistory, stateError].forEach(function (el) {
            if (!el) { return; }
            if (el === target) {
                // Restore the display type the element uses in its CSS rule
                el.style.display = (el === stateError) ? 'block' : 'flex';
            } else {
                el.style.display = 'none';
            }
        });
    }

    function showHistorySub(target) {
        // histLoading → flex, histList → flex, histEmpty/histErr → block (they are <p>)
        [[histLoading, 'flex'], [histList, 'flex'], [histEmpty, 'block'], [histErr, 'block']].forEach(function (pair) {
            var el   = pair[0];
            var type = pair[1];
            if (!el) { return; }
            el.style.display = (el === target) ? type : 'none';
        });
    }

    // ── Panel open / close ───────────────────────────────────────────────────

    function openPanel() {
        panel.removeAttribute('hidden');
        panel.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
        requestAnimationFrame(function () {
            panel.classList.add('rl-open');
        });
        if (!dataLoaded) { fetchData(); }
    }

    function closePanel() {
        panel.classList.remove('rl-open');
        btn.setAttribute('aria-expanded', 'false');
        panel.setAttribute('aria-hidden', 'true');
        panel.addEventListener('transitionend', function onEnd() {
            if (!panel.classList.contains('rl-open')) {
                panel.setAttribute('hidden', '');
            }
            panel.removeEventListener('transitionend', onEnd);
        });
    }

    btn.addEventListener('click', function () {
        panel.classList.contains('rl-open') ? closePanel() : openPanel();
    });

    var closeBtn = panel.querySelector('.rl-close');
    if (closeBtn) { closeBtn.addEventListener('click', closePanel); }

    document.addEventListener('click', function (e) {
        if (panel.classList.contains('rl-open') &&
            !panel.contains(e.target) &&
            !btn.contains(e.target)) {
            closePanel();
        }
    });

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.keyCode === 27) && panel.classList.contains('rl-open')) {
            closePanel();
            btn.focus();
        }
    });

    // ── Main data fetch (balance + referral URL) ─────────────────────────────

    function fetchData() {
        showState(stateLoading);
        $.ajax({
            url:      rlLauncher.ajax_url,
            type:     'POST',
            dataType: 'json',
            data: {
                action: 'reloopin_launcher_data',
                nonce:  rlLauncher.nonce,
            },
            success: function (response) {
                dataLoaded = true;
                if (!response || !response.success) {
                    showState(stateError);
                    return;
                }
                var data = response.data;
                if (!data.logged_in) {
                    showState(stateGuest);
                    return;
                }
                var nameEl = panel.querySelector('.rl-user-name');
                if (nameEl) { nameEl.textContent = data.name || ''; }

                var countEl = panel.querySelector('.rl-points-count');
                if (countEl) { countEl.textContent = Number(data.available_points || 0).toLocaleString(); }

                var refInput = panel.querySelector('.rl-ref-url');
                if (refInput && data.referral_url) { refInput.value = data.referral_url; }

                showState(stateLoggedin);
            },
            error: function () {
                dataLoaded = false;
                showState(stateError);
            },
        });
    }

    // ── History view ─────────────────────────────────────────────────────────

    function fetchHistory(page) {
        historyPage = page;
        showState(stateHistory);
        showHistorySub(histLoading);
        if (histPagination) { histPagination.style.display = 'none'; }

        $.ajax({
            url:      rlLauncher.ajax_url,
            type:     'POST',
            dataType: 'json',
            data: {
                action: 'reloopin_launcher_history',
                nonce:  rlLauncher.nonce,
                page:   page,
            },
            success: function (response) {
                if (!response || !response.success) {
                    showHistorySub(histErr);
                    return;
                }
                var data    = response.data;
                var results = data.results || [];

                if (results.length === 0) {
                    showHistorySub(histEmpty);
                    return;
                }

                if (histList) {
                    histList.innerHTML = '';
                    results.forEach(function (entry) {
                        var pts  = entry.points;
                        var sign = pts >= 0 ? '+' : '';
                        var cls  = pts >= 0 ? 'rl-positive' : 'rl-negative';
                        var note = entry.note
                            ? '<span class="rl-entry-note">' + esc(entry.note) + '</span>'
                            : '';
                        var row  = document.createElement('div');
                        row.className = 'rl-history-entry';
                        row.innerHTML =
                            '<div class="rl-entry-meta">' +
                                '<span class="rl-entry-type">' + esc(entry.type) + '</span>' +
                                '<span class="rl-entry-date">' + esc(entry.date) + '</span>' +
                                note +
                            '</div>' +
                            '<span class="rl-entry-points ' + cls + '">' +
                                sign + Number(pts).toLocaleString() +
                            '</span>';
                        histList.appendChild(row);
                    });
                }

                showHistorySub(histList);

                // Pagination
                historySize = data.page_size || 10;
                var total      = data.total || 0;
                var totalPages = historySize > 0 ? Math.ceil(total / historySize) : 1;

                if (totalPages > 1 && histPagination) {
                    if (histPageInfo) {
                        histPageInfo.textContent = 'Page ' + historyPage + ' of ' + totalPages;
                    }
                    if (histPrev) { histPrev.disabled = (historyPage <= 1); }
                    if (histNext) { histNext.disabled = (historyPage >= totalPages); }
                    histPagination.style.display = 'flex';
                }
            },
            error: function () {
                showHistorySub(histErr);
            },
        });
    }

    // "View history" button
    var viewHistBtn = panel.querySelector('.rl-view-history-btn');
    if (viewHistBtn) {
        viewHistBtn.addEventListener('click', function () {
            fetchHistory(1);
        });
    }

    // Back button
    var backBtn = stateHistory && stateHistory.querySelector('.rl-back-btn');
    if (backBtn) {
        backBtn.addEventListener('click', function () {
            showState(stateLoggedin);
        });
    }

    // Pagination
    if (histPrev) {
        histPrev.addEventListener('click', function () {
            if (historyPage > 1) { fetchHistory(historyPage - 1); }
        });
    }
    if (histNext) {
        histNext.addEventListener('click', function () {
            fetchHistory(historyPage + 1);
        });
    }

    // ── Copy referral URL ────────────────────────────────────────────────────

    var copyBtn      = panel.querySelector('.rl-copy-btn');
    var copyFeedback = panel.querySelector('.rl-copy-feedback');

    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var refInput = panel.querySelector('.rl-ref-url');
            if (!refInput || !refInput.value) { return; }

            function showCopied() {
                if (!copyFeedback) { return; }
                copyFeedback.textContent = 'Copied!';
                setTimeout(function () { copyFeedback.textContent = ''; }, 2500);
            }

            function fallbackCopy() {
                refInput.select();
                try { document.execCommand('copy'); showCopied(); } catch (e) { /* silent */ }
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(refInput.value).then(showCopied).catch(fallbackCopy);
            } else {
                fallbackCopy();
            }
        });
    }

    // ── Utility ──────────────────────────────────────────────────────────────

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

}(jQuery));
