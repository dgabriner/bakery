// Driver GPS Tracking Script
// This script runs only on the driver page for GPS tracking

(function() {
    'use strict';
    
    let lastTrackingTime = 0;
    const TRACKING_INTERVAL = 120000; // 2 minutes in milliseconds
    const MIN_INTERVAL = 30000; // Minimum 30 seconds between updates
    
    // Check if GPS tracking is active
    function isTrackingActive() {
        return localStorage.getItem('gps_tracking_active') === 'true';
    }
    
    // Get stored driver ID
    function getTrackingDriverId() {
        return localStorage.getItem('tracking_driver_id');
    }
    
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function getBasePath() {
        var path = window.location.pathname || '';
        if (path.indexOf('/bakery/') !== -1) {
            return '/bakery/';
        }
        var parts = path.split('/');
        parts.pop();
        return parts.length ? parts.join('/') + '/' : '/';
    }

    // Log GPS coordinate via AJAX
    function logGPSCoordinate(latitude, longitude, driverId) {
        const formData = new FormData();
        formData.append('action', 'log_gps');
        formData.append('driver_id', driverId);
        formData.append('latitude', latitude);
        formData.append('longitude', longitude);
        formData.append('timestamp', new Date().toISOString());
        var csrf = getCsrfToken();
        if (csrf) {
            formData.append('csrf_token', csrf);
        }

        var base = getBasePath();
        const endpoints = [
            base + 'global_gps_handler.php',
            base + 'driver.php'
        ];
        
        // Try each endpoint until one succeeds
        function tryEndpoint(index) {
            if (index >= endpoints.length) {
                console.warn('All GPS logging endpoints failed');
                return;
            }
            
            fetch(endpoints[index], {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log(`GPS logged via ${endpoints[index]} at ${new Date().toLocaleTimeString()}`);
                } else {
                    throw new Error('GPS logging failed');
                }
            })
            .catch(error => {
                console.warn(`GPS logging failed at ${endpoints[index]}, trying next endpoint`);
                tryEndpoint(index + 1);
            });
        }
        
        tryEndpoint(0);
    }
    
    // Get current GPS position and log it
    function trackGPSPosition() {
        const driverId = getTrackingDriverId();
        
        if (!driverId || !navigator.geolocation) {
            return;
        }
        
        // Check if enough time has passed since last tracking
        const now = Date.now();
        if (now - lastTrackingTime < MIN_INTERVAL) {
            return; // Too soon since last update
        }
        
        navigator.geolocation.getCurrentPosition(
            function(position) {
                lastTrackingTime = now;
                logGPSCoordinate(
                    position.coords.latitude,
                    position.coords.longitude,
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
    
    function isDriverTabletPage() {
        var page = (window.location.pathname || '').split('/').pop() || '';
        return page === 'driver.php' || page === 'driver_list.php';
    }

    // Initialize tracking on driver tablet pages
    function initDriverTracking() {
        if (!isTrackingActive() || !isDriverTabletPage()) {
            return;
        }
        
        // Track immediately on page load
        setTimeout(trackGPSPosition, 1000);
        
        // Track on clicks (any navigation/interaction)
        document.addEventListener('click', function(e) {
            if (isTrackingActive()) {
                setTimeout(trackGPSPosition, 500); // Small delay to avoid rapid firing
            }
        });
        
        // Track on page visibility changes (coming back to tab)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && isTrackingActive()) {
                setTimeout(trackGPSPosition, 1000);
            }
        });
        
        // Track on window focus
        window.addEventListener('focus', function() {
            if (isTrackingActive()) {
                setTimeout(trackGPSPosition, 1000);
            }
        });
        
        // Periodic backup tracking (every 2 minutes)
        setInterval(function() {
            if (isTrackingActive()) {
                trackGPSPosition();
            }
        }, TRACKING_INTERVAL);
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDriverTracking);
    } else {
        initDriverTracking();
    }
    
})(); 