/**
 * Mapbox 即時地圖元件（愛心商家端）。
 * 提供 FoodbankMap.create(container, options)，介面與先前的地圖元件相同：
 * ready / setRider / clearRider / setPickup / setDestination / setTrail
 */
(function () {
    'use strict';

    const MAPBOX_VERSION = 'v3.4.0';
    const MAP_STYLE = 'mapbox://styles/mapbox/streets-v12';
    const DEFAULT_CENTER = [121.5654, 25.0330]; // [lng, lat]
    const DEFAULT_ZOOM = 14;
    const MOVE_DURATION_MS = 1500;
    const token = (document.querySelector('meta[name="mapbox-access-token"]') || {}).content || '';
    const geocodeCache = {};
    let loadPromise = null;

    function loadMapbox() {
        if (loadPromise) {
            return loadPromise;
        }
        loadPromise = new Promise(function (resolve, reject) {
            if (!token) {
                loadPromise = null;
                reject(new Error('尚未設定 Mapbox Access Token。'));
                return;
            }
            if (window.mapboxgl) {
                resolve(window.mapboxgl);
                return;
            }
            const base = 'https://api.mapbox.com/mapbox-gl-js/' + MAPBOX_VERSION + '/mapbox-gl';
            const css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = base + '.css';
            document.head.appendChild(css);

            const script = document.createElement('script');
            script.src = base + '.js';
            script.async = true;
            script.onload = function () {
                window.mapboxgl.accessToken = token;
                resolve(window.mapboxgl);
            };
            script.onerror = function () {
                loadPromise = null;
                reject(new Error('Mapbox 載入失敗。'));
            };
            document.head.appendChild(script);
        });
        return loadPromise;
    }

    /** 地址轉經緯度（Mapbox Geocoding），回傳 [lng, lat] 或 null。 */
    function geocode(address) {
        if (geocodeCache[address]) {
            return geocodeCache[address];
        }
        const url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/' + encodeURIComponent(address)
            + '.json?limit=1&country=tw&language=zh-TW&access_token=' + encodeURIComponent(token);
        geocodeCache[address] = fetch(url)
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                return data && data.features && data.features[0] ? data.features[0].center : null;
            })
            .catch(function () { return null; });
        return geocodeCache[address];
    }

    function createRiderElement(title) {
        const el = document.createElement('div');
        el.className = 'go-rider-marker';
        el.title = title;
        el.style.cssText = 'display:flex;flex-direction:column;align-items:center;pointer-events:auto;';
        el.innerHTML = '<span style="background:#0f766e;color:#fff;font:700 11px/1 sans-serif;padding:3px 7px;border-radius:999px;margin-bottom:3px;white-space:nowrap;">Go Rider</span>'
            + '<span style="width:34px;height:34px;border-radius:50%;background:#0f766e;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;"><i class="fas fa-motorcycle"></i></span>';
        return el;
    }

    function create(container, options) {
        const settings = options || {};
        const riderTitle = settings.riderTitle || 'Go Rider';
        let map = null;
        let riderMarker = null;
        let pickupMarker = null;
        let destinationMarker = null;
        let riderPosition = null;
        let animationFrame = null;
        let hasFitted = false;
        let ready = null;
        let trailCoordinates = [];

        function init() {
            if (!ready) {
                ready = loadMapbox().then(function (mapboxgl) {
                    return new Promise(function (resolve) {
                        map = new mapboxgl.Map({
                            container: container,
                            style: MAP_STYLE,
                            center: DEFAULT_CENTER,
                            zoom: DEFAULT_ZOOM
                        });
                        map.addControl(new mapboxgl.NavigationControl({ showCompass: false }), 'top-right');
                        map.on('load', function () {
                            map.addSource('rider-trail', {
                                type: 'geojson',
                                data: { type: 'Feature', geometry: { type: 'LineString', coordinates: [] } }
                            });
                            map.addLayer({
                                id: 'rider-trail-line',
                                type: 'line',
                                source: 'rider-trail',
                                layout: { 'line-cap': 'round', 'line-join': 'round' },
                                paint: { 'line-color': '#0f766e', 'line-width': 5, 'line-opacity': 0.9 }
                            });
                            resolve(mapboxgl);
                        });
                    });
                });
                ready.catch(function () { ready = null; });
            }
            return ready;
        }

        function fit(mapboxgl) {
            const bounds = new mapboxgl.LngLatBounds();
            let count = 0;
            [riderMarker, pickupMarker, destinationMarker].forEach(function (marker) {
                if (marker) {
                    bounds.extend(marker.getLngLat());
                    count += 1;
                }
            });
            if (count === 1) {
                map.easeTo({ center: bounds.getCenter(), zoom: DEFAULT_ZOOM });
            } else if (count > 1) {
                map.fitBounds(bounds, { padding: 56, maxZoom: 16, duration: 600 });
            }
        }

        function setAddressMarker(kind, address) {
            if (!address) {
                return Promise.resolve();
            }
            return init().then(function (mapboxgl) {
                return geocode(address).then(function (lngLat) {
                    if (!lngLat) {
                        return;
                    }
                    const isPickup = kind === 'pickup';
                    let marker = isPickup ? pickupMarker : destinationMarker;
                    if (!marker) {
                        marker = new mapboxgl.Marker({ color: isPickup ? '#16a34a' : '#dc2626' })
                            .setPopup(new mapboxgl.Popup({ offset: 24 }).setText((isPickup ? '取貨點：' : '送達點：') + address))
                            .setLngLat(lngLat)
                            .addTo(map);
                        if (isPickup) {
                            pickupMarker = marker;
                        } else {
                            destinationMarker = marker;
                        }
                    } else {
                        marker.setLngLat(lngLat);
                    }
                    if (!hasFitted) {
                        fit(mapboxgl);
                    }
                });
            });
        }

        /** 讓 Go Rider Marker 平滑位移到新座標。 */
        function glideTo(target) {
            if (animationFrame) {
                cancelAnimationFrame(animationFrame);
            }
            const start = riderMarker.getLngLat();
            const startedAt = performance.now();
            function step(now) {
                const t = Math.min(1, (now - startedAt) / MOVE_DURATION_MS);
                const eased = t * (2 - t);
                riderMarker.setLngLat([
                    start.lng + (target[0] - start.lng) * eased,
                    start.lat + (target[1] - start.lat) * eased
                ]);
                animationFrame = t < 1 ? requestAnimationFrame(step) : null;
            }
            animationFrame = requestAnimationFrame(step);
        }

        return {
            ready: init,
            setRider: function (latitude, longitude) {
                return init().then(function (mapboxgl) {
                    const target = [Number(longitude), Number(latitude)]; // [lng, lat]
                    if (riderPosition && riderPosition[0] === target[0] && riderPosition[1] === target[1]) {
                        return;
                    }
                    riderPosition = target;
                    if (!riderMarker) {
                        riderMarker = new mapboxgl.Marker({ element: createRiderElement(riderTitle), anchor: 'bottom' })
                            .setLngLat(target)
                            .addTo(map);
                        hasFitted = true;
                        fit(mapboxgl);
                        return;
                    }
                    glideTo(target);
                    // 跟隨視野：Go Rider 離開畫面時才平移，避免干擾商家手動操作
                    if (!map.getBounds().contains(target)) {
                        map.easeTo({ center: target, duration: MOVE_DURATION_MS });
                    }
                });
            },
            clearRider: function () {
                if (animationFrame) {
                    cancelAnimationFrame(animationFrame);
                    animationFrame = null;
                }
                if (riderMarker) {
                    riderMarker.remove();
                    riderMarker = null;
                }
                riderPosition = null;
                hasFitted = false;
                trailCoordinates = [];
                if (map && map.getSource('rider-trail')) {
                    map.getSource('rider-trail').setData({ type: 'Feature', geometry: { type: 'LineString', coordinates: [] } });
                }
            },
            setPickup: function (address) { return setAddressMarker('pickup', address); },
            setDestination: function (address) { return setAddressMarker('destination', address); },
            // points: [[lat, lng], ...]
            setTrail: function (points) {
                return init().then(function () {
                    trailCoordinates = (points || []).map(function (point) {
                        return [Number(point[1]), Number(point[0])];
                    });
                    map.getSource('rider-trail').setData({
                        type: 'Feature',
                        geometry: { type: 'LineString', coordinates: trailCoordinates }
                    });
                });
            }
        };
    }

    window.FoodbankMap = Object.assign(window.FoodbankMap || {}, { create: create, loadMapbox: loadMapbox });
})();
