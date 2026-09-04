document.addEventListener('DOMContentLoaded', function() {
    let draggedCustomer = null;
    let filteredDay = null;
    const days = {
        1: 'Monday',
        2: 'Tuesday', 
        3: 'Wednesday',
        4: 'Thursday',
        5: 'Friday',
        6: 'Saturday',
        7: 'Sunday'
    };
    
    // Day filtering functionality
    const dayHeaders = document.querySelectorAll('.clickable-day');
    const clearFilterBtn = document.getElementById('clear-filter');
    const filterStatus = document.getElementById('filter-status');
    const routesContainer = document.querySelector('.routes-container');
    const customerInstruction = document.getElementById('customer-instruction');
    
    dayHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const day = this.getAttribute('data-day');
            filterByDay(day);
        });
    });
    
    clearFilterBtn.addEventListener('click', function() {
        clearDayFilter();
    });
    
    function filterByDay(day) {
        filteredDay = day;
        
        // Update visual state
        dayHeaders.forEach(h => h.classList.remove('active-filter'));
        document.querySelector(`[data-day="${day}"]`).classList.add('active-filter');
        
        // Hide/show appropriate cells
        routesContainer.classList.add('filtered-view');
        
        const allDayCells = document.querySelectorAll('.day-cell');
        allDayCells.forEach(cell => {
            if (cell.getAttribute('data-day') === day) {
                cell.classList.add('show-day');
            } else {
                cell.classList.remove('show-day');
            }
        });
        
        // Filter customers to show only unassigned ones for this day
        filterUnassignedCustomers(day);
        
        // Add visual styling for filtered mode
        document.querySelector('.customers-container').classList.add('filtered-mode');
        document.querySelector('.filter-info').classList.add('active-filter');
        
        // Update filter status
        filterStatus.textContent = `Showing: ${days[day]} (Unassigned Customers Only)`;
        clearFilterBtn.style.display = 'inline-block';
        
        // Update customer instruction
        customerInstruction.textContent = `Showing customers not assigned to any driver for ${days[day]}. Click to instantly assign or drag as usual. Click assigned customers to instantly reassign them.`;
        
        // Make customers clickable with special highlighting for filtered mode
        updateCustomerInteraction();
    }
    
    function filterUnassignedCustomers(day) {
        // Get all customers assigned to any driver for this day
        const assignedCustomerIds = new Set();
        const dayCells = document.querySelectorAll(`.day-cell[data-day="${day}"]`);
        
        dayCells.forEach(cell => {
            const assignedCustomers = cell.querySelectorAll('.assigned-customer');
            assignedCustomers.forEach(customer => {
                assignedCustomerIds.add(customer.getAttribute('data-customer-id'));
            });
        });
        
        // Show/hide customers based on assignment status
        const customerItems = document.querySelectorAll('.customer-item');
        customerItems.forEach(item => {
            const customerId = item.getAttribute('data-customer-id');
            
            if (assignedCustomerIds.has(customerId)) {
                // Customer is assigned - hide them
                item.style.display = 'none';
                item.classList.add('hidden-assigned');
            } else {
                // Customer is unassigned - show them
                item.style.display = 'block';
                item.classList.remove('hidden-assigned');
            }
        });
    }
    
    function clearDayFilter() {
        filteredDay = null;
        
        // Reset visual state
        dayHeaders.forEach(h => h.classList.remove('active-filter'));
        routesContainer.classList.remove('filtered-view');
        
        const allDayCells = document.querySelectorAll('.day-cell');
        allDayCells.forEach(cell => {
            cell.classList.remove('show-day');
        });
        
        // Show all customers again
        const customerItems = document.querySelectorAll('.customer-item');
        customerItems.forEach(item => {
            item.style.display = 'block';
            item.classList.remove('hidden-assigned');
        });
        
        // Remove visual styling for filtered mode
        document.querySelector('.customers-container').classList.remove('filtered-mode');
        document.querySelector('.filter-info').classList.remove('active-filter');
        
        // Update filter status
        filterStatus.textContent = 'Showing: All Days';
        clearFilterBtn.style.display = 'none';
        
        // Reset customer instruction
        customerInstruction.textContent = 'Click a customer to instantly assign them to a driver and day, or drag them to specific driver/day cells. Click assigned customers to instantly reassign them. Customers are organized and color-coded by their delivery zone.';
        
        // Update customer interaction
        updateCustomerInteraction();
    }
    
    function updateCustomerInteraction() {
        const customerItems = document.querySelectorAll('.customer-item');
        customerItems.forEach(item => {
            // Remove existing classes
            item.classList.remove('filtered-clickable');
            
            if (filteredDay) {
                item.classList.add('filtered-clickable');
                item.title = `Click to assign to ${days[filteredDay]}`;
            } else {
                item.title = 'Click to assign to driver and day';
            }
        });
    }
    
    // Modal functionality
    const modal = document.getElementById('assignment-modal');
    const modalCustomerName = document.getElementById('modal-customer-name');
    const modalDayName = document.getElementById('modal-day-name');
    const modalDayContext = document.getElementById('modal-day-context');
    const daySelectionSection = document.getElementById('day-selection-section');
    const driverSelectionSection = document.getElementById('driver-selection-section');
    const driverClickSection = document.getElementById('driver-click-section');
    
    let currentCustomerId = null;
    let currentDayOfWeek = null;
    let selectedDriverId = null;
    let selectedDayOfWeek = null;
    
    // Customer click functionality (works always now)
    document.addEventListener('click', function(e) {
        // Handle clicks on customer items in the customer list
        const customerItem = e.target.closest('.customer-item');
        if (customerItem && !e.target.closest('.zone-group-header')) {
            e.preventDefault();
            e.stopPropagation();
            
            currentCustomerId = customerItem.getAttribute('data-customer-id');
            const customerName = customerItem.getAttribute('data-customer-name');
            
            // Update modal content
            modalCustomerName.textContent = customerName;
            
            if (filteredDay) {
                // Filtered mode - show clickable driver list, hide traditional controls
                currentDayOfWeek = filteredDay;
                modalDayName.textContent = days[filteredDay];
                modalDayContext.style.display = 'inline';
                daySelectionSection.style.display = 'none';
                driverSelectionSection.style.display = 'none';
                driverClickSection.style.display = 'block';
                
                // Update driver click list with current assignment status
                updateDriverClickList(currentCustomerId, currentDayOfWeek);
            } else {
                // Non-filtered mode - show visual interface
                currentDayOfWeek = null;
                modalDayContext.style.display = 'none';
                daySelectionSection.style.display = 'block';
                driverSelectionSection.style.display = 'block';
                driverClickSection.style.display = 'none';
                
                // Reset day selection to Monday (first option)
                selectedDayOfWeek = '1';
                const dayOptions = document.querySelectorAll('.day-icon-option');
                dayOptions.forEach(option => {
                    option.classList.remove('selected');
                    if (option.getAttribute('data-day') === '1') {
                        option.classList.add('selected');
                    }
                });
                
                // Reset driver selection
                selectedDriverId = '0';
                const driverOptions = document.querySelectorAll('#driver-selection-section .driver-icon-option');
                driverOptions.forEach(option => {
                    option.classList.remove('selected');
                    if (option.getAttribute('data-driver-id') === '0') {
                        option.classList.add('selected');
                    }
                });
                
                // Check for existing assignment for the default selected day
                updateDriverSelectionForDay('1');
            }
            
            // Show modal
            modal.style.display = 'block';
        }
        
        // Handle clicks on assigned customers to reassign them
        const assignedCustomer = e.target.closest('.assigned-customer');
        if (assignedCustomer && !e.target.classList.contains('delete-customer')) {
            e.preventDefault();
            e.stopPropagation();
            
            currentCustomerId = assignedCustomer.getAttribute('data-customer-id');
            const customerName = assignedCustomer.getAttribute('data-customer-name');
            const dayCell = assignedCustomer.closest('.day-cell');
            const currentDay = dayCell.getAttribute('data-day');
            
            // Update modal content
            modalCustomerName.textContent = customerName;
            
            if (filteredDay) {
                // Filtered mode - show clickable driver list, hide traditional controls
                currentDayOfWeek = filteredDay;
                modalDayName.textContent = days[filteredDay];
                modalDayContext.style.display = 'inline';
                daySelectionSection.style.display = 'none';
                driverSelectionSection.style.display = 'none';
                driverClickSection.style.display = 'block';
                
                // Update driver click list with current assignment status
                updateDriverClickList(currentCustomerId, currentDayOfWeek);
            } else {
                // Non-filtered mode - show visual interface
                currentDayOfWeek = null;
                modalDayContext.style.display = 'none';
                daySelectionSection.style.display = 'block';
                driverSelectionSection.style.display = 'block';
                driverClickSection.style.display = 'none';
                
                // Set the day to the current assignment day
                selectedDayOfWeek = currentDay;
                const dayOptions = document.querySelectorAll('.day-icon-option');
                dayOptions.forEach(option => {
                    option.classList.remove('selected');
                    if (option.getAttribute('data-day') === currentDay) {
                        option.classList.add('selected');
                    }
                });
                
                // Set driver selection based on current assignment
                updateDriverSelectionForDay(currentDay);
            }
            
            // Show modal
            modal.style.display = 'block';
        }
    });
    
    // Driver click functionality is now handled by the visual interface onclick handlers
    
    function updateDriverClickList(customerId, dayOfWeek) {
        const existingAssignment = findExistingAssignment(customerId, dayOfWeek);
        const driverItems = document.querySelectorAll('#driver-click-section .driver-icon-option');
        
        driverItems.forEach(item => {
            const driverId = item.getAttribute('data-driver-id');
            const statusSpan = item.querySelector('.driver-status');
            
            // Remove existing status classes
            item.classList.remove('selected');
            
            if (driverId === existingAssignment) {
                item.classList.add('selected');
                statusSpan.textContent = '(Current)';
            } else {
                statusSpan.textContent = '';
            }
        });
    }
    
    async function saveDriverAssignment(driverId, dayOfWeek) {
        // Store the current filter state before reload
        if (filteredDay) {
            localStorage.setItem('preserveFilterDay', filteredDay);
        }
        
        try {
            const response = await fetch('standing_routes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=save_route&driver_id=${driverId}&customer_id=${currentCustomerId}&day_of_week=${dayOfWeek}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                modal.style.display = 'none';
                // Reload the page to show updated routes
                window.location.reload();
            } else {
                alert('Error saving assignment: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error saving assignment');
        }
    }
    
    function updateDriverSelectionForDay(dayOfWeek) {
        if (currentCustomerId) {
            const existingAssignment = findExistingAssignment(currentCustomerId, dayOfWeek);
            selectedDriverId = existingAssignment || '0';
            
            const driverOptions = document.querySelectorAll('#driver-selection-section .driver-icon-option');
            driverOptions.forEach(option => {
                option.classList.remove('selected');
                if (option.getAttribute('data-driver-id') === selectedDriverId) {
                    option.classList.add('selected');
                }
            });
        }
    }
    
    function findExistingAssignment(customerId, dayOfWeek) {
        const assignedCustomer = document.querySelector(
            `.day-cell[data-day="${dayOfWeek}"] .assigned-customer[data-customer-id="${customerId}"]`
        );
        
        if (assignedCustomer) {
            const dayCell = assignedCustomer.closest('.day-cell');
            return dayCell.getAttribute('data-driver-id');
        }
        
        return null;
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
    
    // Note: Day selection change handling is now done via visual interface onclick handlers
    
    // Close modal when clicking the X button
    const closeBtn = document.querySelector('.close');
    closeBtn.addEventListener('click', function() {
        modal.style.display = 'none';
    });
    
    // Initialize customer interaction
    updateCustomerInteraction();
    
    // Zone group toggle functionality
    window.toggleZoneGroup = function(headerElement) {
        const zoneGroup = headerElement.closest('.zone-group');
        zoneGroup.classList.toggle('collapsed');
    };
    
    // Visual driver selection functions
    window.selectDriverInModal = function(driverId) {
        selectedDriverId = driverId || '0';
        
        // Update visual selection
        const driverOptions = document.querySelectorAll('#driver-selection-section .driver-icon-option');
        driverOptions.forEach(option => {
            option.classList.remove('selected');
            if (option.getAttribute('data-driver-id') === selectedDriverId) {
                option.classList.add('selected');
            }
        });
        
        // Automatically save the assignment
        const dayOfWeek = filteredDay || selectedDayOfWeek || '1';
        saveDriverAssignment(selectedDriverId, dayOfWeek);
    };
    
    window.selectDriverFilteredMode = function(driverId) {
        // Automatically save the assignment in filtered mode
        saveDriverAssignment(driverId || '0', currentDayOfWeek);
    };
    
    window.selectDayInModal = function(dayOfWeek) {
        selectedDayOfWeek = dayOfWeek;
        
        // Update visual selection
        const dayOptions = document.querySelectorAll('.day-icon-option');
        dayOptions.forEach(option => {
            option.classList.remove('selected');
            if (option.getAttribute('data-day') === dayOfWeek) {
                option.classList.add('selected');
            }
        });
        
        // Update driver selection based on current assignment for this day
        updateDriverSelectionForDay(dayOfWeek);
        
        // If there's a current driver selection, automatically save the assignment
        if (selectedDriverId && selectedDriverId !== '0') {
            saveDriverAssignment(selectedDriverId, dayOfWeek);
        }
    };
    
    // Check for preserved filter on page load
    const preservedFilterDay = localStorage.getItem('preserveFilterDay');
    if (preservedFilterDay) {
        localStorage.removeItem('preserveFilterDay');
        // Apply the filter after a short delay to ensure everything is loaded
        setTimeout(() => {
            filterByDay(preservedFilterDay);
        }, 100);
    }
    
    // Make day cells droppable
    const dayCells = document.querySelectorAll('.day-cell');
    
    // Make all customer items draggable
    document.querySelectorAll('.customer-item').forEach(customerItem => {
        customerItem.addEventListener('dragstart', function(e) {
            // Only allow drag if in filtered mode or if we want to allow both
            // For now, let's allow both drag and click functionality
            
            draggedCustomer = {
                id: this.getAttribute('data-customer-id'),
                name: this.getAttribute('data-customer-name'),
                element: this
            };
            
            // Add visual feedback
            this.style.opacity = '0.4';
            
            // Set drag data
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', JSON.stringify({
                customerId: draggedCustomer.id,
                customerName: draggedCustomer.name
            }));
        });
        
        customerItem.addEventListener('dragend', function() {
            this.style.opacity = '1';
            draggedCustomer = null;
        });
    });
    
    // Handle drag over for day cells
    dayCells.forEach(cell => {
        cell.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('drag-over');
        });
        
        cell.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });
        
        cell.addEventListener('drop', async function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            
            if (!draggedCustomer) return;
            
            const driverId = this.getAttribute('data-driver-id');
            const dayOfWeek = this.getAttribute('data-day');
            
            // Store the current filter state before reload
            if (filteredDay) {
                localStorage.setItem('preserveFilterDay', filteredDay);
            }
            
            try {
                const response = await fetch('standing_routes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=save_route&driver_id=${driverId}&customer_id=${draggedCustomer.id}&day_of_week=${dayOfWeek}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Reload the page to show updated routes
                    window.location.reload();
                } else {
                    alert('Error saving route: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error saving route');
            }
        });
    });
    
    // Make assigned customer items draggable
    function makeAssignedItemsDraggable() {
        document.querySelectorAll('.assigned-customer').forEach(item => {
            item.setAttribute('draggable', 'true');
            
            item.addEventListener('dragstart', function(e) {
                draggedCustomer = {
                    id: this.getAttribute('data-customer-id'),
                    name: this.getAttribute('data-customer-name'),
                    element: this
                };
                
                this.style.opacity = '0.4';
                
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    customerId: draggedCustomer.id,
                    customerName: draggedCustomer.name
                }));
                
                // Store the original location to handle reordering
                this._originalParent = this.parentNode;
            });
            
            item.addEventListener('dragend', function() {
                this.style.opacity = '1';
                draggedCustomer = null;
                
                // If the item was dragged but not dropped in a valid target, return it
                if (this.parentNode !== this._originalParent) {
                    this._originalParent.appendChild(this);
                }
            });
        });
    }
    
    // Initialize draggable items
    makeAssignedItemsDraggable();
    
    // Handle customer deletion
    document.addEventListener('click', async function(e) {
        if (e.target.classList.contains('delete-customer')) {
            e.stopPropagation();
            const customerItem = e.target.closest('.assigned-customer');
            const customerId = customerItem.getAttribute('data-customer-id');
            const dayOfWeek = customerItem.closest('.day-cell').getAttribute('data-day');
            
            // Store the current filter state before reload
            if (filteredDay) {
                localStorage.setItem('preserveFilterDay', filteredDay);
            }
            
            try {
                const response = await fetch('standing_routes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=save_route&driver_id=0&customer_id=${customerId}&day_of_week=${dayOfWeek}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // For deletion, we can just remove the element without full page reload
                    // But to maintain consistency and ensure data integrity, let's reload
                    window.location.reload();
                } else {
                    alert('Error removing customer: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error removing customer');
            }
        }
    });
});
