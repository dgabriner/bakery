document.addEventListener('DOMContentLoaded', function() {
    let changedInputs = new Set();
    let isCompactView = false;
    let autoSaveTimeout = null;
    let saveInFlight = false;
    let saveQueued = false;
    let customerOrderCache = new Map(); // Cache for loaded customer orders
    const AUTO_SAVE_DELAY = 1500; // Auto-save after 1.5 seconds of inactivity
    
    console.log('Standing Orders Manager initialized');
    console.log(`Total customers: ${window.__STANDING_ORDERS_MANAGER__.customerCount}`);
    console.log(`Auto-collapse threshold: 50 customers`);
    
    // PERFORMANCE: Show initial load time
    const performanceInfo = document.getElementById('performance-info');
    const pageLoadTime = performance.now();
    setTimeout(() => {
        updatePerformanceInfo(`Page loaded in ${Math.round(pageLoadTime)}ms`, 'good');
        performanceInfo.style.display = 'flex';
    }, 100);
    
    // Performance check functionality
    document.getElementById('performance-check').addEventListener('click', function() {
        this.disabled = true;
        this.textContent = '⚡ Checking...';
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'check_performance' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let status = 'good';
                let message = `DB: ${data.load_time}ms`;
                
                if (data.missing_indexes.length > 0) {
                    status = 'warning';
                    message += ` (${data.missing_indexes.length} indexes missing)`;
                } else if (parseFloat(data.load_time) > 100) {
                    status = 'warning';
                    message += ' (slow)';
                }
                
                updatePerformanceInfo(message, status);
                
                if (data.missing_indexes.length > 0) {
                    showNotification(`⚠️ Performance: Missing database indexes on ${data.missing_indexes.join(', ')}. Contact administrator.`, 'warning');
                }
            }
        })
        .catch(error => {
            updatePerformanceInfo('Check failed', 'error');
        })
        .finally(() => {
            this.disabled = false;
            this.textContent = '⚡ Check Performance';
        });
    });
    
    // Route diagnostics functionality
    document.getElementById('diagnostic-routes').addEventListener('click', function() {
        this.disabled = true;
        this.textContent = '🔍 Checking...';
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'diagnostic_routes' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const diagnostics = data.diagnostics;
                let message = `Routes Table: ${diagnostics.routes_table_count} entries\n`;
                message += `Customers with routes: ${diagnostics.standing_routes.customers_with_routes}\n`;
                message += `Total route entries: ${diagnostics.standing_routes.total_routes}\n`;
                message += `Available days: ${diagnostics.standing_routes.all_days || 'None'}\n\n`;
                
                if (diagnostics.sample_customers_with_routes.length > 0) {
                    message += `Sample customers with routes:\n`;
                    diagnostics.sample_customers_with_routes.forEach(customer => {
                        message += `• ${customer.name} (Days: ${customer.route_days})\n`;
                    });
                } else {
                    message += `⚠️ No customers found with delivery routes!\n`;
                    message += `This explains why you don't see customers with routes.`;
                }
                
                alert(message);
                
                if (diagnostics.routes_table_count == 0) {
                    showNotification('⚠️ No delivery routes found in database. You need to set up delivery routes first.', 'warning');
                } else if (diagnostics.standing_routes.customers_with_routes == 0) {
                    showNotification('⚠️ Standing routes table has data but no customers are assigned to routes.', 'warning');
                }
            }
        })
        .catch(error => {
            alert('Diagnostic failed: ' + error.message);
        })
        .finally(() => {
            this.disabled = false;
            this.textContent = '🔍 Check Routes';
        });
    });
    
    function updatePerformanceInfo(text, status = 'good') {
        const performanceInfo = document.getElementById('performance-info');
        const performanceText = performanceInfo.querySelector('.performance-text');
        
        performanceInfo.className = `performance-info ${status}`;
        performanceText.textContent = text;
        
        if (status === 'warning' || status === 'error') {
            performanceInfo.style.display = 'flex';
        }
    }
    
    // Lazy loading for customer orders
    function loadCustomerOrders(customerId) {
        if (customerOrderCache.has(customerId)) {
            return Promise.resolve(customerOrderCache.get(customerId));
        }
        
        return fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'load_customer_orders',
                customer_id: customerId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                customerOrderCache.set(customerId, data.orders);
                return data.orders;
            }
            throw new Error(data.error || 'Failed to load customer orders');
        });
    }
    
    // Enhanced customer section toggle with lazy loading
    document.querySelectorAll('.customer-header').forEach(header => {
        let isToggling = false; // Prevent rapid clicking
        
        header.addEventListener('click', function(event) {
            // Don't prevent default or stop propagation unless necessary
            
            if (isToggling) {
                console.log('Toggle blocked - already in progress');
                return;
            }
            
            isToggling = true;
            
            const section = this.closest('.customer-section');
            const customerId = section.dataset.customerId;
            const ordersContainer = section.querySelector('.customer-orders');
            
            console.log(`Customer header clicked: ${section.dataset.customerName}, currently collapsed: ${section.classList.contains('collapsed')}`);
            
            if (section.classList.contains('collapsed')) {
                // Expanding - check if we need to load orders
                console.log('Expanding customer section');
                section.classList.remove('collapsed');
                
                // If this is a large dataset and orders aren't loaded, load them
                if (window.__STANDING_ORDERS_MANAGER__.largeCustomerSet && !customerOrderCache.has(customerId)) {
                    section.classList.add('loading');
                    
                    loadCustomerOrders(customerId)
                        .then(orders => {
                            // Update the UI with loaded orders
                            updateCustomerOrdersUI(customerId, orders);
                            section.classList.remove('loading');
                        })
                        .catch(error => {
                            section.classList.remove('loading');
                            showNotification(`Failed to load orders for customer: ${error.message}`, 'error');
                        });
                }
            } else {
                // Collapsing
                console.log('Collapsing customer section');
                section.classList.add('collapsed');
            }
            
            // Reset toggle lock after a short delay
            setTimeout(() => {
                isToggling = false;
            }, 300);
        });
    });
    
    function updateCustomerOrdersUI(customerId, orders) {
        // Update quantity inputs with loaded order data
        const customerSection = document.querySelector(`.customer-section[data-customer-id="${customerId}"], .customer-summary-card[data-customer-id="${customerId}"]`);
        if (!customerSection) return;
        const inputs = customerSection.querySelectorAll('.quantity-input');
        
        inputs.forEach(input => {
            const productId = input.dataset.productId;
            const dayOfWeek = input.dataset.day;
            
            if (orders[productId] && orders[productId][dayOfWeek]) {
                const quantity = orders[productId][dayOfWeek].quantity;
                input.value = quantity;
                input.dataset.original = quantity;
                updateProductTotals(input);
            }
        });
    }
    
    // One delegated handler covers the initial grid and any lazily loaded inputs.
    // The 1.5s auto-save delay already debounces typing, so a second input
    // handler only added duplicate work and timing races.
    document.addEventListener('input', function(event) {
        const input = event.target.closest('.quantity-input');
        if (!input) return;

        const original = parseInt(input.dataset.original) || 0;
        const current = parseInt(input.value) || 0;
        const productRow = input.closest('.product-row');

        if (current !== original) {
            input.classList.add('changed');
            productRow.classList.add('changed');
            changedInputs.add(input);
        } else {
            input.classList.remove('changed');
            productRow.classList.remove('changed');
            changedInputs.delete(input);
        }

        updateProductTotals(input);
        updateBulkSaveButton();
        updateChangesPanel();
        updateAutoSaveStatus(changedInputs.size > 0 ? 'pending' : 'idle');
        scheduleAutoSave();
    });
    
    // PERFORMANCE: Virtual scrolling for large datasets (basic implementation)
    if (window.__STANDING_ORDERS_MANAGER__.customerCount > 50) {
        // Collapse all customer sections by default for better performance
        document.querySelectorAll('.customer-section, .customer-summary-card').forEach(section => {
            section.classList.add('collapsed');
        });
        
        showNotification(`📊 Large dataset detected (${window.__STANDING_ORDERS_MANAGER__.customerCount} customers). Sections collapsed for better performance. Use expand/collapse buttons to manage view.`, 'info');
    }
    
    // Add expand/collapse all functionality
    const expandAllBtn = document.getElementById('expand-all-customers');
    const collapseAllBtn = document.getElementById('collapse-all-customers');
    
    if (expandAllBtn) {
        expandAllBtn.addEventListener('click', function() {
            this.disabled = true;
            this.textContent = '🔄 Expanding...';
            
            // Get all collapsed sections (both customer sections and summary cards)
            const collapsedSections = document.querySelectorAll('.customer-section.collapsed, .customer-summary-card.collapsed');
            let index = 0;
            const batchSize = 10;
            
            function expandBatch() {
                const endIndex = Math.min(index + batchSize, collapsedSections.length);
                for (let i = index; i < endIndex; i++) {
                    const section = collapsedSections[i];
                    section.classList.remove('collapsed');
                    
                    // For customer summary cards, also expand the details
                    if (section.classList.contains('customer-summary-card')) {
                        section.classList.add('expanded');
                        const details = section.querySelector('.customer-full-details');
                        if (details) {
                            details.style.display = 'block';
                        }
                        // Update the expand toggle arrow
                        const expandToggle = section.querySelector('.expand-toggle');
                        if (expandToggle) {
                            expandToggle.style.transform = 'rotate(180deg)';
                        }
                    }
                }
                index = endIndex;
                
                if (index < collapsedSections.length) {
                    setTimeout(expandBatch, 50); // Small delay to prevent blocking
                } else {
                    expandAllBtn.disabled = false;
                    expandAllBtn.textContent = '🔄 Expand All Customers';
                    showNotification(`✅ Expanded ${collapsedSections.length} customer sections`, 'success');
                }
            }
            
            expandBatch();
        });
    }
    
    if (collapseAllBtn) {
        collapseAllBtn.addEventListener('click', function() {
            this.disabled = true;
            this.textContent = '📁 Collapsing...';
            
            // Collapse all customer sections and summary cards
            document.querySelectorAll('.customer-section, .customer-summary-card').forEach(section => {
                section.classList.add('collapsed');
                
                // For customer summary cards, also collapse the details
                if (section.classList.contains('customer-summary-card')) {
                    section.classList.remove('expanded');
                    const details = section.querySelector('.customer-full-details');
                    if (details) {
                        details.style.display = 'none';
                    }
                    // Reset the expand toggle arrow
                    const expandToggle = section.querySelector('.expand-toggle');
                    if (expandToggle) {
                        expandToggle.style.transform = 'rotate(0deg)';
                    }
                }
            });
            
            const totalSections = document.querySelectorAll('.customer-section, .customer-summary-card').length;
            
            // Re-enable button
            this.disabled = false;
            this.textContent = '📁 Collapse All Customers';
            
            showNotification(`📁 Collapsed ${totalSections} customer sections`, 'info');
            console.log(`Collapse All: Processed ${totalSections} sections`);
        });
    }
    
    // Update product totals
    function updateProductTotals(changedInput) {
        const productRow = changedInput.closest('.product-row');
        const inputs = productRow.querySelectorAll('.quantity-input');
        const totalQty = productRow.querySelector('.total-qty');
        const totalValue = productRow.querySelector('.total-value');
        const quickClear = productRow.querySelector('.quick-clear');
        
        let total = 0;
        inputs.forEach(input => {
            total += parseInt(input.value) || 0;
        });
        
        totalQty.textContent = total;
        
        // Update value if price is available
        const priceElement = productRow.querySelector('.price');
        if (priceElement && totalValue) {
            const priceMatch = priceElement.textContent.match(/\$(\d+\.?\d*)/);
            if (priceMatch && total > 0) {
                const price = parseFloat(priceMatch[1]);
                totalValue.textContent = `$${(total * price).toFixed(2)}`;
                totalValue.style.display = 'block';
            } else {
                totalValue.style.display = 'none';
            }
        }
        
        // Show/hide quick clear button
        if (quickClear) {
            quickClear.style.display = total > 0 ? 'flex' : 'none';
        }
    }
    
    // Auto-save scheduling
    function scheduleAutoSave() {
        // Clear existing timeout
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
        }
        
        // Only schedule if there are changes
        if (changedInputs.size === 0) return;
        
        // Schedule new auto-save
        autoSaveTimeout = setTimeout(() => {
            performSave(true); // true indicates auto-save
        }, AUTO_SAVE_DELAY);
    }
    
    // Update bulk save button
    function updateBulkSaveButton() {
        const bulkSaveBtn = document.getElementById('bulk-save');
        if (changedInputs.size === 0) {
            bulkSaveBtn.disabled = true;
            bulkSaveBtn.textContent = '💾 Save Changes';
        } else {
            bulkSaveBtn.disabled = false;
            bulkSaveBtn.textContent = `💾 Save ${changedInputs.size} Changes`;
        }
    }
    
    // Update auto-save status
    function updateAutoSaveStatus(status, message = '') {
        const statusElement = document.getElementById('auto-save-status');
        const statusIcon = statusElement.querySelector('.status-icon');
        const statusText = statusElement.querySelector('.status-text');
        
        // Reset classes
        statusElement.className = 'auto-save-status';
        
        switch(status) {
            case 'idle':
                statusIcon.textContent = '💾';
                statusText.textContent = 'Auto-save enabled';
                break;
            case 'pending':
                statusElement.classList.add('saving');
                statusIcon.textContent = '⏱️';
                statusText.textContent = 'Changes pending...';
                break;
            case 'saving':
                statusElement.classList.add('saving');
                statusIcon.textContent = '💾';
                statusText.textContent = 'Auto-saving...';
                break;
            case 'success':
                statusElement.classList.add('success');
                statusIcon.textContent = '✅';
                statusText.textContent = message || 'Auto-saved';
                setTimeout(() => updateAutoSaveStatus('idle'), 2000);
                break;
            case 'error':
                statusElement.classList.add('error');
                statusIcon.textContent = '❌';
                statusText.textContent = 'Auto-save failed';
                setTimeout(() => updateAutoSaveStatus('idle'), 3000);
                break;
        }
    }
    
    // Unified save function
    function performSave(isAutoSave = false) {
        if (changedInputs.size === 0) return;

        // Keep saves single-flight. A slow request must not overlap with the
        // next debounce window and produce competing writes/network errors.
        if (saveInFlight) {
            saveQueued = true;
            return;
        }
        saveInFlight = true;

        // Snapshot the inputs for this request. Inputs changed while the request
        // is in flight must remain pending instead of being cleared by an older
        // successful response.
        const inputsToSave = Array.from(changedInputs);
        const updates = inputsToSave.map(input => ({
            customer_id: input.dataset.customerId,
            product_id: input.dataset.productId,
            day_of_week: input.dataset.day,
            quantity: parseInt(input.value) || 0
        }));

        const bulkSaveBtn = document.getElementById('bulk-save');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        
        // Update UI
        bulkSaveBtn.disabled = true;
        if (isAutoSave) {
            bulkSaveBtn.textContent = '💾 Auto-saving...';
            updateAutoSaveStatus('saving');
        } else {
            bulkSaveBtn.textContent = '💾 Saving...';
        }
        
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                action: 'bulk_save',
                updates: JSON.stringify(updates)
            })
        })
        .then(async response => {
            const responseText = await response.text();
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(responseText.trim() || `Save failed (HTTP ${response.status})`);
            }
            if (!response.ok) {
                throw new Error(data.error || `Save failed (HTTP ${response.status})`);
            }
            return data;
        })
        .then(data => {
            if (data.success) {
                // Update original values and clear changes
                inputsToSave.forEach((input, index) => {
                    // Only clear an input if it still has the value that was sent.
                    // A newer edit should stay in changedInputs for the next save.
                    if (String(parseInt(input.value) || 0) === String(updates[index].quantity)) {
                        input.dataset.original = input.value;
                        input.classList.remove('changed');
                        input.closest('.product-row').classList.remove('changed');
                        changedInputs.delete(input);
                    }
                });
                updateBulkSaveButton();
                updateChangesPanel();
                
                // Show success status
                if (isAutoSave) {
                    updateAutoSaveStatus('success', `Auto-saved ${data.updated} changes`);
                } else {
                    showNotification(`✅ Successfully updated ${data.updated} orders!`, 'success');
                }
            } else {
                if (isAutoSave) {
                    updateAutoSaveStatus('error');
                }
                showNotification(`❌ Error: ${data.error}`, 'error');
            }
        })
        .catch(error => {
            if (isAutoSave) {
                updateAutoSaveStatus('error');
            }
            showNotification(`❌ Network error: ${error.message}`, 'error');
        })
        .finally(() => {
            saveInFlight = false;
            updateBulkSaveButton();

            // Send the latest values once the current request has completed.
            if (saveQueued) {
                saveQueued = false;
                scheduleAutoSave();
            }
        });
    }
    
    // Manual save functionality
    document.getElementById('bulk-save').addEventListener('click', function() {
        performSave(false); // false indicates manual save
    });
    
    // Customer section toggle - REMOVED DUPLICATE HANDLER
    // The enhanced toggle with lazy loading above already handles this
    
    // View toggle
    document.getElementById('view-toggle').addEventListener('click', function() {
        isCompactView = !isCompactView;
        document.body.classList.toggle('compact-view', isCompactView);
        this.textContent = isCompactView ? '👁️ Normal View' : '👁️ Compact View';
    });
    
    // Filter toggle
    document.getElementById('filter-toggle').addEventListener('click', function() {
        const panel = document.getElementById('filters-panel');
        panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
    });
    
    // Changes panel toggle
    document.getElementById('changes-toggle').addEventListener('click', function() {
        const panel = document.getElementById('changes-panel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    });
    
    // Filters functionality
    const customerFilter = document.getElementById('customer-filter');
    const driverFilter = document.getElementById('driver-filter');
    const productLineFilter = document.getElementById('product-line-filter');
    const dayFilter = document.getElementById('day-filter');
    const coverageStatusSort = document.getElementById('coverage-status-sort');

    const quickStandingOrderForm = document.getElementById('quick-standing-order-form');
    const quickStandingOrderStatus = document.getElementById('quick-standing-order-status');
    if (quickStandingOrderForm) {
        quickStandingOrderForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const submitButton = quickStandingOrderForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            quickStandingOrderStatus.textContent = 'Saving…';
            try {
                const body = new URLSearchParams(new FormData(quickStandingOrderForm));
                body.append('action', 'save_order');
                const response = await fetch('standing_orders_manager.php', { method: 'POST', body: body.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Could not save standing order');
                quickStandingOrderStatus.textContent = 'Saved. Refreshing order coverage…';
                setTimeout(function () { window.location.reload(); }, 250);
            } catch (error) {
                quickStandingOrderStatus.textContent = error.message;
                submitButton.disabled = false;
            }
        });
    }
    
    const FILTER_STORAGE_KEY = 'preserveStandingOrdersManagerFilters';

    function persistFilters() {
        const payload = {
            customer: customerFilter ? customerFilter.value : '',
            drivers: driverFilter ? Array.from(driverFilter.selectedOptions).map(option => option.value) : [],
            productLine: productLineFilter ? productLineFilter.value : '',
            day: dayFilter ? dayFilter.value : '',
        };
        try {
            localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(payload));
        } catch (error) {
            // Ignore quota / private-mode failures; filters still work for the session.
        }
    }

    function restoreFiltersFromStorage() {
        try {
            const raw = localStorage.getItem(FILTER_STORAGE_KEY);
            if (!raw) return false;
            const payload = JSON.parse(raw);
            if (!payload || typeof payload !== 'object') return false;
            if (customerFilter && payload.customer) customerFilter.value = payload.customer;
            if (productLineFilter && payload.productLine) productLineFilter.value = payload.productLine;
            if (dayFilter && payload.day) dayFilter.value = payload.day;
            if (driverFilter && Array.isArray(payload.drivers)) {
                Array.from(driverFilter.options).forEach(option => {
                    option.selected = payload.drivers.includes(option.value);
                });
            }
            return true;
        } catch (error) {
            return false;
        }
    }

    [customerFilter, driverFilter, productLineFilter, dayFilter].forEach(filter => {
        filter.addEventListener('change', function () {
            applyFilters();
            persistFilters();
        });
    });
    if (dayFilter && quickStandingOrderForm) {
        dayFilter.addEventListener('change', function () {
            const quickDay = quickStandingOrderForm.querySelector('[name="day_of_week"]');
            if (dayFilter.value && quickDay) quickDay.value = dayFilter.value;
        });
    }
    
    document.getElementById('clear-filters').addEventListener('click', function() {
        customerFilter.value = '';
        Array.from(driverFilter.options).forEach(option => { option.selected = false; });
        productLineFilter.value = '';
        dayFilter.value = '';
        applyFilters();
        persistFilters();
    });

    document.getElementById('week-view-toggle')?.addEventListener('click', function () {
        if (typeof changedInputs !== 'undefined' && changedInputs.size > 0) {
            const proceed = window.confirm(
                'You have unsaved quantity changes. Switching week view reloads the page and will discard them. Continue?'
            );
            if (!proceed) return;
        }
        const url = new URL(window.location.href);
        const enablingFullWeek = url.searchParams.get('full_week') !== '1';
        if (enablingFullWeek) {
            url.searchParams.set('full_week', '1');
            try { localStorage.setItem('somFullWeek', '1'); } catch (error) {}
        } else {
            url.searchParams.delete('full_week');
            try { localStorage.setItem('somFullWeek', '0'); } catch (error) {}
        }
        persistFilters();
        window.location.href = url.toString();
    });

    const dayLabels = {1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun'};

    function parseDayList(value) {
        return (value || '').split(',').map(Number).filter(day => day >= 1 && day <= 7);
    }

    function parseNumberList(value) {
        return (value || '').split(',').map(Number).filter(number => number > 0);
    }

    // Apply the configured Pan Dulce standard to every active route day for one customer.
    // Values are dispatched through the normal input handler so autosave and the pending
    // changes panel treat this exactly like manually edited quantities.
    document.querySelectorAll('.apply-standard-btn').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            const customerSection = button.closest('.customer-section, .customer-summary-card');
            if (!customerSection) return;

            // Expand collapsed no-route cards so inputs exist in an open panel.
            const details = customerSection.querySelector('.customer-full-details');
            if (details && details.style.display === 'none') {
                details.style.display = 'block';
                const toggle = customerSection.querySelector('.expand-toggle');
                if (toggle) toggle.textContent = '▲';
            }

            const multiplier = Number(button.dataset.multiplier || 1);
            const rows = customerSection.querySelectorAll('.product-row[data-standard-enabled="1"]');
            const routeDays = parseDayList(customerSection.dataset.routeDays);
            const fullWeek = document.querySelector('.som-container')?.dataset.fullWeek === '1'
                || customerSection.classList.contains('no-route-customer');
            let updated = 0;
            rows.forEach(function (row) {
                const standard = Number(row.dataset.standardQuantity || 0);
                const quantity = Math.round(standard * multiplier);
                row.querySelectorAll('.quantity-input').forEach(function (input) {
                    const day = Number(input.dataset.day);
                    // Route-focused mode: only fill route days. Full week / no-route: fill visible edit days.
                    if (!fullWeek && routeDays.length && !routeDays.includes(day)) {
                        return;
                    }
                    input.value = quantity;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    updated++;
                });
            });

            if (updated > 0) {
                // Save immediately instead of waiting for debounce or requiring
                // the user to find the separate Save Changes button.
                if (autoSaveTimeout) {
                    clearTimeout(autoSaveTimeout);
                    autoSaveTimeout = null;
                }
                performSave(true);
            } else {
                showNotification('No Pan Dulce standard quantities are configured for this customer.', 'warning');
            }
        });
    });

    function sortCoverageRows() {
        const tbody = document.querySelector('#day-coverage-table tbody');
        if (!tbody) return;
        const mode = coverageStatusSort ? coverageStatusSort.value : 'default';
        const rank = mode === 'attention'
            ? {'route-empty': 1, 'no-route-orders': 2, 'route-orders': 3, 'no-route-empty': 4}
            : {[mode]: 1};
        Array.from(tbody.querySelectorAll('.coverage-row'))
            .sort(function (a, b) {
                if (mode === 'default') return Number(a.dataset.originalIndex) - Number(b.dataset.originalIndex);
                if (mode === 'route') {
                    const aRoute = parseDayList(a.dataset.routeDays);
                    const bRoute = parseDayList(b.dataset.routeDays);
                    return (aRoute[0] || 99) - (bRoute[0] || 99)
                        || a.dataset.customerName.localeCompare(b.dataset.customerName);
                }
                const aRank = rank[a.dataset.coverageStatus] || 99;
                const bRank = rank[b.dataset.coverageStatus] || 99;
                return aRank - bRank || a.dataset.customerName.localeCompare(b.dataset.customerName);
            })
            .forEach(function (row) { tbody.appendChild(row); });
    }

    function updateCoverage(dayValue, customerValue, driverValues) {
        const selectedDay = dayValue ? Number(dayValue) : null;
        const coverageLabel = document.getElementById('coverage-day-label');
        coverageLabel.textContent = selectedDay ? dayLabels[selectedDay] : 'All days';

        const counts = {
            routeOrders: 0,
            routeEmpty: 0,
            noRouteOrders: 0,
            noRouteEmpty: 0
        };

        document.querySelectorAll('.coverage-row').forEach(row => {
            const routeDays = parseDayList(row.dataset.routeDays);
            const orderDays = parseDayList(row.dataset.orderDays);
            const driverIds = parseNumberList(row.dataset.driverIds);
            const customerMatches = !customerValue || row.dataset.customerId === customerValue;
            const driverMatches = !driverValues.length || driverValues.some(driverId => driverIds.includes(Number(driverId)));
            const hasRoute = selectedDay ? routeDays.includes(selectedDay) : routeDays.length > 0;
            const hasOrders = selectedDay ? orderDays.includes(selectedDay) : orderDays.length > 0;
            const visible = customerMatches && driverMatches;
            row.style.display = visible ? '' : 'none';
            if (!visible) return;

            const status = row.querySelector('.coverage-status');
            const routeCell = row.querySelector('.coverage-route-cell');
            const ordersCell = row.querySelector('.coverage-orders-cell');
            const actionsCell = row.querySelector('.coverage-actions-cell');
            status.className = 'coverage-status';
            row.classList.remove('coverage-highlight');
            if (actionsCell) actionsCell.textContent = '—';

            if (hasRoute && hasOrders) {
                counts.routeOrders++;
                row.dataset.coverageStatus = 'route-orders';
                status.classList.add('route-orders');
                status.textContent = 'Route + orders';
            } else if (hasRoute) {
                counts.routeEmpty++;
                row.dataset.coverageStatus = 'route-empty';
                status.classList.add('route-empty');
                status.textContent = 'Route — add products';
                row.classList.add('coverage-highlight');
                if (actionsCell) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm btn-primary coverage-resolve-btn';
                    btn.textContent = 'Apply Pan Dulce';
                    btn.title = 'Set standard Pan Dulce standing order for this customer'
                        + (selectedDay ? (' on ' + dayLabels[selectedDay]) : ' on all route days');
                    btn.dataset.customerId = row.dataset.customerId;
                    if (selectedDay) btn.dataset.dayOfWeek = String(selectedDay);
                    actionsCell.textContent = '';
                    actionsCell.appendChild(btn);
                }
            } else if (hasOrders) {
                counts.noRouteOrders++;
                row.dataset.coverageStatus = 'no-route-orders';
                status.classList.add('no-route-orders');
                status.textContent = 'Orders — no route';
                row.classList.add('coverage-highlight');
            } else {
                counts.noRouteEmpty++;
                row.dataset.coverageStatus = 'no-route-empty';
                status.classList.add('no-route-empty');
                status.textContent = 'No route / no orders';
            }

            routeCell.textContent = selectedDay
                ? (hasRoute ? `Yes — ${dayLabels[selectedDay]}` : 'No route')
                : (routeDays.length ? routeDays.map(day => dayLabels[day]).join(', ') : 'No route');

            let orderDetails = {};
            try { orderDetails = JSON.parse(row.dataset.orderDetails || '{}'); } catch (error) { orderDetails = {}; }
            if (selectedDay) {
                const detail = orderDetails[selectedDay];
                ordersCell.textContent = detail
                    ? `${detail.product_count} product${detail.product_count == 1 ? '' : 's'} / ${detail.quantity_total} qty`
                    : 'No orders';
            } else {
                const orderSummary = Object.values(orderDetails);
                const productCount = orderSummary.reduce((total, detail) => total + Number(detail.product_count || 0), 0);
                const quantityTotal = orderSummary.reduce((total, detail) => total + Number(detail.quantity_total || 0), 0);
                ordersCell.textContent = orderSummary.length
                    ? `${orderSummary.length} day${orderSummary.length == 1 ? '' : 's'} / ${productCount} products / ${quantityTotal} qty`
                    : 'No orders';
            }
        });

        document.getElementById('coverage-route-orders').textContent = counts.routeOrders;
        document.getElementById('coverage-route-empty').textContent = counts.routeEmpty;
        document.getElementById('coverage-no-route-orders').textContent = counts.noRouteOrders;
        document.getElementById('coverage-no-route-empty').textContent = counts.noRouteEmpty;
        sortCoverageRows();
    }
    
    function applyFilters() {
        const customerValue = customerFilter.value;
        const driverValues = Array.from(driverFilter.selectedOptions).map(option => option.value);
        const productLineValue = productLineFilter.value;
        const dayValue = dayFilter.value;
        const fullWeek = document.querySelector('.som-container')?.dataset.fullWeek === '1';
        
        // Filter customer sections
        document.querySelectorAll('.customer-section, .customer-summary-card').forEach(section => {
            const customerId = section.dataset.customerId;
            const routeDays = parseDayList(section.dataset.routeDays);
            const driverIds = parseNumberList(section.dataset.driverIds);
            const orderDays = parseDayList(section.dataset.orderDays);
            const editDays = parseDayList(section.dataset.editDays);
            const driverMatches = !driverValues.length || driverValues.some(driverId => driverIds.includes(Number(driverId)));
            let showCustomer = (!customerValue || customerId === customerValue) && driverMatches;
            if (showCustomer && dayValue) {
                const selectedDay = Number(dayValue);
                // No-route / full-week customers can edit any day. Otherwise show if the
                // selected day is a route day, an existing order day, or an edit column.
                if (!routeDays.length || fullWeek || section.classList.contains('no-route-customer')) {
                    showCustomer = editDays.length ? editDays.includes(selectedDay) : true;
                } else {
                    showCustomer = routeDays.includes(selectedDay)
                        || orderDays.includes(selectedDay)
                        || editDays.includes(selectedDay);
                }
            }
            section.style.display = showCustomer ? 'block' : 'none';
        });

        // Keep empty zones/sections from taking up space after a day filter is applied.
        document.querySelectorAll('.zone-section').forEach(zone => {
            const hasVisibleCustomer = Array.from(zone.querySelectorAll('.customer-section, .customer-summary-card'))
                .some(section => section.style.display !== 'none');
            zone.style.display = hasVisibleCustomer ? '' : 'none';
        });
        document.querySelectorAll('.no-orders-section, .with-orders-section').forEach(section => {
            const hasVisibleZone = Array.from(section.querySelectorAll('.zone-section'))
                .some(zone => zone.style.display !== 'none');
            section.style.display = hasVisibleZone ? '' : 'none';
        });
        
        // Filter product line sections
        document.querySelectorAll('.product-line-section').forEach(section => {
            const productLine = section.dataset.productLine;
            const showProductLine = !productLineValue || productLine === productLineValue;
            section.style.display = showProductLine ? 'block' : 'none';
        });
        
        // Filter day columns
        // Hide both headers and quantity cells by their explicit day metadata.
        document.querySelectorAll('.day-column[data-day], .quantity-cell[data-day]').forEach(cell => {
            cell.style.display = !dayValue || cell.dataset.day === dayValue ? '' : 'none';
        });

        updateCoverage(dayValue, customerValue, driverValues);
    }

    document.querySelectorAll('.coverage-row').forEach(function (row, index) {
        row.dataset.originalIndex = index;
    });
    if (coverageStatusSort) coverageStatusSort.addEventListener('change', sortCoverageRows);

    document.getElementById('day-coverage-table')?.addEventListener('click', async function (event) {
        const button = event.target.closest('.coverage-resolve-btn');
        if (!button || button.disabled) return;
        event.preventDefault();

        const customerId = button.dataset.customerId;
        const dayOfWeek = button.dataset.dayOfWeek || '';
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Applying…';

        try {
            const body = new URLSearchParams({
                action: 'apply_pan_dulce_standard',
                customer_id: customerId,
                multiplier: '1',
            });
            if (dayOfWeek) body.append('day_of_week', dayOfWeek);

            const response = await fetch('standing_orders_manager.php', {
                method: 'POST',
                body: body.toString(),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Could not apply Pan Dulce standard');
            }

            showNotification(
                'Applied standard Pan Dulce order (' + data.products + ' products, '
                    + data.days.length + ' day' + (data.days.length === 1 ? '' : 's') + ').',
                'success'
            );
            setTimeout(function () { window.location.reload(); }, 400);
        } catch (error) {
            button.disabled = false;
            button.textContent = originalText;
            showNotification(error.message, 'error');
        }
    });

    // Restore filters / full-week preference, then apply. Deep-link customer_id wins.
    (function initStandingOrdersViewState() {
        const params = new URLSearchParams(window.location.search);
        const customerId = params.get('customer_id');
        const hasFullWeekParam = params.has('full_week');

        // Remember full-week preference across visits (legacy parity with classic page).
        if (!hasFullWeekParam) {
            try {
                if (localStorage.getItem('somFullWeek') === '1') {
                    params.set('full_week', '1');
                    const next = window.location.pathname + '?' + params.toString() + window.location.hash;
                    window.location.replace(next);
                    return;
                }
            } catch (error) {}
        }

        let restored = false;
        if (!customerId) {
            restored = restoreFiltersFromStorage();
        }

        if (customerId && customerFilter) {
            const option = Array.from(customerFilter.options).find(function (opt) {
                return opt.value === String(customerId);
            });
            if (option) {
                customerFilter.value = String(customerId);
                const panel = document.getElementById('filters-panel');
                if (panel) panel.style.display = 'flex';
            }
        } else if (restored) {
            const panel = document.getElementById('filters-panel');
            if (panel && (customerFilter.value || dayFilter.value || productLineFilter.value
                || (driverFilter && Array.from(driverFilter.selectedOptions).length))) {
                panel.style.display = 'flex';
            }
        }

        applyFilters();

        if (customerId) {
            const section = document.querySelector(
                '.customer-section[data-customer-id="' + customerId + '"], .customer-summary-card[data-customer-id="' + customerId + '"]'
            );
            if (section) {
                section.classList.remove('collapsed');
                const details = section.querySelector('.customer-full-details');
                if (details) details.style.display = 'block';
                section.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        }
    })();
    
    // Notification system
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 6px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#007bff'};
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            transform: translateX(100%);
            transition: transform 0.3s;
        `;
        
        document.body.appendChild(notification);
        
        // Animate in
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);
        
        // Auto remove
        setTimeout(() => {
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 3000);
    }
    
    // Update changes panel
    function updateChangesPanel() {
        const changesCount = document.getElementById('changes-count');
        const changesList = document.getElementById('changes-list');
        
        changesCount.textContent = `${changedInputs.size} changes`;
        
        if (changedInputs.size === 0) {
            changesList.innerHTML = '<p class="no-changes">No changes pending</p>';
        } else {
            let html = '';
            changedInputs.forEach(input => {
                const productRow = input.closest('.product-row');
                const customerSection = input.closest('.customer-section, .customer-summary-card');
                const customerName = customerSection ? customerSection.dataset.customerName : 'Customer';
                const productName = productRow.querySelector('.product-name').textContent;
                const dayName = input.dataset.day;
                const dayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                const original = input.dataset.original || '0';
                const current = input.value || '0';
                
                html += `<div class="change-item">
                    <strong>${customerName}</strong> - ${productName}<br>
                    ${dayNames[dayName]}: ${original} → ${current}
                </div>`;
            });
            changesList.innerHTML = html;
        }
    }
    
    // Clear product quantities
    window.clearProduct = function(button) {
        const productRow = button.closest('.product-row');
        const inputs = productRow.querySelectorAll('.quantity-input');
        
        inputs.forEach(input => {
            input.value = '0';
            input.dispatchEvent(new Event('input'));
        });
    };
    
    // Toggle dough type sections (legacy support)
    window.toggleDoughType = function(header) {
        const section = header.closest('.dough-type-section');
        section.classList.toggle('collapsed');
    };
    
    // Toggle product line sections
    window.toggleProductLine = function(header) {
        const section = header.closest('.product-line-section');
        section.classList.toggle('collapsed');
    };
    
    // Toggle dough type subsections within product lines
    window.toggleDoughTypeSubsection = function(header) {
        const subsection = header.closest('.dough-type-subsection');
        subsection.classList.toggle('collapsed');
    };
    
    // Toggle customer details in summary cards
    window.toggleCustomerDetails = function(header) {
        const card = header.closest('.customer-summary-card');
        const details = card.querySelector('.customer-full-details');
        
        if (!card || !details) {
            console.warn('toggleCustomerDetails: Missing card or details element');
            return;
        }
        
        // Toggle the expanded state
        const isCurrentlyExpanded = card.classList.contains('expanded');
        
        if (isCurrentlyExpanded) {
            // Collapsing
            card.classList.remove('expanded');
            details.style.display = 'none';
        } else {
            // Expanding
            card.classList.add('expanded');
            details.style.display = 'block';
        }
        
        // Update the expand toggle arrow
        const expandToggle = header.querySelector('.expand-toggle');
        if (expandToggle) {
            expandToggle.style.transform = isCurrentlyExpanded ? 'rotate(0deg)' : 'rotate(180deg)';
        }
    };
    
    // Zone toggle functionality
    document.querySelectorAll('.zone-toggle').forEach(header => {
        header.addEventListener('click', function() {
            const section = this.closest('.zone-section');
            section.classList.toggle('collapsed');
        });
    });
    
    // Copy Orders Modal functionality
    const copyOrdersModal = document.getElementById('copy-orders-modal');
    const modalOverlay = document.getElementById('modal-overlay');
    const sourceCustomerSelect = document.getElementById('source-customer-select');
    const daySelection = document.getElementById('day-selection');
    const previewSection = document.getElementById('preview-section');
    const copyConfirm = document.getElementById('copy-confirm');
    let currentTargetCustomerId = null;
    let currentTargetDay = null; // null means copy all days
    let currentCopyType = 'all'; // 'all' or 'day'
    
    // Store all customer data for the modal
    const customersData = window.__STANDING_ORDERS_MANAGER__.customersData;
    
    // Open copy orders modal for specific day
    document.querySelectorAll('.day-copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentTargetCustomerId = this.dataset.targetCustomer;
            currentTargetDay = parseInt(this.dataset.targetDay);
            currentCopyType = 'day';
            
            document.getElementById('target-customer-name').textContent = this.dataset.customerName;
            document.getElementById('target-day-info').textContent = this.dataset.dayName;
            
            populateSourceCustomers();
            showModal();
        });
    });
    
    // Open copy orders modal for all days
    document.querySelectorAll('.copy-all-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentTargetCustomerId = this.dataset.targetCustomer;
            currentTargetDay = null;
            currentCopyType = 'all';
            
            document.getElementById('target-customer-name').textContent = this.dataset.customerName;
            document.getElementById('target-day-info').textContent = 'All Days';
            
            populateSourceCustomers();
            showModal();
        });
    });
    
    // Source customer selection
    sourceCustomerSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            const availableDays = selectedOption.dataset.days.split(',');
            showDaySelection(availableDays);
        } else {
            hideDaySelection();
            hidePreview();
        }
    });
    
    // Day selection checkboxes
    document.querySelectorAll('#day-selection input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updatePreview();
        });
    });
    
    // Copy confirmation
    copyConfirm.addEventListener('click', function() {
        const sourceCustomerId = sourceCustomerSelect.value;
        const selectedDays = Array.from(document.querySelectorAll('#day-selection input[type="checkbox"]:checked'))
            .map(cb => parseInt(cb.value));
        
        if (!sourceCustomerId) {
            showNotification('❌ Please select a source customer', 'error');
            return;
        }
        
        if (selectedDays.length === 0) {
            showNotification('❌ Please select at least one day to copy', 'error');
            return;
        }
        
        this.disabled = true;
        this.textContent = '📋 Copying...';
        
        const sourceCustomerName = sourceCustomerSelect.options[sourceCustomerSelect.selectedIndex].text;
        const targetCustomerName = document.getElementById('target-customer-name').textContent;
        const dayNames = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        const dayText = selectedDays.map(d => dayNames[d]).join(', ');
        
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'copy_orders',
                source_customer_id: sourceCustomerId,
                target_customer_id: currentTargetCustomerId,
                selected_days: JSON.stringify(selectedDays)
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(`✅ Successfully copied ${data.copied} orders from ${sourceCustomerName.split(' (')[0]} to ${targetCustomerName} for ${dayText}!`, 'success');
                hideModal();
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                showNotification(`❌ Error: ${data.error}`, 'error');
            }
        })
        .catch(error => {
            showNotification(`❌ Network error: ${error.message}`, 'error');
        })
        .finally(() => {
            this.disabled = false;
            this.textContent = '📋 Copy Orders';
        });
    });
    
    // Modal controls
    function showModal() {
        copyOrdersModal.style.display = 'block';
        modalOverlay.style.display = 'block';
        document.body.style.overflow = 'hidden';
        resetModal();
    }
    
    function hideModal() {
        copyOrdersModal.style.display = 'none';
        modalOverlay.style.display = 'none';
        document.body.style.overflow = '';
        resetModal();
    }
    
    function resetModal() {
        sourceCustomerSelect.value = '';
        hideDaySelection();
        hidePreview();
        copyConfirm.disabled = true;
    }
    
    function showDaySelection(availableDays) {
        // Reset all checkboxes
        document.querySelectorAll('#day-selection input[type="checkbox"]').forEach(cb => {
            cb.checked = false;
            cb.disabled = true;
            cb.parentElement.style.opacity = '0.5';
        });
        
        // For day-specific copy, only enable and auto-select the target day
        if (currentCopyType === 'day') {
            const targetCheckbox = document.querySelector(`#day-selection input[value="${currentTargetDay}"]`);
            if (targetCheckbox && availableDays.includes(currentTargetDay.toString())) {
                targetCheckbox.disabled = false;
                targetCheckbox.checked = true;
                targetCheckbox.parentElement.style.opacity = '1';
                
                // Disable all other days for single-day copy
                document.querySelectorAll('#day-selection input[type="checkbox"]').forEach(cb => {
                    if (cb.value != currentTargetDay) {
                        cb.disabled = true;
                        cb.parentElement.style.opacity = '0.3';
                    }
                });
                
                // Auto-update preview since day is pre-selected
                setTimeout(updatePreview, 100);
            }
        } else {
            // For copy all, enable all available days
            availableDays.forEach(day => {
                const checkbox = document.querySelector(`#day-selection input[value="${day}"]`);
                if (checkbox) {
                    checkbox.disabled = false;
                    checkbox.parentElement.style.opacity = '1';
                }
            });
        }
        
        // Update label based on copy type
        const daySelectionLabel = document.getElementById('day-selection-label');
        if (currentCopyType === 'day') {
            daySelectionLabel.textContent = 'Copy this specific day:';
        } else {
            daySelectionLabel.textContent = 'Select days to copy:';
        }
        
        daySelection.style.display = 'block';
        if (currentCopyType !== 'day') {
            hidePreview();
        }
    }
    
    function hideDaySelection() {
        daySelection.style.display = 'none';
    }
    
    function hidePreview() {
        previewSection.style.display = 'none';
        copyConfirm.disabled = true;
    }
    
    function populateSourceCustomers() {
        // Clear existing options
        sourceCustomerSelect.innerHTML = '<option value="">Select a customer...</option>';
        
        // Filter customers based on copy type
        let eligibleCustomers = [];
        
        if (currentCopyType === 'day') {
            // For day-specific copy, show customers who have orders on that specific day
            eligibleCustomers = customersData.filter(customer => {
                return customer.has_orders && 
                       customer.route_days.includes(currentTargetDay) &&
                       customer.id != currentTargetCustomerId;
            });
        } else {
            // For copy all, show all customers with any orders
            eligibleCustomers = customersData.filter(customer => {
                return customer.has_orders && customer.id != currentTargetCustomerId;
            });
        }
        
        // Add options
        eligibleCustomers.forEach(customer => {
            const option = document.createElement('option');
            option.value = customer.id;
            option.textContent = `${customer.name} (${customer.zone}) - ${customer.route_days.map(d => ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'][d]).join(', ')}`;
            option.dataset.days = customer.route_days.join(',');
            option.dataset.zone = customer.zone;
            sourceCustomerSelect.appendChild(option);
        });
        
        // Show info message
        const sourceInfo = document.getElementById('source-info');
        if (eligibleCustomers.length === 0) {
            sourceInfo.innerHTML = `<small class="text-muted">No customers found with orders ${currentCopyType === 'day' ? 'on ' + ['','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'][currentTargetDay] : 'available'}</small>`;
            sourceInfo.style.display = 'block';
        } else {
            sourceInfo.innerHTML = `<small class="text-muted">${eligibleCustomers.length} customers available ${currentCopyType === 'day' ? 'with orders on this day' : 'with orders'}</small>`;
            sourceInfo.style.display = 'block';
        }
    }
    
    function updatePreview() {
        const selectedDays = Array.from(document.querySelectorAll('#day-selection input[type="checkbox"]:checked'))
            .map(cb => ({
                value: parseInt(cb.value),
                name: cb.dataset.dayName
            }));
        
        if (selectedDays.length === 0) {
            hidePreview();
            return;
        }
        
        const sourceCustomerId = sourceCustomerSelect.value;
        const sourceCustomerName = sourceCustomerSelect.options[sourceCustomerSelect.selectedIndex].text;
        
        // Show preview
        let previewHtml = `<div class="preview-item">
            <strong>Source:</strong> ${sourceCustomerName}<br>
            <strong>Target:</strong> ${document.getElementById('target-customer-name').textContent}<br>
            <strong>Copy Type:</strong> ${currentCopyType === 'day' ? 'Single Day' : 'Multiple Days'}<br>
            <strong>Days:</strong> ${selectedDays.map(d => d.name).join(', ')}<br>
            <em>All products with orders on these days will be copied.</em>
        </div>`;
        
        document.getElementById('copy-preview').innerHTML = previewHtml;
        previewSection.style.display = 'block';
        copyConfirm.disabled = false;
    }
    
    // Close modal events
    document.querySelector('.modal-close').addEventListener('click', hideModal);
    document.querySelector('.modal-cancel').addEventListener('click', hideModal);
    modalOverlay.addEventListener('click', hideModal);
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && copyOrdersModal.style.display === 'block') {
            hideModal();
        }
    });
});
