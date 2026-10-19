/**
 * Google 共用模組：地址自動完成（Places）、地址格式檢核與 Google Maps 導航連結。
 */
(function () {
    'use strict';

    const apiKey = (document.querySelector('meta[name="google-maps-api-key"]') || {}).content || '';
    let loadPromise = null;

    function load() {
        if (loadPromise) {
            return loadPromise;
        }
        loadPromise = new Promise(function (resolve, reject) {
            if (window.google && window.google.maps && window.google.maps.places) {
                resolve(window.google.maps);
                return;
            }
            if (!apiKey) {
                reject(new Error('尚未設定 Google Maps API 金鑰。'));
                return;
            }
            window.__foodbankMapsReady = function () {
                resolve(window.google.maps);
            };
            const script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey)
                + '&libraries=places&language=zh-TW&region=TW&loading=async&callback=__foodbankMapsReady';
            script.async = true;
            script.onerror = function () {
                loadPromise = null;
                reject(new Error('Google Maps 載入失敗。'));
            };
            document.head.appendChild(script);
        });
        return loadPromise;
    }

    const addressPattern = /[\p{L}]/u;
    const addressOnlySymbols = /^[\d\s\p{P}\p{S}]+$/u;

    function validateAddress(value, label) {
        const text = value.trim();
        if (!text) {
            return label + '為必填欄位。';
        }
        if (text.length < 5 || text.length > 255) {
            return label + '長度需介於 5 到 255 個字元。';
        }
        if (!addressPattern.test(text) || addressOnlySymbols.test(text) || /[<>]/.test(text)) {
            return label + '格式不正確，請輸入完整地址。';
        }
        return '';
    }

    /** 地址必填 / 格式防呆，並在 SDK 可用時啟用 Places Autocomplete。 */
    function bindAddressInputs() {
        const inputs = document.querySelectorAll('[data-address-input]');
        if (!inputs.length) {
            return;
        }

        function check(input) {
            const message = validateAddress(input.value, input.dataset.addressLabel || '地址');
            input.setCustomValidity(message);
            return message;
        }

        inputs.forEach(function (input) {
            input.addEventListener('input', function () { check(input); });
            input.addEventListener('blur', function () { check(input); });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && document.querySelector('.pac-container .pac-item-selected')) {
                    event.preventDefault();
                }
            });
            check(input);
        });

        load().then(function (maps) {
            inputs.forEach(function (input) {
                const autocomplete = new maps.places.Autocomplete(input, {
                    componentRestrictions: { country: 'tw' },
                    fields: ['formatted_address', 'name']
                });
                autocomplete.addListener('place_changed', function () {
                    const place = autocomplete.getPlace();
                    if (place && place.formatted_address) {
                        input.value = place.formatted_address;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            });
        }).catch(function () {
            // 沒有 API 金鑰時仍保留基本格式檢核
        });
    }

    /**
     * 產生 Google Maps 導航 URL。
     * origin 為空時，Google Maps 會使用手機目前位置。
     */
    function buildDirectionsUrl(origin, destination, travelMode, waypoint) {
        const params = new URLSearchParams({ api: '1', destination: destination, travelmode: travelMode || 'driving' });
        if (origin) {
            params.set('origin', origin);
        }
        if (waypoint) {
            params.set('waypoints', waypoint);
        }
        return 'https://www.google.com/maps/dir/?' + params.toString();
    }

    window.FoodbankMap = {
        load: load,
        bindAddressInputs: bindAddressInputs,
        buildDirectionsUrl: buildDirectionsUrl
    };

    document.addEventListener('DOMContentLoaded', bindAddressInputs);
})();
