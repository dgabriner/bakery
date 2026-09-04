document.addEventListener('DOMContentLoaded', function() {
    const zoneFilter = document.getElementById('zoneFilter');
    const driverFilter = document.getElementById('driverFilter');
    const listViewRadio = document.getElementById('listView');
    const tableViewRadio = document.getElementById('tableView');
    const listViewContainer = document.querySelector('.list-view');
    const tableViewContainer = document.querySelector('.table-view');
    
    // View toggle functionality
    function toggleView() {
        if (tableViewRadio.checked) {
            listViewContainer.classList.remove('active');
            tableViewContainer.classList.add('active');
            filterTableView();
        } else {
            tableViewContainer.classList.remove('active');
            listViewContainer.classList.add('active');
            filterListView();
        }
    }
    
    // Filter function for list view
    function filterListView() {
        const selectedZone = zoneFilter.value;
        const selectedDriver = driverFilter.value;
        
        document.querySelectorAll('.list-view .driver-section').forEach(driverSection => {
            let showDriver = true;
            
            // Filter by zone
            if (selectedZone !== 'all') {
                const driverId = driverSection.dataset.driverId;
                if (driverId !== selectedZone) {
                    showDriver = false;
                }
            }
            
            if (showDriver) {
                // Filter stops by driver within each driver
                const zoneGroups = driverSection.querySelectorAll('.zone-group');
                let hasVisibleContent = false;
                
                zoneGroups.forEach(zoneGroup => {
                    const zoneName = zoneGroup.dataset.zone;
                    let showZone = true;
                    
                    // Filter by driver
                    if (selectedDriver !== 'all') {
                        const zoneDriverId = zoneGroup.dataset.driverId;
                        if (zoneDriverId !== selectedDriver) {
                            showZone = false;
                        }
                    }
                    
                    zoneGroup.style.display = showZone ? 'block' : 'none';
                    if (showZone) hasVisibleContent = true;
                });
                
                // Hide driver if no content is visible
                if (!hasVisibleContent && (selectedZone !== 'all' || selectedDriver !== 'all')) {
                    showDriver = false;
                }
            }
            
            driverSection.style.display = showDriver ? 'block' : 'none';
        });
    }
    
    // Filter function for table view
    function filterTableView() {
        const selectedZone = zoneFilter.value;
        const selectedDriver = driverFilter.value;
        
        document.querySelectorAll('.customer-table').forEach(table => {
            let showTable = true;
            
            // Filter by zone
            if (selectedZone !== 'all') {
                const zoneName = table.dataset.zone;
                if (zoneName !== selectedZone) {
                    showTable = false;
                }
            }
            
            table.style.display = showTable ? 'block' : 'none';
            
            if (showTable) {
                // Filter customer rows and day cells
                const customerRows = table.querySelectorAll('.customer-row');
                
                customerRows.forEach(row => {
                    let showRow = true;
                    
                    // Filter by driver (check if customer has any assignments with this driver)
                    if (selectedDriver !== 'all') {
                        const dayAssignments = row.querySelectorAll('.day-assignment');
                        let hasDriverAssignment = false;
                        
                        dayAssignments.forEach(assignment => {
                            if (assignment.dataset.driverId === selectedDriver) {
                                hasDriverAssignment = true;
                            }
                        });
                        
                        if (!hasDriverAssignment) {
                            showRow = false;
                        }
                    }
                    
                    row.style.display = showRow ? 'table-row' : 'none';
                    
                    if (showRow) {
                        // Filter day cells within each row
                        const dayCells = row.querySelectorAll('.day-cell');
                        dayCells.forEach(cell => {
                            const day = cell.dataset.day;
                            const showDayCell = selectedZone === 'all' || day === selectedZone;
                            cell.style.display = showDayCell ? 'table-cell' : 'none';
                        });
                    }
                });
            }
        });
    }
    
    // Main filter function that calls appropriate view filter
    function applyFilters() {
        if (tableViewRadio.checked) {
            filterTableView();
        } else {
            filterListView();
        }
    }
    
    // Add event listeners
    listViewRadio.addEventListener('change', toggleView);
    tableViewRadio.addEventListener('change', toggleView);
    zoneFilter.addEventListener('change', applyFilters);
    driverFilter.addEventListener('change', applyFilters);
    
    // Add click handlers for driver legend items to filter by driver
    document.querySelectorAll('.driver-legend-item').forEach(item => {
        item.addEventListener('click', function() {
            const driverName = this.querySelector('span').textContent;
            const driverOption = Array.from(driverFilter.options).find(option => option.text === driverName);
            if (driverOption) {
                driverFilter.value = driverOption.value;
                applyFilters();
            }
        });
    });
    
    // Inline Order Details functionality
    function addCustomerClickHandlers() {
        // List view - stop items
        document.querySelectorAll('.stop-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.stopPropagation();
                const customerId = this.dataset.customerId;
                const customerName = this.querySelector('.customer-name').textContent;
                console.log('Stop item clicked:', { customerId, customerName });
                toggleOrderDetails(this, customerId, customerName);
            });
        });
        
        // Table view - customer rows
        document.querySelectorAll('.customer-row').forEach(row => {
            row.addEventListener('click', function(e) {
                e.stopPropagation();
                const customerId = this.dataset.customerId;
                const customerName = this.querySelector('.table-customer-name').textContent;
                console.log('Customer row clicked:', { customerId, customerName });
                // For table view, we'll add inline details if needed
            });
        });
    }
    
    function toggleOrderDetails(stopItem, customerId, customerName) {
        const detailsContainer = stopItem.querySelector('.order-details-container');
        const loadingDiv = stopItem.querySelector('.order-details-loading');
        const contentDiv = stopItem.querySelector('.order-details-content');
        
        // If already expanded, collapse
        if (detailsContainer.style.display === 'block') {
            detailsContainer.style.display = 'none';
            detailsContainer.classList.remove('expanded');
            return;
        }
        
        // Show container and loading
        detailsContainer.style.display = 'block';
        loadingDiv.style.display = 'block';
        contentDiv.style.display = 'none';
        detailsContainer.classList.add('expanded');
        
        // Load order details via AJAX
        const requestData = `customer_id=${customerId}&date=${encodeURIComponent('window.__DRIVER_OVERVIEW__.selectedDate')}`;
        console.log('Sending request data:', requestData);
        
        fetch('get_customer_order_details.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: requestData
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            loadingDiv.style.display = 'none';
            contentDiv.style.display = 'block';
            
            if (data.success) {
                displayInlineOrderDetails(contentDiv, data.order, data.products);
            } else {
                contentDiv.innerHTML = '<div class="no-products">Error loading order details: ' + data.message + '</div>';
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            loadingDiv.style.display = 'none';
            contentDiv.style.display = 'block';
            contentDiv.innerHTML = '<div class="no-products">Error loading order details. Please try again.</div>';
        });
    }
    
    function displayInlineOrderDetails(container, order, products) {
        console.log('Displaying inline order details:', { order, products });
        
        // Group products by product line and dough type
        const groupedProducts = {};
        
        if (products && products.length > 0) {
            products.forEach(product => {
                const productLine = product.product_line || 'Other';
                const doughType = product.dough_type || 'Standard';
                
                if (!groupedProducts[productLine]) {
                    groupedProducts[productLine] = {};
                }
                if (!groupedProducts[productLine][doughType]) {
                    groupedProducts[productLine][doughType] = [];
                }
                
                groupedProducts[productLine][doughType].push(product);
            });
        }
        
        // Build HTML for grouped products
        let productsHtml = '';
        
        if (Object.keys(groupedProducts).length === 0) {
            productsHtml = '<div class="no-products">No products found for this order.</div>';
        } else {
            productsHtml = '<div class="product-groups">';
            Object.keys(groupedProducts).forEach(productLine => {
                Object.keys(groupedProducts[productLine]).forEach(doughType => {
                    const productsInGroup = groupedProducts[productLine][doughType];
                    
                    productsInGroup.forEach(product => {
                        const totalPrice = product.line_total ? parseFloat(product.line_total).toFixed(2) : 
                                         (product.quantity * product.unit_price).toFixed(2);
                        productsHtml += `
                            <div class="product-item">
                                <div class="product-details">
                                    <div class="product-name">${product.product_name || 'Unknown Product'}</div>
                                    <div class="product-meta">${productLine} • ${doughType}</div>
                                </div>
                                <div class="product-info">
                                    <span class="unit-price">$${parseFloat(product.unit_price || 0).toFixed(2)}</span>
                                    <span class="quantity">×${product.quantity || 0}</span>
                                    <span class="total-price">$${totalPrice}</span>
                                </div>
                            </div>
                        `;
                    });
                });
            });
            productsHtml += '</div>';
        }
        
        container.innerHTML = productsHtml;
    }
    
    // Initialize click handlers
    addCustomerClickHandlers();
    
    // Re-add click handlers after view changes
    listViewRadio.addEventListener('change', function() {
        setTimeout(addCustomerClickHandlers, 100);
    });
    tableViewRadio.addEventListener('change', function() {
        setTimeout(addCustomerClickHandlers, 100);
    });
});
