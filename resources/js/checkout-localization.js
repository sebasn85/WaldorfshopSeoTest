(function () {
    'use strict';
    function init() {
        var source = document.querySelector('[data-ws-checkout-copy]');
        var root = document.querySelector('.page-content.checkout');
        if (!source || !root) return;
        var copies;
        try { copies = JSON.parse(source.textContent); } catch (error) { return; }
        var language = (document.documentElement.lang || 'de').toLowerCase().split('-')[0];
        var copy = copies[language] || copies.de;
        var scheduled = false;
        function translate() {
            scheduled = false;
            root = document.querySelector('.page-content.checkout');
            if (!root) return;
            root.querySelectorAll('small.d-block.mb-3').forEach(function (element) {
                if (element.textContent.trim().indexOf('Als Kund:in von Waldorfshop') === 0) {
                    element.textContent = copy.marketing;
                }
            });
            // Limit corrections to checkout controls; product and customer data stay untouched.
            root.querySelectorAll('label,label span,button,button span,a,a span,h1,h2,h3').forEach(function (element) {
                Array.prototype.forEach.call(element.childNodes, function (node) {
                    if (node.nodeType !== 3) return;
                    var text = node.textContent.trim();
                    var key = Object.keys(copy.replacements).find(function (candidate) {
                        return candidate.toLocaleLowerCase() === text.toLocaleLowerCase();
                    });
                    var replacement = key && copy.replacements[key];
                    if (replacement) node.textContent = node.textContent.replace(text, replacement);
                });
            });
            if (language === 'en') {
                root.querySelectorAll('[aria-label="Inrease quantity"]').forEach(function (element) {
                    element.setAttribute('aria-label', 'Increase quantity');
                });
            }
        }
        translate();
        new MutationObserver(function () {
            if (!scheduled) { scheduled = true; window.requestAnimationFrame(translate); }
        }).observe(document.body, {childList: true, subtree: true, characterData: true});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
}());
