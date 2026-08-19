// Driver GPS Tracking Script
// Continuous tracking starts only after the first delivery photo is taken.

(function() {
    'use strict';

    var lastTrackingTime = 0;
    var trackingInitialized = false;
    var TRACKING_INTERVAL = 60000;
    var MIN_INTERVAL = 30000;

    function getRouteDate() {
        var root = document.getElementById('driverRouteRoot');
        if (root && root.getAttribute('data-date')) {
            return root.getAttribute('data-date');
        }
        var params = new URLSearchParams(window.location.search || '');
        if (params.get('date')) {
            return params.get('date');
        }
        return new Date().toISOString().slice(0, 10);
    }

    function isTrackingActive() {
        return localStorage.getItem('gps_tracking_active') === 'true'
            && localStorage.getItem('gps_tracking_date') === getRouteDate();
    }

    function getTrackingDriverId() {
        return localStorage.getItem('tracking_driver_id');
    }

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function getBasePath() {
        var meta = document.querySelector('meta[name="app-base-url"]');
        if (meta) {
            return meta.getAttribute('content') || '/';
        }
        var path = window.location.pathname || '';
        if (path.indexOf('/bakery/') !== -1) {
            return '/bakery/';
        }
        var parts = path.split('/');
        parts.pop();
        return parts.length ? parts.join('/') + '/' : '/';
    }

    function logGPSCoordinate(latitude, longitude, accuracy, driverId) {
        var formData = new FormData();
        formData.append('action', 'log_gps');
        formData.append('driver_id', driverId);
        formData.append('latitude', latitude);
        formData.append('longitude', longitude);
        formData.append('accuracy_m', accuracy == null ? '' : accuracy);
        formData.append('timestamp', new Date().toISOString());
        var csrf = getCsrfToken();
        if (csrf) {
            formData.append('csrf_token', csrf);
        }

        var base = getBasePath();
        var endpoints = [
            base + 'global_gps_handler.php',
            base + 'driver.php'
        ];

        function tryEndpoint(index) {
            if (index >= endpoints.length) {
                console.warn('All GPS logging endpoints failed');
                return;
            }

            fetch(endpoints[index], {
                method: 'POST',
                body: formData
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    console.log('GPS logged via ' + endpoints[index] + ' at ' + new Date().toLocaleTimeString());
                } else {
                    throw new Error('GPS logging failed');
                }
            })
            .catch(function () {
                console.warn('GPS logging failed at ' + endpoints[index] + ', trying next endpoint');
                tryEndpoint(index + 1);
            });
        }

        tryEndpoint(0);
    }

    function trackGPSPosition() {
        var driverId = getTrackingDriverId();

        if (!isTrackingActive() || !driverId || !navigator.geolocation) {
            return;
        }

        var now = Date.now();
        if (now - lastTrackingTime < MIN_INTERVAL) {
            return;
        }

        navigator.geolocation.getCurrentPosition(
            function(position) {
                lastTrackingTime = now;
                logGPSCoordinate(
                    position.coords.latitude,
                    position.coords.longitude,
                    position.coords.accuracy,
                    driverId
                );
            },
            function(error) {
                console.warn('GPS tracking error:', error.message);
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 60000
            }
        );
    }

    function startTrackingListeners() {
        if (trackingInitialized) {
            return;
        }
        trackingInitialized = true;

        setTimeout(trackGPSPosition, 1000);

        document.addEventListener('click', function() {
            if (isTrackingActive()) {
                setTimeout(trackGPSPosition, 500);
            }
        });

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && isTrackingActive()) {
                setTimeout(trackGPSPosition, 1000);
            }
        });

        window.addEventListener('focus', function() {
            if (isTrackingActive()) {
                setTimeout(trackGPSPosition, 1000);
            }
        });

        window.setInterval(function() {
            if (isTrackingActive()) {
                trackGPSPosition();
            }
        }, TRACKING_INTERVAL);
    }

    function isDriverTabletPage() {
        var page = (window.location.pathname || '').split('/').pop() || '';
        return page === 'driver.php' || page === 'driver_list.php';
    }

    window.bakeryEnableGpsTracking = function(driverId, routeDate) {
        if (!driverId || parseInt(driverId, 10) <= 0) {
            return;
        }
        try {
            localStorage.setItem('tracking_driver_id', String(driverId));
            localStorage.setItem('gps_tracking_active', 'true');
            localStorage.setItem('gps_tracking_date', routeDate || getRouteDate());
        } catch (error) {}

        if (!isDriverTabletPage()) {
            return;
        }

        startTrackingListeners();
        setTimeout(trackGPSPosition, 500);
    };

    function initDriverTracking() {
        if (!isDriverTabletPage() || !isTrackingActive()) {
            return;
        }
        startTrackingListeners();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDriverTracking);
    } else {
        initDriverTracking();
    }
}());
