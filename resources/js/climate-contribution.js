(function () {
    'use strict';
    var endpoint = '/rest/waldorfshop/climate-contribution';
    var busy = false;
    var state = null;
    var panel, checkbox, status;

    function message(key) {
        return panel.getAttribute('data-ws-climate-' + key) || '';
    }

    async function request(method, body) {
        var headers = {Accept: 'application/json', 'Accept-Language': document.documentElement.lang || 'de'};
        if (body) {
            headers['Content-Type'] = 'application/json';
            var token = document.getElementById('csrf-token');
            if (!token || !token.value) throw new Error(message('reload'));
            headers['X-CSRF-TOKEN'] = token.value;
        }
        var envelope;
        if (method === 'POST' && window.jQuery && window.jQuery.ajax) {
            // Ceres handles the response events through its global ajaxComplete listener.
            // Await resumes after that listener has updated basket and checkout stores.
            envelope = await new Promise(function (resolve, reject) {
                window.jQuery.ajax({
                    url: endpoint, method: method, headers: headers,
                    contentType: 'application/json', dataType: 'json',
                    data: JSON.stringify(body), cache: false
                }).done(resolve).fail(function () { reject(new Error(message('save-failed'))); });
            });
        } else {
            var response = await fetch(endpoint, {
                method: method, credentials: 'same-origin', cache: 'no-store', headers: headers,
                body: body ? JSON.stringify(body) : undefined
            });
            try { envelope = await response.json(); }
            catch (error) { throw new Error(message('save-failed')); }
            if (!response.ok) throw new Error(message('save-failed'));
        }
        var data = envelope && envelope.data;
        if (!data || typeof data.enabled !== 'boolean') throw new Error(message('load-failed'));
        return data;
    }

    async function refresh() {
        if (busy) return;
        try {
            state = await request('GET');
            panel.hidden = !state.enabled;
            checkbox.checked = state.selected === true;
            checkbox.disabled = !state.eligible;
            status.textContent = state.selected ? message('selected') :
                (state.eligible ? '' : message('ineligible'));
        } catch (error) {
            checkbox.disabled = true;
            status.textContent = message('load-failed');
        }
    }

    async function change() {
        if (busy || !state || !state.eligible) return;
        busy = true;
        checkbox.disabled = true;
        status.textContent = message('updating');
        var overlay = document.createElement('div');
        overlay.setAttribute('role', 'status');
        overlay.textContent = message('updating');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:rgba(255,255,255,.9);display:flex;align-items:center;justify-content:center;padding:2rem;text-align:center';
        document.body.appendChild(overlay);
        try {
            state = await request('POST', {selected: checkbox.checked, basketId: state.basketId});
            if (!window.jQuery || !window.jQuery.ajax) {
                // Compatibility fallback when the normal Ceres event transport is unavailable.
                window.location.reload();
                return;
            }
            checkbox.checked = state.selected === true;
            checkbox.disabled = !state.eligible;
            status.textContent = state.selected ? message('selected') : '';
            overlay.remove();
            busy = false;
        } catch (error) {
            checkbox.checked = state.selected;
            status.textContent = error.message;
            checkbox.disabled = false;
            overlay.remove();
            busy = false;
        }
    }

    function init() {
        panel = document.querySelector('[data-ws-climate]');
        if (!panel) return;
        checkbox = panel.querySelector('[data-ws-climate-checkbox]');
        status = panel.querySelector('[data-ws-climate-status]');
        checkbox.addEventListener('change', change);
        document.addEventListener('afterBasketChanged', refresh);
        document.addEventListener('submit', function (event) {
            if (busy) {event.preventDefault(); event.stopImmediatePropagation();}
        }, true);
        refresh();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
    else init();
}());
