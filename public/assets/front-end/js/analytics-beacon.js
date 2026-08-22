/*
 | First-party analytics beacon.
 |
 | The server already records every page load, so this file exists only for the things a page load
 | cannot see:
 |
 |  1. The product filter navigates with history.pushState and a jQuery request. Filtering,
 |     sorting and changing a price range are real page changes that return JSON, so the server
 |     counts them as an XHR and — correctly — not as a pageview. Without this they are simply
 |     lost, and on a catalogue site that is a large share of all navigation.
 |  2. Interactions that never navigate and write nothing on the server, should the theme ever add
 |     one. The delegated [data-analytics] handler below is that extension point; today nothing in
 |     the theme carries the attribute, and an event the server already records is refused here
 |     rather than counted twice.
 |
 | Deliberately small, dependency-free and defensive. It runs on every storefront page of a live
 | shop, so it must never throw into a customer's console, never block a click, and never delay a
 | page. Everything is wrapped, everything is queued, and the queue is flushed with sendBeacon so
 | the browser can post it during unload without holding the page open.
 |
 | It sends NOTHING about the person. No form values, no input contents, no scroll heatmap, no
 | fingerprint — an event name, an entity id and a normalised path.
 */
(function () {
    'use strict';

    var root = document.getElementById('analytics-beacon');
    if (!root) return;

    var endpoint = root.getAttribute('data-endpoint');
    if (!endpoint) return;

    var queue = [];
    var flushTimer = null;

    // The server allow-list, mirrored here so a typo never becomes a silent no-op that only shows
    // up as a missing report weeks later.
    //
    // One entry, on purpose. Anything the server already records — a product view, a wishlist add,
    // a checkout start, the cart page, a compare add — is deliberately absent: sending it from here
    // as well would count it twice, and deduplication would not catch it because the two sides
    // disagree about the path. What is left is the filter's pushState navigation, which returns
    // JSON and is correctly not a pageview to the server.
    var ALLOWED = ['product_list_viewed'];

    function push(name, payload) {
        try {
            if (ALLOWED.indexOf(name) === -1) return;

            var event = {name: name};
            if (payload) {
                if (payload.entityType) event.entity_type = String(payload.entityType).slice(0, 24);
                if (payload.entityId) event.entity_id = String(payload.entityId).slice(0, 18);
                if (payload.properties) event.properties = payload.properties;
                if (payload.dedupeKey) event.dedupe_key = String(payload.dedupeKey).slice(0, 64);
            }
            event.path = location.pathname + location.search;

            queue.push(event);

            // A cap, so a runaway loop on a page cannot turn into a flood of requests.
            if (queue.length >= 20) {
                flush();
                return;
            }

            // Batched: several events usually happen together (a filter changes the list, the
            // list is a pageview), and one request is cheaper for the customer than three.
            if (flushTimer) clearTimeout(flushTimer);
            flushTimer = setTimeout(flush, 1200);
        } catch (error) {
            // Analytics never breaks a shop.
        }
    }

    function flush() {
        try {
            if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; }
            if (!queue.length) return;

            var body = JSON.stringify({events: queue});
            queue = [];

            // sendBeacon survives the page being closed, which is exactly when the last events of
            // a visit are queued. It cannot set headers, which is why the endpoint is same-origin
            // checked rather than CSRF-token checked.
            if (navigator.sendBeacon) {
                navigator.sendBeacon(endpoint, new Blob([body], {type: 'application/json'}));
                return;
            }

            var request = new XMLHttpRequest();
            request.open('POST', endpoint, true);
            request.setRequestHeader('Content-Type', 'application/json');
            request.send(body);
        } catch (error) {
            // As above.
        }
    }

    // The filter's pushState navigations. Patched rather than hooked into the filter's own code so
    // this keeps working if that file is rewritten, and so any other pushState navigation added
    // later is counted without anybody remembering to.
    try {
        var pushState = history.pushState;
        history.pushState = function () {
            var result = pushState.apply(this, arguments);
            push('product_list_viewed');
            return result;
        };
        window.addEventListener('popstate', function () { push('product_list_viewed'); });
    } catch (error) {
        // An environment that will not let history be patched simply loses these events.
    }

    // Interactions that never navigate. Delegated from the document so elements the theme renders
    // later — a quick view's markup, an ajax-replaced product grid — are covered without rebinding.
    document.addEventListener('click', function (event) {
        try {
            var target = event.target.closest ? event.target.closest('[data-analytics]') : null;
            if (!target) return;

            push(target.getAttribute('data-analytics'), {
                entityType: target.getAttribute('data-analytics-type'),
                entityId: target.getAttribute('data-analytics-id')
            });
        } catch (error) {
            // A click is never blocked by its own measurement.
        }
    }, true);

    // Anything still queued when the page goes away. visibilitychange fires on mobile where
    // unload does not, which is where most of this shop's traffic is.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flush();
    });
    window.addEventListener('pagehide', flush);

    // A small public surface, so theme code can report something without knowing any of the above.
    window.kohlAnalytics = {track: push, flush: flush};
})();
