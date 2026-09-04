const driverAssignmentConfig = {
    date: window.__DRIVER_ASSIGNMENT__.selectedDate,
    mapsKey: window.__DRIVER_ASSIGNMENT__.apiKey,
    standingRoutes: window.__DRIVER_ASSIGNMENT__.standingRoutes,
    dailyOrders: window.__DRIVER_ASSIGNMENT__.dailyOrders,
    ordersByDriver: window.__DRIVER_ASSIGNMENT__.ordersByDriver,
    driversById: window.__DRIVER_ASSIGNMENT__.driversById,
    currentDayOfWeek: window.__DRIVER_ASSIGNMENT__.dayOfWeek,
    dayName: window.__DRIVER_ASSIGNMENT__.dayName,
    activeCustomers: window.__DRIVER_ASSIGNMENT__.activeCustomers,
    productsForStanding: window.__DRIVER_ASSIGNMENT__.productsForStanding,
    assignedCustomerIdsToday: window.__DRIVER_ASSIGNMENT__.assignedCustomerIdsToday
};

// Global variables
let currentDriverId = null;
let currentAssignments = [];
let map = null;
let directionsService = null;
let directionsRenderer = null;
let geocoder = null;
let markers = [];

const bakeryAddress = '484 5th Street, San Francisco, CA';
const routeStartMinutes = (6 * 60) + 40;

function formatDuration(totalMinutes) {
    const minutes = Math.max(0, Math.round(totalMinutes));
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return hours ? hours + 'h ' + remainder + 'm' : remainder + 'm';
}

function formatClock(totalMinutes) {
    const minutesInDay = ((Math.round(totalMinutes) % 1440) + 1440) % 1440;
    const hours24 = Math.floor(minutesInDay / 60);
    const minutes = minutesInDay % 60;
    const suffix = hours24 >= 12 ? 'PM' : 'AM';
    const hours12 = hours24 % 12 || 12;
    return hours12 + ':' + String(minutes).padStart(2, '0') + ' ' + suffix;
}

function timeToMinutes(value) {
    if (!value) return null;
    const parts = String(value).split(':').map(Number);
    return Number.isFinite(parts[0]) ? (parts[0] * 60) + (parts[1] || 0) : null;
}

function minutesToTimeString(totalMinutes) {
    const minutesInDay = ((Math.round(totalMinutes) % 1440) + 1440) % 1440;
    const hours = Math.floor(minutesInDay / 60);
    const mins = minutesInDay % 60;
    return String(hours).padStart(2, '0') + ':' + String(mins).padStart(2, '0');
}

function mainViewStopData(element) {
    return {
        element,
        orderId: element.dataset.orderId,
        address: element.dataset.address,
        deliverBy: timeToMinutes(element.dataset.deliverBy),
        deliverAfter: timeToMinutes(element.dataset.deliverAfter),
        deliveryMinutes: Number(element.dataset.deliveryMinutes) || 20
    };
}

function getMainViewRouteStops(routeList) {
    return Array.from(routeList.querySelectorAll('.order-item')).map(mainViewStopData);
}

function calculateMainViewRouteSchedule(result, stops) {
    const legs = result.routes[0].legs;
    let currentMinutes = routeStartMinutes;
    const arrivals = [];
    const departures = [];
    stops.forEach((stop, index) => {
        currentMinutes += legs[index].duration.value / 60;
        if (stop.deliverAfter !== null && currentMinutes < stop.deliverAfter) {
            currentMinutes = stop.deliverAfter;
        }
        arrivals.push(currentMinutes);
        currentMinutes += stop.deliveryMinutes;
        departures.push(currentMinutes);
    });
    if (legs[stops.length]) {
        currentMinutes += legs[stops.length].duration.value / 60;
    }
    return {
        totalMinutes: currentMinutes - routeStartMinutes,
        arrivals,
        departures
    };
}

function updateMainViewRoutePresentation(routeList, exactSchedule) {
    const stops = getMainViewRouteStops(routeList);
    const driverSection = routeList.closest('.driver-section');
    const estimate = driverSection ? driverSection.querySelector('.route-time-estimate') : null;

    if (!stops.length) {
        if (estimate) {
            estimate.textContent = '';
            estimate.title = '';
        }
        return null;
    }

    let routineFinish = routeStartMinutes;
    const routineArrivals = [];
    stops.forEach(stop => {
        routineFinish += 10;
        if (stop.deliverAfter !== null && routineFinish < stop.deliverAfter) {
            routineFinish = stop.deliverAfter;
        }
        routineArrivals.push(routineFinish);
        routineFinish += stop.deliveryMinutes;
    });
    routineFinish += 10;

    const schedule = exactSchedule || {
        totalMinutes: routineFinish - routeStartMinutes,
        arrivals: routineArrivals
    };
    const isApprox = !exactSchedule;

    stops.forEach((stop, index) => {
        const timeSpan = stop.element.querySelector('.delivery-time');
        if (timeSpan) {
            timeSpan.textContent = (isApprox ? '≈ ' : '') + formatClock(schedule.arrivals[index]);
            timeSpan.classList.toggle('delivery-time-estimated', isApprox);
            timeSpan.classList.toggle('delivery-time-exact', !isApprox);
        }
    });

    if (estimate) {
        estimate.textContent = (isApprox ? '≈ ' : '') + formatDuration(schedule.totalMinutes)
            + ' · finishes ' + (isApprox ? '~' : '') + formatClock(routeStartMinutes + schedule.totalMinutes);
        estimate.title = (isApprox ? 'Routine estimate' : 'Directions estimate')
            + ' · starts 6:40 AM · finishes about ' + formatClock(routeStartMinutes + schedule.totalMinutes);
    }

    return schedule;
}

function fetchExactMainViewRouteTimes(routeList) {
    const stops = getMainViewRouteStops(routeList);
    if (!stops.length) {
        return Promise.resolve(null);
    }
    if (typeof google === 'undefined' || !google.maps) {
        return Promise.resolve(null);
    }

    const service = directionsService || new google.maps.DirectionsService();
    return new Promise(resolve => {
        service.route({
            origin: bakeryAddress,
            destination: bakeryAddress,
            waypoints: stops.map(stop => ({ location: stop.address, stopover: true })),
            optimizeWaypoints: false,
            travelMode: google.maps.TravelMode.DRIVING
        }, (result, status) => {
            if (status === 'OK') {
                resolve(calculateMainViewRouteSchedule(result, stops));
            } else {
                resolve(null);
            }
        });
    });
}

function refreshMainViewRouteTimes(routeList) {
    updateMainViewRoutePresentation(routeList, null);
    return fetchExactMainViewRouteTimes(routeList).then(schedule => {
        if (schedule) {
            updateMainViewRoutePresentation(routeList, schedule);
        }
        return schedule;
    });
}

function refreshAllMainViewRouteTimes() {
    const lists = document.querySelectorAll('.route-order-list');
    lists.forEach(list => refreshMainViewRouteTimes(list));
}

// Initialize Google Maps
function initMap() {
    if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
        console.error('Google Maps API not loaded');
        return;
    }
    
    map = new google.maps.Map(document.getElementById('route-map'), {
        zoom: 12,
        center: { lat: 37.7749, lng: -122.4194 },
        mapTypeId: 'roadmap'
    });
    
    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
        draggable: false,
        suppressMarkers: true
    });
    directionsRenderer.setMap(map);
    
    geocoder = new google.maps.Geocoder();
}

// Auto-assign from standing routes
function autoAssignFromStandingRoutes() {
    if (!confirm(window.__DRIVER_ASSIGNMENT__.buildConfirm)) {
        return;
    }
    
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=create_orders_and_assign&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload(); // Refresh page to show updated assignments
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error creating orders and assignments');
    });
}

function saveAsStandingRoute() {
    if (!confirm('Save the current dated routes as the recurring window.__DRIVER_ASSIGNMENT__.dayName route? This replaces the existing recurring route for this weekday and will affect future weeks.')) {
        return;
    }

    fetch('driver_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=save_as_standing_route&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Could not save recurring route'));
        }
    })
    .catch(error => {
        console.error('Error saving recurring route:', error);
        alert('Error saving recurring route');
    });
}

// Assign specific driver from standing routes
function assignFromStandingRoutes(driverId) {
    if (!confirm('Restore this driver\'s dated route from the standing route?')) {
        return;
    }

    fetch('driver_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=sync_driver_route&driver_id=' + encodeURIComponent(driverId)
            + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Could not restore route'));
        }
    })
    .catch(error => {
        console.error('Error restoring route:', error);
        alert('Error restoring route');
    });
}

// Optimize route for a driver (Constraint-aware, Route Tester logic)
function optimizeRoute(driverId) {
    currentDriverId = driverId;
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_optimized_route&driver_id=' + driverId + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showConstraintAwareRouteModal(data.orders);
        } else {
            alert('Error getting route data: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error getting route data');
    });
}

// Show constraint-aware route optimization modal
function showConstraintAwareRouteModal(orders) {
    document.getElementById('route-modal').style.display = 'flex';
    if (!map) initMap();
    markers.forEach(marker => marker.setMap(null));
    markers = [];
    // Add bakery marker
    const bakeryLocation = { lat: 37.7749, lng: -122.4194 };
    const bakeryMarker = new google.maps.Marker({
        position: bakeryLocation,
        map: map,
        title: 'Bakery',
        label: '🏪'
    });
    markers.push(bakeryMarker);
    // Add customer markers
    orders.forEach((order, index) => {
        if (order.latitude && order.longitude && !isNaN(order.latitude) && !isNaN(order.longitude)) {
            // Use lat/lng if present and valid
            const marker = new google.maps.Marker({
                position: { lat: parseFloat(order.latitude), lng: parseFloat(order.longitude) },
                map: map,
                title: order.customer_name,
                label: (index + 1).toString()
            });
            markers.push(marker);
        } else if (order.address && typeof google !== 'undefined' && google.maps && typeof geocoder !== 'undefined') {
            // Use geocoder to convert address to coordinates
            geocoder.geocode({ address: order.address }, (results, status) => {
                console.log('Geocoding', order.address, 'Status:', status, 'Results:', results);
                if (status === 'OK' && results[0]) {
                    const marker = new google.maps.Marker({
                        position: results[0].geometry.location,
                        map: map,
                        title: order.customer_name,
                        label: (index + 1).toString()
                    });
                    markers.push(marker);
                } else {
                    console.warn(`Geocoding failed for ${order.customer_name}: ${order.address}`);
                }
            });
        }
    });
    // Start constraint-aware optimization
    optimizeConstraintAwareRoute(orders);
}

// Constraint-aware optimization logic (adapted from Route Tester)
function hasValidRouteCoordinates(order) {
    const latitude = Number(order.latitude);
    const longitude = Number(order.longitude);
    return Number.isFinite(latitude) && Number.isFinite(longitude)
        && latitude >= -90 && latitude <= 90
        && longitude >= -180 && longitude <= 180;
}

function routeLocationForOrder(order) {
    if (hasValidRouteCoordinates(order)) {
        return {
            lat: Number(order.latitude),
            lng: Number(order.longitude)
        };
    }
    return order.address;
}

function optimizeConstraintAwareRoute(orders) {
    if (!orders || orders.length === 0) {
        alert('No orders to optimize.');
        return;
    }
    // Validate addresses
    const invalidAddresses = orders.filter(o =>
        !hasValidRouteCoordinates(o) && (!o.address || o.address.trim().length < 10)
    );
    if (invalidAddresses.length > 0) {
        document.getElementById('route-info').innerHTML = `<div class="alert alert-danger">❌ Invalid addresses detected:<br><small>${invalidAddresses.map(o => o.customer_name + ': ' + o.address).join('<br>')}</small></div>`;
        return;
    }
    // Prepare waypoints
    const waypoints = orders.map(order => ({ location: routeLocationForOrder(order), stopover: true }));
    if (waypoints.length > 25) {
        document.getElementById('route-info').innerHTML = `<div class="alert alert-danger">❌ Too many stops (${waypoints.length}) for route optimization. Please reduce to 25 or fewer.</div>`;
        return;
    }
    const request = {
        origin: '484 5th Street, San Francisco, CA',
        destination: '484 5th Street, San Francisco, CA',
        waypoints: waypoints,
        optimizeWaypoints: true,
        travelMode: google.maps.TravelMode.DRIVING
    };
    directionsService.route(request, (result, status) => {
        if (status === 'OK') {
            fixConstraintViolations(result, orders);
        } else {
            document.getElementById('route-info').innerHTML = `<div class="alert alert-danger">❌ Route optimization failed: ${status}</div>`;
        }
    });
}

// Fix constraint violations iteratively
function fixConstraintViolations(result, orders) {
    // Google's optimized order
    const waypointOrder = result.routes[0].waypoint_order;
    let customerOrder = waypointOrder.map(index => orders[index]);
    let currentRoute = { customerOrder: customerOrder, result: result };
    iterativeConstraintFix(currentRoute, orders);
}

// Iterative constraint fixing (async)
function iterativeConstraintFix(currentRoute, orders) {
    const maxIterations = 50;
    let iteration = 0;
    let lastMovedCustomer = null;
    function processIteration() {
        iteration++;
        const routeWithTimes = calculateArrivalTimesForRoute(currentRoute.result, currentRoute.customerOrder);
        const violations = findConstraintViolationsInRoute(routeWithTimes);
        if (violations.length === 0 || iteration >= maxIterations) {
            // Done! Display final route
            displayConstraintAwareRoute(currentRoute.result, currentRoute.customerOrder, orders, routeWithTimes, violations);
            return;
        }
        // Find largest violation that can be moved earlier
        const fixable = violations.filter(v => {
            const idx = currentRoute.customerOrder.findIndex(c => c.daily_order_id === v.customer.daily_order_id);
            return idx > 0;
        });
        if (fixable.length === 0) {
            displayConstraintAwareRoute(currentRoute.result, currentRoute.customerOrder, orders, routeWithTimes, violations);
            return;
        }
        // Move the largest violation earlier
        const largest = fixable.reduce((a, b) => a.violationMinutes > b.violationMinutes ? a : b);
        moveCustomerOneStepEarlier(currentRoute.customerOrder, largest.customer, orders).then(({ newOrder }) => {
            getRouteForOrder(newOrder, orders).then(newRoute => {
                currentRoute = newRoute || currentRoute;
                processIteration();
            });
        });
    }
    processIteration();
}

// Calculate arrival times for each stop
function calculateArrivalTimesForRoute(result, customerOrder) {
    const route = result.routes[0];
    let currentTime = new Date();
    currentTime.setHours(6, 40, 0, 0); // 6:40 AM start
    return customerOrder.map((customer, idx) => {
        const leg = route.legs[idx];
        currentTime = new Date(currentTime.getTime() + (leg.duration.value * 1000));
        const arrivalTime = new Date(currentTime);
        const deliveryTime = customer.delivery_time || 20;
        currentTime = new Date(currentTime.getTime() + (deliveryTime * 60 * 1000));
        return { customer, arrivalTime, leg, routeIndex: idx };
    });
}

// Find constraint violations
function findConstraintViolationsInRoute(routeWithTimes) {
    const violations = [];
    routeWithTimes.forEach(stop => {
        const customer = stop.customer;
        const arrivalTime = stop.arrivalTime;
        if (customer.deliver_by) {
            const deadline = new Date();
            const [h, m] = customer.deliver_by.split(':');
            deadline.setHours(parseInt(h), parseInt(m), 0, 0);
            if (arrivalTime > deadline) {
                violations.push({
                    type: 'late',
                    customer: customer,
                    arrivalTime: arrivalTime,
                    deadline: deadline,
                    routeIndex: stop.routeIndex,
                    violationMinutes: Math.ceil((arrivalTime - deadline) / (1000 * 60))
                });
            }
        }
    });
    return violations;
}

// Move customer one step earlier
function moveCustomerOneStepEarlier(customerOrder, targetCustomer, orders) {
    return new Promise(resolve => {
        const idx = customerOrder.findIndex(c => c.daily_order_id === targetCustomer.daily_order_id);
        if (idx <= 0) return resolve({ newOrder: customerOrder });
        const newOrder = [...customerOrder];
        const [moved] = newOrder.splice(idx, 1);
        newOrder.splice(idx - 1, 0, moved);
        resolve({ newOrder });
    });
}

// Get route for a specific order
function getRouteForOrder(customerOrder, orders) {
    return new Promise(resolve => {
        const waypoints = customerOrder.map(order => ({ location: routeLocationForOrder(order), stopover: true }));
        const request = {
            origin: '484 5th Street, San Francisco, CA',
            destination: '484 5th Street, San Francisco, CA',
            waypoints: waypoints,
            optimizeWaypoints: false,
            travelMode: google.maps.TravelMode.DRIVING
        };
        directionsService.route(request, (result, status) => {
            if (status === 'OK') {
                resolve({ customerOrder: customerOrder, result: result });
            } else {
                resolve(null);
            }
        });
    });
}

// Display the constraint-aware route and violations
function displayConstraintAwareRoute(result, customerOrder, orders, routeWithTimes, violations) {
    directionsRenderer.setDirections(result);
    let routeList = `<div class='route-stops'><h4>Constraint-Aware Route Order</h4><div class='draggable-route-list'>`;
    routeWithTimes.forEach((stop, idx) => {
        const customer = stop.customer;
        const arrival = stop.arrivalTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        let constraintStatus = '';
        if (customer.deliver_by) {
            const deadline = new Date();
            const [h, m] = customer.deliver_by.split(':');
            deadline.setHours(parseInt(h), parseInt(m), 0, 0);
            if (stop.arrivalTime > deadline) {
                const delay = Math.ceil((stop.arrivalTime - deadline) / (1000 * 60));
                constraintStatus = `<span style='color:red'>❌ Late by ${delay} min (Deadline: ${deadline.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })})</span>`;
            } else {
                const buffer = Math.floor((deadline - stop.arrivalTime) / (1000 * 60));
                constraintStatus = `<span style='color:green'>✅ On time (${buffer} min buffer)</span>`;
            }
        } else {
            constraintStatus = '<span style="color:gray">No delivery time constraint</span>';
        }
        routeList += `
            <div class="route-stop-item" draggable="true" data-index="${idx}" data-order-id="${customer.daily_order_id}">
                <div class="drag-handle">⋮⋮</div>
                <div class="stop-number">${idx + 1}</div>
                <div class="stop-content">
                    <strong>${customer.customer_name}</strong><br>
                    <small>${customer.address}</small><br>
                    🕐 Arrival: ${arrival}<br>
                    ${constraintStatus}
                </div>
            </div>
        `;
    });
    routeList += '</div></div>';
    if (violations.length > 0) {
        routeList += `<div class='alert alert-warning'>⚠️ Some stops are late due to constraints. Consider starting earlier or splitting the route.</div>`;
    } else {
        routeList += `<div class='alert alert-success'>🎉 All delivery time constraints satisfied!</div>`;
    }
    document.getElementById('route-info').innerHTML = routeList;
    
    // Setup drag and drop functionality
    setupDragAndDrop();
    
    // Prepare assignments for saving
    currentAssignments = customerOrder.map((order, idx) => ({
        daily_order_id: order.daily_order_id,
        driver_id: currentDriverId,
        route_order: idx + 1,
        scheduled_delivery_time: routeWithTimes[idx].arrivalTime.toTimeString().substring(0, 5)
    }));
}

// Setup drag and drop functionality for route reordering
function setupDragAndDrop() {
    const routeList = document.querySelector('.draggable-route-list');
    if (!routeList) return;
    
    let draggedItem = null;
    let draggedIndex = null;
    
    // Add event listeners to all draggable items
    const items = routeList.querySelectorAll('.route-stop-item');
    items.forEach(item => {
        item.addEventListener('dragstart', handleDragStart);
        item.addEventListener('dragend', handleDragEnd);
        item.addEventListener('dragover', handleDragOver);
        item.addEventListener('drop', handleDrop);
        item.addEventListener('dragenter', handleDragEnter);
        item.addEventListener('dragleave', handleDragLeave);
    });
    
    function handleDragStart(e) {
        draggedItem = this;
        draggedIndex = parseInt(this.dataset.index);
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.outerHTML);
    }
    
    function handleDragEnd(e) {
        this.classList.remove('dragging');
        draggedItem = null;
        draggedIndex = null;
    }
    
    function handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }
    
    function handleDragEnter(e) {
        e.preventDefault();
        this.classList.add('drag-over');
    }
    
    function handleDragLeave(e) {
        this.classList.remove('drag-over');
    }
    
    function handleDrop(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        
        if (draggedItem === this) return;
        
        const dropIndex = parseInt(this.dataset.index);
        const items = Array.from(routeList.querySelectorAll('.route-stop-item'));
        
        // Reorder the items
        if (draggedIndex < dropIndex) {
            // Moving down
            this.parentNode.insertBefore(draggedItem, this.nextSibling);
        } else {
            // Moving up
            this.parentNode.insertBefore(draggedItem, this);
        }
        
        // Update data-index attributes and stop numbers
        const newItems = routeList.querySelectorAll('.route-stop-item');
        newItems.forEach((item, index) => {
            item.dataset.index = index;
            item.querySelector('.stop-number').textContent = index + 1;
        });
        
        // Update currentAssignments with new order
        updateAssignmentsFromDrag(newItems);
        
        // Recalculate route with new order
        recalculateRouteWithNewOrder(newItems);
    }
}

// Update assignments array after drag and drop
function updateAssignmentsFromDrag(newItems) {
    const newOrder = Array.from(newItems).map(item => {
        const orderId = item.dataset.orderId;
        return currentAssignments.find(assignment => assignment.daily_order_id == orderId);
    });
    
    // Update route_order based on new position
    newOrder.forEach((assignment, index) => {
        if (assignment) {
            assignment.route_order = index + 1;
        }
    });
    
    currentAssignments = newOrder;
}

// Recalculate route with new customer order
function recalculateRouteWithNewOrder(newItems) {
    const newCustomerOrder = Array.from(newItems).map(item => {
        const orderId = item.dataset.orderId;
        return currentAssignments.find(assignment => assignment.daily_order_id == orderId);
    }).filter(Boolean);
    
    if (newCustomerOrder.length === 0) return;
    
    // Get the original orders data
    const orders = currentAssignments.map(assignment => {
        return { daily_order_id: assignment.daily_order_id, address: '' }; // We'll need to get the full order data
    });
    
    // Recalculate route with new order
    getRouteForOrder(newCustomerOrder, orders).then(newRoute => {
        if (newRoute) {
            // Update the map with new route
            directionsRenderer.setDirections(newRoute.result);
            
            // Recalculate arrival times
            const routeWithTimes = calculateArrivalTimesForRoute(newRoute.result, newCustomerOrder);
            
            // Update assignments with new arrival times
            currentAssignments = newCustomerOrder.map((order, idx) => ({
                daily_order_id: order.daily_order_id,
                driver_id: currentDriverId,
                route_order: idx + 1,
                scheduled_delivery_time: routeWithTimes[idx].arrivalTime.toTimeString().substring(0, 5)
            }));
            
            // Update arrival times in the UI
            updateArrivalTimesInUI(routeWithTimes);
        }
    });
}

// Update arrival times in the UI after drag and drop
function updateArrivalTimesInUI(routeWithTimes) {
    const items = document.querySelectorAll('.route-stop-item');
    items.forEach((item, index) => {
        if (routeWithTimes[index]) {
            const arrival = routeWithTimes[index].arrivalTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            const arrivalElement = item.querySelector('.stop-content');
            if (arrivalElement) {
                const arrivalText = arrivalElement.innerHTML.replace(/🕐 Arrival: [^<]+/, `🕐 Arrival: ${arrival}`);
                arrivalElement.innerHTML = arrivalText;
            }
        }
    });
}

// Save optimized route
function saveOptimizedRoute() {
    if (currentAssignments.length === 0) {
        alert('No route to save');
        return;
    }
    
    saveAssignments(currentAssignments);
    closeRouteModal();
}

// Close route modal
function closeRouteModal() {
    document.getElementById('route-modal').style.display = 'none';
    currentAssignments = [];
}

// Edit assignments for a driver
function editAssignments(driverId) {
    currentDriverId = driverId;
    
    // Get current assignments for this driver
    const driverOrders = driverAssignmentConfig.ordersByDriver[driverId] || { orders: [] };
    const allOrders = driverAssignmentConfig.dailyOrders;
    
    let content = `
        <div class="edit-assignments">
            <h4>Edit Assignments for ${driverOrders.driver_name || 'Driver'}</h4>
            <div class="assignments-list">
    `;
    
    driverOrders.orders.forEach(order => {
        const isLocked = ['delivered', 'in_transit'].includes(order.delivery_status || '');
        content += `
            <div class="assignment-item${isLocked ? ' assignment-item-locked' : ''}" data-order-id="${order.id}">
                <div class="assignment-info">
                    <div class="customer-name">${order.customer_name}</div>
                    <div class="customer-address">${order.address}</div>
                    ${isLocked ? `<div class="route-stop-lock-note">${order.delivery_status === 'delivered' ? 'Completed' : 'In transit'} — kept on this route</div>` : ''}
                </div>
                <div class="assignment-controls">
                    <input type="number" class="route-order-input" value="${order.route_order || 0}" 
                           placeholder="Route Order" min="1" ${isLocked ? 'disabled' : ''}>
                    <input type="time" class="delivery-time-input" value="${order.scheduled_delivery_time || ''}" 
                           placeholder="Delivery Time" ${isLocked ? 'disabled' : ''}>
                    ${isLocked ? '' : `<button class="btn btn-sm btn-outline-danger" onclick="removeAssignment(${order.id})">Remove</button>`}
                </div>
            </div>
        `;
    });
    
    content += `
            </div>
            <div class="add-assignment">
                <h5>Add Existing Daily Orders</h5>
                <select id="add-order-select" onchange="addAssignment(this.value)">
                    <option value="">Select order to add...</option>
    `;
    
    // Add unassigned orders
    const unassignedOrders = allOrders.filter(order => !order.assigned_driver_id);
    unassignedOrders.forEach(order => {
        content += `<option value="${order.id}">${order.customer_name} - ${order.address}</option>`;
    });
    
    content += `
                </select>
                <p class="add-customer-edit-hint">
                    Need a customer without a daily order?
                    <button type="button" class="btn-link" onclick="closeEditModal(); openAddCustomerModal(${driverId});">Add customer to route…</button>
                </p>
            </div>
        </div>
    `;
    
    document.getElementById('edit-assignments-content').innerHTML = content;
    document.getElementById('edit-modal').style.display = 'flex';
}

// Add assignment
function addAssignment(orderId) {
    if (!orderId) return;
    
    const allOrders = driverAssignmentConfig.dailyOrders;
    const order = allOrders.find(o => o.id == orderId);
    if (!order) return;
    
    const assignmentsList = document.querySelector('.assignments-list');
    const nextRouteOrder = assignmentsList.querySelectorAll('.assignment-item').length + 1;
    const assignmentItem = document.createElement('div');
    assignmentItem.className = 'assignment-item';
    assignmentItem.dataset.orderId = orderId;
    assignmentItem.innerHTML = `
        <div class="assignment-info">
            <div class="customer-name">${order.customer_name}</div>
            <div class="customer-address">${order.address}</div>
        </div>
        <div class="assignment-controls">
            <input type="number" class="route-order-input" value="${nextRouteOrder}" placeholder="Route Order" min="1">
            <input type="time" class="delivery-time-input" value="" placeholder="Delivery Time">
            <button class="btn btn-sm btn-outline-danger" onclick="removeAssignment(${orderId})">Remove</button>
        </div>
    `;
    
    assignmentsList.appendChild(assignmentItem);
    document.getElementById('add-order-select').value = '';
}

// Transfer one or more stops to another driver
function transferAssignments(dailyOrderIds, fromDriverId, toDriverId, options = {}) {
    toDriverId = parseInt(toDriverId, 10);
    fromDriverId = fromDriverId ? parseInt(fromDriverId, 10) : null;

    if (!toDriverId || toDriverId <= 0) {
        alert('Choose a destination driver');
        return Promise.reject(new Error('no_target_driver'));
    }

    const orderIds = (Array.isArray(dailyOrderIds) ? dailyOrderIds : [dailyOrderIds])
        .map(id => parseInt(id, 10))
        .filter(id => id > 0);

    if (orderIds.length === 0) {
        alert('No stops selected to move');
        return Promise.reject(new Error('no_orders'));
    }

    const confirmMessage = options.confirmMessage
        || ('Move ' + orderIds.length + ' stop' + (orderIds.length === 1 ? '' : 's') + ' to the selected driver?');
    if (options.skipConfirm !== true && !confirm(confirmMessage)) {
        return Promise.reject(new Error('cancelled'));
    }

    let body = 'action=transfer_assignments'
        + '&to_driver_id=' + toDriverId
        + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
        + '&daily_order_ids=' + encodeURIComponent(JSON.stringify(orderIds));
    if (fromDriverId && fromDriverId > 0) {
        body += '&from_driver_id=' + fromDriverId;
    }

    return fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (options.reload !== false) {
                location.reload();
            }
            return data;
        }
        throw new Error(data.error || 'Transfer failed');
    })
    .catch(error => {
        if (error.message === 'cancelled') {
            throw error;
        }
        console.error('Error:', error);
        alert('Error moving stops: ' + error.message);
        throw error;
    });
}

function moveStopToDriver(orderId, fromDriverId, selectEl) {
    const toDriverId = parseInt(selectEl.value, 10);
    if (!toDriverId || toDriverId <= 0) {
        return;
    }

    transferAssignments([orderId], fromDriverId, toDriverId)
        .catch(() => {
            selectEl.value = '';
        });
}

function moveAllStops(fromDriverId) {
    const selectEl = document.getElementById('move-all-select-' + fromDriverId);
    const toDriverId = parseInt(selectEl && selectEl.value, 10);
    if (!toDriverId || toDriverId <= 0) {
        alert('Choose a destination driver from the "Move all to…" dropdown');
        return;
    }

    const routeList = document.querySelector('.route-order-list[data-driver-id="' + fromDriverId + '"]');
    const orderIds = routeList
        ? Array.from(routeList.querySelectorAll('.order-item:not(.order-item-locked)')).map(item => parseInt(item.dataset.orderId, 10))
        : [];

    if (orderIds.length === 0) {
        alert('No movable stops on this driver');
        return;
    }

    transferAssignments(orderIds, fromDriverId, toDriverId, {
        confirmMessage: 'Move all ' + orderIds.length + ' stop' + (orderIds.length === 1 ? '' : 's') + ' to the selected driver?'
    }).catch(() => {
        if (selectEl) {
            selectEl.value = '';
        }
    });
}

// Remove assignment from main view (immediate database removal)
function removeAssignmentFromDatabase(orderId) {
    if (!confirm(window.__DRIVER_ASSIGNMENT__.unassignConfirm)) {
        return;
    }

    // Find the driver ID for this order
    const orderItem = document.querySelector(`[data-order-id="${orderId}"]`);
    const driverSection = orderItem.closest('.driver-section');
    const driverId = driverSection.dataset.driverId;

    if (!driverId) {
        alert('Could not determine driver ID');
        return;
    }

    // Remove the assignment from database
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=remove_assignment&daily_order_id=' + orderId + '&driver_id=' + driverId + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // Refresh page to show updated assignments
        } else {
            alert('Error removing assignment: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error removing assignment');
    });
}

// Remove assignment from edit modal (visual only)
function removeAssignment(orderId) {
    const assignmentItem = document.querySelector(`[data-order-id="${orderId}"]`);
    if (assignmentItem) {
        assignmentItem.remove();
    }
}

// Save assignments
// mode: 'replace' rewrites the driver's route; 'append' adds without clearing existing stops
function saveAssignments(assignments = null, mode = 'replace') {
    if (!assignments) {
        // Collect assignments from edit modal
        const assignmentItems = document.querySelectorAll('#edit-assignments-content .assignment-item');
        assignments = [];
        
        assignmentItems.forEach(item => {
            const orderId = item.dataset.orderId;
            const routeOrder = item.querySelector('.route-order-input').value;
            const deliveryTime = item.querySelector('.delivery-time-input').value;
            
            if (orderId && routeOrder) {
                assignments.push({
                    daily_order_id: orderId,
                    driver_id: currentDriverId,
                    route_order: routeOrder,
                    scheduled_delivery_time: deliveryTime || null
                });
            }
        });
        mode = 'replace';
    }
    
    // Use the driver_id from the first assignment if currentDriverId is not set
    const saveMode = mode === 'append' ? 'append' : 'replace';
    const driverId = currentDriverId || (assignments[0] && assignments[0].driver_id);
    if (!driverId) {
        alert('Choose a driver before saving');
        return;
    }
    if (assignments.length === 0 && saveMode === 'append') {
        alert('Check one or more stops to add');
        return;
    }
    if (assignments.length === 0 && !confirm('Clear every movable stop from this driver\'s route for this date?')) {
        return;
    }
    
    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=assign_orders'
            + '&mode=' + encodeURIComponent(saveMode)
            + '&driver_id=' + driverId
            + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
            + '&assignments=' + encodeURIComponent(JSON.stringify(assignments))
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Assignments saved successfully');
            location.reload();
        } else {
            alert('Error saving assignments: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving assignments');
    });
}

// Close edit modal
function closeEditModal() {
    document.getElementById('edit-modal').style.display = 'none';
    currentDriverId = null;
}

function buildStandingProductOptions(selectedId) {
    const products = driverAssignmentConfig.productsForStanding || [];
    const byLine = {};
    products.forEach(p => {
        const line = p.product_line_name || 'Other';
        if (!byLine[line]) {
            byLine[line] = [];
        }
        byLine[line].push(p);
    });
    let html = '<option value="">Choose product…</option>';
    Object.keys(byLine).sort().forEach(line => {
        html += '<optgroup label="' + line.replace(/"/g, '&quot;') + '">';
        byLine[line].forEach(p => {
            const sel = parseInt(selectedId, 10) === parseInt(p.id, 10) ? ' selected' : '';
            html += '<option value="' + p.id + '"' + sel + '>' + p.name + '</option>';
        });
        html += '</optgroup>';
    });
    return html;
}

function resetAddCustomerModal() {
    document.getElementById('add-customer-search').value = '';
    document.getElementById('add-customer-save-standing-route').checked = false;
    document.getElementById('add-customer-apply-pan-dulce').value = '0';
    document.getElementById('add-customer-standing-rows').innerHTML = '';
    document.getElementById('add-customer-status').textContent = '';
    setAddCustomerStandingOptionsVisibility();
    filterAddCustomerOptions();
}

function setAddCustomerStandingOptionsVisibility() {
    const saveStandingRoute = document.getElementById('add-customer-save-standing-route')?.checked;
    const standingSection = document.getElementById('add-customer-standing-section');
    if (standingSection) {
        standingSection.hidden = !saveStandingRoute;
    }
    if (!saveStandingRoute) {
        document.getElementById('add-customer-apply-pan-dulce').value = '0';
    }
}

function openAddCustomerModal(driverId) {
    driverId = parseInt(driverId, 10);
    document.getElementById('add-customer-driver-id').value = driverId > 0 ? String(driverId) : '';
    const driverSelect = document.getElementById('add-customer-driver-select');
    if (driverSelect && driverId > 0) {
        driverSelect.value = String(driverId);
    }
    resetAddCustomerModal();
    document.getElementById('add-customer-modal').style.display = 'flex';
    window.setTimeout(() => document.getElementById('add-customer-search')?.focus(), 0);
}

function closeAddCustomerModal() {
    document.getElementById('add-customer-modal').style.display = 'none';
}

function filterAddCustomerOptions() {
    const searchEl = document.getElementById('add-customer-search');
    const selectEl = document.getElementById('add-customer-select');
    if (!searchEl || !selectEl) {
        return;
    }
    const query = searchEl.value.trim().toLowerCase();
    Array.from(selectEl.options).forEach(opt => {
        const blob = (opt.dataset.search || opt.textContent || '').toLowerCase();
        opt.hidden = query !== '' && !blob.includes(query);
    });
    const visible = Array.from(selectEl.options).filter(opt => !opt.hidden);
    if (visible.length > 0 && (!selectEl.value || selectEl.selectedOptions[0]?.hidden)) {
        selectEl.value = visible[0].value;
    }
}

function addStandingOrderRow(productId, quantity) {
    const container = document.getElementById('add-customer-standing-rows');
    const row = document.createElement('div');
    row.className = 'add-customer-standing-row';
    row.innerHTML = `
        <select class="standing-product-select">${buildStandingProductOptions(productId || '')}</select>
        <input type="number" class="standing-qty-input" min="1" step="1" value="${quantity || 1}">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.add-customer-standing-row').remove()">Remove</button>
    `;
    container.appendChild(row);
    document.getElementById('add-customer-apply-pan-dulce').value = '0';
}

function applyPanDulceInAddModal() {
    document.getElementById('add-customer-standing-rows').innerHTML = '';
    document.getElementById('add-customer-apply-pan-dulce').value = '1';
    document.getElementById('add-customer-status').textContent =
        'Pan Dulce standard will be applied when you add this customer.';
}

function collectStandingOrderLines() {
    if (!document.getElementById('add-customer-save-standing-route')?.checked) {
        return [];
    }
    const lines = [];
    document.querySelectorAll('#add-customer-standing-rows .add-customer-standing-row').forEach(row => {
        const productId = parseInt(row.querySelector('.standing-product-select')?.value, 10);
        const quantity = parseInt(row.querySelector('.standing-qty-input')?.value, 10);
        if (productId > 0 && quantity > 0) {
            lines.push({ product_id: productId, quantity: quantity });
        }
    });
    return lines;
}

function submitAddCustomerToRoute() {
    const customerId = parseInt(document.getElementById('add-customer-select')?.value, 10);
    const driverId = parseInt(document.getElementById('add-customer-driver-select')?.value, 10);
    const saveStandingRoute = document.getElementById('add-customer-save-standing-route')?.checked;
    const applyPanDulce = saveStandingRoute
        && document.getElementById('add-customer-apply-pan-dulce')?.value === '1';
    const standingOrderLines = collectStandingOrderLines();
    const statusEl = document.getElementById('add-customer-status');

    if (!customerId || customerId <= 0) {
        alert('Choose a customer');
        return;
    }
    if (!driverId || driverId <= 0) {
        alert('Choose a driver');
        return;
    }

    const assignedDriverId = parseInt(
        driverAssignmentConfig.assignedCustomerIdsToday[String(customerId)]
            || driverAssignmentConfig.assignedCustomerIdsToday[customerId]
            || 0,
        10
    );
    if (assignedDriverId > 0 && assignedDriverId === driverId) {
        alert('This customer is already on this driver\'s route today.');
        return;
    }
    if (assignedDriverId > 0 && assignedDriverId !== driverId) {
        if (!confirm('This customer is already on another driver\'s route today. Move them to the selected driver?')) {
            return;
        }
    }

    if (statusEl) {
        statusEl.textContent = 'Adding customer…';
    }

    let body = 'action=add_customer_to_route'
        + '&customer_id=' + customerId
        + '&driver_id=' + driverId
        + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date)
        + '&save_standing_route=' + (saveStandingRoute ? '1' : '0')
        + '&apply_pan_dulce=' + (applyPanDulce ? '1' : '0')
        + '&standing_order_lines=' + encodeURIComponent(JSON.stringify(standingOrderLines));

    fetch('driver_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
            return;
        }
        throw new Error(data.error || 'Failed to add customer');
    })
    .catch(error => {
        console.error('Error:', error);
        if (statusEl) {
            statusEl.textContent = '';
        }
        alert('Error adding customer: ' + error.message);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const searchEl = document.getElementById('add-customer-search');
    if (searchEl) {
        searchEl.addEventListener('input', filterAddCustomerOptions);
    }
});

function getSelectedUnassignedOrderIds() {
    return Array.from(document.querySelectorAll('.unassigned-checkbox:checked'))
        .map(cb => parseInt(cb.value, 10))
        .filter(id => id > 0);
}

function updateBulkSelectedCount() {
    const countEl = document.getElementById('bulk-selected-count');
    if (!countEl) return;
    const n = getSelectedUnassignedOrderIds().length;
    countEl.textContent = n + ' selected';
    const selectAll = document.getElementById('select-all-unassigned');
    if (selectAll) {
        const all = document.querySelectorAll('.unassigned-checkbox');
        selectAll.checked = all.length > 0 && n === all.length;
        selectAll.indeterminate = n > 0 && n < all.length;
    }
}

function toggleSelectAllUnassigned(checked) {
    document.querySelectorAll('.unassigned-checkbox').forEach(cb => {
        cb.checked = !!checked;
    });
    updateBulkSelectedCount();
}

// Append checked unassigned orders to a driver (does not replace existing route)
function assignSelectedToDriver() {
    const driverSelect = document.getElementById('bulk-driver-select');
    const driverId = parseInt(driverSelect && driverSelect.value, 10);
    if (!driverId || driverId <= 0) {
        alert('Choose a driver first');
        return;
    }

    const orderIds = getSelectedUnassignedOrderIds();
    if (orderIds.length === 0) {
        alert('Check one or more unassigned customers first');
        return;
    }

    currentDriverId = driverId;
    const assignments = orderIds.map(orderId => ({
        daily_order_id: orderId,
        driver_id: driverId,
        route_order: 0,
        scheduled_delivery_time: null
    }));

    saveAssignments(assignments, 'append');
}

function updateStandingRouteSelectStyle(selectEl) {
    const driverId = parseInt(selectEl.value, 10);
    const driverInfo = driverAssignmentConfig.driversById[String(driverId)] || driverAssignmentConfig.driversById[driverId];
    if (driverId > 0 && driverInfo) {
        selectEl.style.backgroundColor = driverInfo.color;
        selectEl.style.color = '#fff';
        selectEl.style.borderColor = 'transparent';
    } else {
        selectEl.style.backgroundColor = '';
        selectEl.style.color = '';
        selectEl.style.borderColor = '';
    }
}

function saveStandingRoute(selectEl) {
    const customerId = parseInt(selectEl.dataset.customerId, 10);
    const dayOfWeek = parseInt(selectEl.dataset.day, 10);
    const driverId = parseInt(selectEl.value, 10);

    if (!customerId || !dayOfWeek) {
        alert('Could not determine customer or day');
        return;
    }

    selectEl.disabled = true;

    fetch('driver_assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=save_standing_route'
            + '&customer_id=' + customerId
            + '&day_of_week=' + dayOfWeek
            + '&driver_id=' + driverId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateStandingRouteSelectStyle(selectEl);
            if (dayOfWeek === driverAssignmentConfig.currentDayOfWeek) {
                location.reload();
            }
        } else {
            alert('Error saving standing route: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving standing route');
    })
    .finally(() => {
        selectEl.disabled = false;
    });
}

// Assign a single order to a driver without wiping that driver's existing stops
function assignToDriver(orderId, driverId) {
    driverId = parseInt(driverId, 10);
    
    if (!driverId || driverId <= 0) {
        return;
    }
    
    currentDriverId = driverId;
    
    const assignments = [{
        daily_order_id: orderId,
        driver_id: driverId,
        route_order: 0,
        scheduled_delivery_time: null
    }];
    
    saveAssignments(assignments, 'append');
}

// Show date picker
function showDatePicker() {
    const date = prompt('Enter date (YYYY-MM-DD):', driverAssignmentConfig.date);
    if (date) {
        window.location.href = `?date=${date}`;
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    refreshAllMainViewRouteTimes();

    // Load Google Maps API with async, defer, and onload (no callback in URL)
    const script = document.createElement('script');
    script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(driverAssignmentConfig.mapsKey) + '&libraries=geometry';
    script.async = true;
    script.defer = true;
    script.onload = function() {
        if (typeof initMap === 'function') initMap();
        refreshAllMainViewRouteTimes();
    };
    document.head.appendChild(script);
    
    // Setup drag and drop for main view
    setupMainViewDragAndDrop();
    updateBulkSelectedCount();

});

// Setup drag and drop functionality for main driver assignment view
function setupMainViewDragAndDrop() {
    const routeLists = document.querySelectorAll('.route-order-list');
    let draggedItem = null;
    let draggedIndex = null;
    let sourceRouteList = null;
    let sourceDriverId = null;

    routeLists.forEach(routeList => {
        routeList.addEventListener('dragover', handleRouteListDragOver);
        routeList.addEventListener('drop', handleRouteListDrop);
        routeList.addEventListener('dragenter', handleRouteListDragEnter);
        routeList.addEventListener('dragleave', handleRouteListDragLeave);

        const items = routeList.querySelectorAll('.order-item[draggable="true"]');
        items.forEach(item => {
            item.addEventListener('dragstart', handleMainDragStart);
            item.addEventListener('dragend', handleMainDragEnd);
            item.addEventListener('dragover', handleMainDragOver);
            item.addEventListener('drop', handleMainDrop);
            item.addEventListener('dragenter', handleMainDragEnter);
            item.addEventListener('dragleave', handleMainDragLeave);
        });
    });

    function handleMainDragStart(e) {
        draggedItem = this;
        sourceRouteList = this.closest('.route-order-list');
        sourceDriverId = sourceRouteList ? sourceRouteList.dataset.driverId : null;
        draggedIndex = sourceRouteList
            ? Array.from(sourceRouteList.querySelectorAll('.order-item')).indexOf(this)
            : null;
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.dataset.orderId || '');
    }

    function handleMainDragEnd() {
        this.classList.remove('dragging');
        routeLists.forEach(list => list.classList.remove('drag-over'));
        draggedItem = null;
        draggedIndex = null;
        sourceRouteList = null;
        sourceDriverId = null;
    }

    function handleMainDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    function handleRouteListDragOver(e) {
        if (!draggedItem) {
            return;
        }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    function handleMainDragEnter(e) {
        e.preventDefault();
        if (draggedItem && draggedItem !== this) {
            this.classList.add('drag-over');
        }
    }

    function handleRouteListDragEnter(e) {
        if (!draggedItem) {
            return;
        }
        e.preventDefault();
        this.classList.add('drag-over');
    }

    function handleMainDragLeave() {
        this.classList.remove('drag-over');
    }

    function handleRouteListDragLeave(e) {
        if (!this.contains(e.relatedTarget)) {
            this.classList.remove('drag-over');
        }
    }

    function handleMainDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove('drag-over');

        if (!draggedItem || draggedItem === this) {
            return;
        }

        const targetRouteList = this.closest('.route-order-list');
        const targetDriverId = targetRouteList ? targetRouteList.dataset.driverId : null;

        if (targetRouteList && sourceRouteList && targetDriverId !== sourceDriverId) {
            moveStopBetweenDrivers(draggedItem, sourceRouteList, targetRouteList, this);
            return;
        }

        const dropIndex = Array.from(targetRouteList.querySelectorAll('.order-item')).indexOf(this);
        if (draggedIndex < dropIndex) {
            this.parentNode.insertBefore(draggedItem, this.nextSibling);
        } else {
            this.parentNode.insertBefore(draggedItem, this);
        }

        updateMainViewRouteNumbers(targetRouteList);
        saveMainViewOrder(targetDriverId, targetRouteList);
    }

    function handleRouteListDrop(e) {
        if (e.target.classList && e.target.classList.contains('order-item')) {
            return;
        }

        e.preventDefault();
        this.classList.remove('drag-over');

        if (!draggedItem || !sourceRouteList) {
            return;
        }

        const targetDriverId = this.dataset.driverId;
        if (targetDriverId !== sourceDriverId) {
            moveStopBetweenDrivers(draggedItem, sourceRouteList, this, null);
            return;
        }

        this.appendChild(draggedItem);
        updateMainViewRouteNumbers(this);
        saveMainViewOrder(targetDriverId, this);
    }

    function moveStopBetweenDrivers(item, fromList, toList, beforeItem) {
        const orderId = parseInt(item.dataset.orderId, 10);
        const fromDriverId = parseInt(fromList.dataset.driverId, 10);
        const toDriverId = parseInt(toList.dataset.driverId, 10);

        if (!orderId || !fromDriverId || !toDriverId || fromDriverId === toDriverId) {
            return;
        }

        const emptyPlaceholder = toList.querySelector('.no-orders-inline');
        if (emptyPlaceholder) {
            emptyPlaceholder.remove();
            toList.classList.remove('route-order-list-empty');
        }

        if (beforeItem) {
            toList.insertBefore(item, beforeItem);
        } else {
            toList.appendChild(item);
        }
        updateMainViewRouteNumbers(toList);

        const saveIndicator = document.createElement('div');
        saveIndicator.className = 'save-indicator';
        saveIndicator.textContent = 'Moving...';
        saveIndicator.style.cssText = 'position: absolute; top: 10px; right: 10px; background: #007bff; color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; z-index: 100;';
        const driverSection = toList.closest('.driver-section');
        driverSection.style.position = 'relative';
        driverSection.appendChild(saveIndicator);

        transferAssignments([orderId], fromDriverId, toDriverId, {
            skipConfirm: true,
            reload: false
        })
        .then(() => {
            saveIndicator.textContent = 'Moved!';
            saveIndicator.style.background = '#28a745';
            setTimeout(() => location.reload(), 600);
        })
        .catch(() => {
            fromList.appendChild(item);
            updateMainViewRouteNumbers(fromList);
            updateMainViewRouteNumbers(toList);
            if (saveIndicator.parentNode) {
                saveIndicator.parentNode.removeChild(saveIndicator);
            }
        });
    }
}

// Update route order numbers in main view
function updateMainViewRouteNumbers(routeList) {
    const items = routeList.querySelectorAll('.order-item');
    items.forEach((item, index) => {
        const routeOrderSpan = item.querySelector('.route-order');
        if (routeOrderSpan) {
            routeOrderSpan.textContent = `#${index + 1}`;
        }
    });
}

// Save the new order from main view drag and drop
function saveMainViewOrder(driverId, routeList) {
    updateMainViewRoutePresentation(routeList, null);

    const driverSection = routeList.closest('.driver-section');
    const saveIndicator = document.createElement('div');
    saveIndicator.className = 'save-indicator';
    saveIndicator.textContent = 'Saving...';
    saveIndicator.style.cssText = 'position: absolute; top: 10px; right: 10px; background: #28a745; color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; z-index: 100;';
    driverSection.style.position = 'relative';
    driverSection.appendChild(saveIndicator);

    refreshMainViewRouteTimes(routeList).then(schedule => {
        const stops = getMainViewRouteStops(routeList);
        const assignments = stops.map((stop, index) => ({
            daily_order_id: stop.orderId,
            driver_id: parseInt(driverId, 10),
            route_order: index + 1,
            scheduled_delivery_time: schedule
                ? minutesToTimeString(schedule.arrivals[index])
                : null
        }));

        return fetch('driver_assignment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=assign_orders&driver_id=' + driverId + '&delivery_date=' + encodeURIComponent(driverAssignmentConfig.date) + '&assignments=' + encodeURIComponent(JSON.stringify(assignments))
        });
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            saveIndicator.textContent = 'Saved!';
            saveIndicator.style.background = '#28a745';
            setTimeout(() => {
                if (saveIndicator.parentNode) {
                    saveIndicator.parentNode.removeChild(saveIndicator);
                }
            }, 2000);
        } else {
            saveIndicator.textContent = 'Error!';
            saveIndicator.style.background = '#dc3545';
            setTimeout(() => {
                if (saveIndicator.parentNode) {
                    saveIndicator.parentNode.removeChild(saveIndicator);
                }
            }, 3000);
            console.error('Error saving order:', data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        saveIndicator.textContent = 'Error!';
        saveIndicator.style.background = '#dc3545';
        setTimeout(() => {
            if (saveIndicator.parentNode) {
                saveIndicator.parentNode.removeChild(saveIndicator);
            }
        }, 3000);
    });
}
