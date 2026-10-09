(function () {
    'use strict';
    var endpoint = '/rest/waldorfshop/climate-contribution';
    var busy = false;
    var state = null;
    var panel, checkbox, status;

    async function request(method, body) {
        var headers = {Accept: 'application/json', 'Accept-Language': document.documentElement.lang || 'de'};
        if (body) {
            headers['Content-Type'] = 'application/json';
            var token = document.getElementById('csrf-token');
            if (!token || !token.value) throw new Error('Bitte lade die Kasse neu.');
            headers['X-CSRF-TOKEN'] = token.value;
        }
        var response = await fetch(endpoint, {
            method: method, credentials: 'same-origin', cache: 'no-store', headers: headers,
            body: body ? JSON.stringify(body) : undefined
        });
        var envelope;
        try { envelope = await response.json(); }
        catch (error) { throw new Error('Der Versandbeitrag konnte nicht gespeichert werden. Bitte lade die Kasse neu.'); }
        var data = envelope && envelope.data;
        if (!response.ok) throw new Error((data && data.error) || 'Der Versandbeitrag konnte nicht gespeichert werden. Bitte lade die Kasse neu und versuche es erneut.');
        if (!data || typeof data.enabled !== 'boolean') throw new Error('Der Versandbeitrag konnte nicht geladen werden. Bitte lade die Kasse neu.');
        return data;
    }

    async function refresh() {
        if (busy) return;
        try {
            state = await request('GET');
            panel.hidden = !state.enabled;
            checkbox.checked = state.selected === true;
            checkbox.disabled = !state.eligible;
            status.textContent = state.selected ? 'Freiwilliger Versandbeitrag: 0,50 € ist in den Versandkosten enthalten.' :
                (state.eligible ? '' : 'Der Beitrag ist aktuell nur für Warenkörbe in Euro mit Bruttopreisen verfügbar.');
        } catch (error) {
            checkbox.disabled = true;
            status.textContent = 'Der freiwillige Versandbeitrag konnte nicht geladen werden.';
        }
    }

    async function change() {
        if (busy || !state || !state.eligible) return;
        busy = true;
        checkbox.disabled = true;
        status.textContent = 'Dein Gesamtbetrag wird aktualisiert …';
        var overlay = document.createElement('div');
        overlay.setAttribute('role', 'status');
        overlay.textContent = 'Dein Gesamtbetrag wird aktualisiert …';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:rgba(255,255,255,.9);display:flex;align-items:center;justify-content:center;padding:2rem;text-align:center';
        document.body.appendChild(overlay);
        try {
            await request('POST', {selected: checkbox.checked, basketId: state.basketId});
            // Reload preserves the preview URL and lets all payment components use the new server total.
            window.location.reload();
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
