let currentEditingCustomerId = null;
let currentDriverAssignment = null;

// Store driver data for real-time updates
const driversData = window.__CUSTOMER_SCHEDULE__.drivers;
const zonesData = window.__CUSTOMER_SCHEDULE__.zones;

document.addEventListener('DOMContentLoaded', function() {
    // Verify modal elements exist
    const zoneModal = document.getElementById('zoneEditModal');
    const driverModal = document.getElementById('driverAssignModal');
    const editingCustomerName = document.getElementById('editingCustomerName');
    
    if (!zoneModal) {
        console.error('Zone edit modal not found in DOM');
    }
    if (!driverModal) {
        console.error('Driver assign modal not found in DOM');
    }
    if (!editingCustomerName) {
        console.error('editingCustomerName element not found in DOM');
    }
    
    // Add click handlers to customer rows
    const customerRows = document.querySelectorAll('.clickable-customer');
    customerRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on a day cell, remove button, or customer hub link
            if (!e.target.closest('.clickable-day') && !e.target.closest('.btn-remove-customer') && !e.target.closest('.customer-hub-link')) {
                openZoneEditModal(this);
            }
        });
    });

    // Deep-link from Customer Hub: ?customer_id= scrolls/highlights that row
    (function initCustomerDeepLink() {
        const params = new URLSearchParams(window.location.search);
        const customerId = params.get('customer_id');
        if (!customerId) return;
        const row = document.querySelector('.customer-row[data-customer-id="' + customerId + '"]');
        if (!row) return;
        row.classList.add('is-highlighted');
        const zone = row.closest('.zone-section');
        if (zone) {
            zone.classList.remove('collapsed');
        }
        row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    })();
    
    // Add click handlers to day cells
    const dayCells = document.querySelectorAll('.clickable-day');
    dayCells.forEach(cell => {
        cell.addEventListener('click', function(e) {
            e.stopPropagation(); // Prevent customer row click
            openDriverAssignModal(this);
        });
    });
    
    // Add click handlers to day filter buttons
    const dayFilterBtns = document.querySelectorAll('.day-filter-btn');
    dayFilterBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const day = this.dataset.day;
            toggleDayFilter(day, this);
        });
    });
});

function openDriverAssignModal(dayCell) {
    const customerId = dayCell.dataset.customerId;
    const customerName = dayCell.dataset.customerName;
    const dayOfWeek = dayCell.dataset.dayOfWeek;
    const dayName = dayCell.dataset.dayName;
    const currentDriverId = dayCell.dataset.currentDriverId;
    const currentDriverName = dayCell.dataset.currentDriverName;
    
    currentDriverAssignment = {
        customerId: customerId,
        dayOfWeek: dayOfWeek,
        dayCell: dayCell
    };
    
    document.getElementById('assignCustomerName').textContent = customerName;
    document.getElementById('assignDayName').textContent = dayName;
    document.getElementById('assignCurrentDriver').textContent = currentDriverName || 'No driver assigned';
    
    // Highlight current driver selection
    const driverOptions = document.querySelectorAll('.driver-icon-option');
    driverOptions.forEach(option => {
        option.classList.remove('selected');
        if (currentDriverId && option.dataset.driverId === currentDriverId) {
            option.classList.add('selected');
        } else if (!currentDriverId && option.classList.contains('no-driver')) {
            option.classList.add('selected');
        }
    });
    
    document.getElementById('driverAssignModal').style.display = 'block';
}

function hideDriverAssignModal() {
    document.getElementById('driverAssignModal').style.display = 'none';
    currentDriverAssignment = null;
}

function selectDriver(driverId) {
    if (!currentDriverAssignment) return;
    
    saveDriverAssignment(driverId);
}

async function saveDriverAssignment(driverId) {
    if (!currentDriverAssignment) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_driver');
        formData.append('customer_id', currentDriverAssignment.customerId);
        formData.append('day_of_week', currentDriverAssignment.dayOfWeek);
        formData.append('driver_id', driverId);
        
        const response = await fetch('customer_schedule.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Update the day cell in real-time
            updateDayCell(currentDriverAssignment.dayCell, driverId);
            updateRemoveButton(currentDriverAssignment.dayCell.closest('.customer-row'));
            
            showMessage('Driver updated!', 'success');
            hideDriverAssignModal();
            
            // Update summary statistics
            updateSummaryStats();
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    }
}

function updateDayCell(dayCell, driverId) {
    if (!driverId) {
        // Remove delivery
        dayCell.className = 'day-indicator no-delivery clickable-day';
        dayCell.style.background = '';
        dayCell.innerHTML = '<span class="add-delivery">-</span>';
        dayCell.title = dayCell.title.replace(/Driver:.*/, 'No delivery - Click to assign driver');
        dayCell.dataset.currentDriverId = '';
        dayCell.dataset.currentDriverName = '';
    } else {
        // Add/update delivery
        const driver = driversData[driverId];
        if (driver) {
            const initial = driver.name.charAt(0).toUpperCase();
            dayCell.className = 'day-indicator has-delivery clickable-day';
            dayCell.style.background = `linear-gradient(135deg, ${driver.color} 0%, ${driver.color}dd 100%)`;
            dayCell.innerHTML = `<span class="driver-initial">${initial}</span>`;
            dayCell.title = dayCell.title.replace(/(Driver:.*|No delivery.*)/, `Driver: ${driver.name}`);
            dayCell.dataset.currentDriverId = driverId;
            dayCell.dataset.currentDriverName = driver.name;
        }
    }
}

function updateRemoveButton(customerRow) {
    if (!customerRow) return;

    const hasDeliveries = customerRow.querySelector('.day-indicator.has-delivery');
    const nameRow = customerRow.querySelector('.customer-name-row');
    if (!nameRow) return;

    let removeBtn = nameRow.querySelector('.btn-remove-customer');

    if (!hasDeliveries && !removeBtn) {
        removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn-remove-customer';
        removeBtn.title = 'Remove customer (no route assignments)';
        removeBtn.dataset.customerId = customerRow.dataset.customerId;
        removeBtn.dataset.customerName = customerRow.dataset.customerName;
        removeBtn.textContent = 'Remove';
        removeBtn.onclick = function(e) { confirmRemoveCustomer(e, this); };
        nameRow.appendChild(removeBtn);
    } else if (hasDeliveries && removeBtn) {
        removeBtn.remove();
    }
}

function openZoneEditModal(customerRow) {
    // Add a small delay to ensure DOM is fully ready
    setTimeout(() => {
        const customerId = customerRow.dataset.customerId;
        const customerName = customerRow.dataset.customerName;
        const currentZone = customerRow.dataset.currentZone;
        
        // Double check that all elements exist
        const customerNameElement = document.getElementById('editingCustomerName');
        const modal = document.getElementById('zoneEditModal');
        
        if (!customerNameElement || !modal) {
            console.error('Required modal elements not found:', {
                customerNameElement: !!customerNameElement,
                modal: !!modal
            });
            // Fallback: refresh page and try again
            showMessage('Loading modal... Please try again.', 'error');
            return;
        }
        
        // Debug: Log the modal content
        console.log('Modal found:', modal);
        console.log('Modal innerHTML:', modal.innerHTML.substring(0, 200) + '...');
        
        currentEditingCustomerId = {
            id: customerId,
            row: customerRow,
            currentZone: currentZone
        };
        
        customerNameElement.textContent = customerName;
        
        // Highlight current zone selection with simpler, more reliable method
        const zoneOptions = modal.querySelectorAll('.zone-option');
        console.log('Found zone options in modal:', zoneOptions.length);
        
        // Reset all selections first
        zoneOptions.forEach(option => {
            if (option && option.classList) {
                option.classList.remove('selected');
            }
        });
        
        // Select the current zone
        if (currentZone) {
            // Find option with matching zone name
            zoneOptions.forEach(option => {
                if (option && option.textContent && option.textContent.trim() === currentZone) {
                    option.classList.add('selected');
                }
            });
        } else {
            // Select "No Zone" option
            zoneOptions.forEach(option => {
                if (option && option.classList && option.classList.contains('no-zone')) {
                    option.classList.add('selected');
                }
            });
        }
        
        modal.style.display = 'block';
    }, 10); // Small delay to ensure DOM is ready
}

function hideZoneEditModal() {
    document.getElementById('zoneEditModal').style.display = 'none';
    currentEditingCustomerId = null;
}

async function selectZone(newZone) {
    if (!currentEditingCustomerId) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_zone');
        formData.append('customer_id', currentEditingCustomerId.id);
        formData.append('zone', newZone);
        
        const response = await fetch('customer_schedule.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Update the zone in real-time
            updateCustomerZone(currentEditingCustomerId.row, newZone, currentEditingCustomerId.currentZone);
            
            showMessage('Zone updated!', 'success');
            hideZoneEditModal();
            
            // Update summary statistics
            updateSummaryStats();
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    }
}

function updateCustomerZone(customerRow, newZone, oldZone) {
    const displayZone = newZone || 'No Zone';
    customerRow.dataset.currentZone = newZone;
    
    console.log('updateCustomerZone called:', {
        newZone: newZone,
        oldZone: oldZone,
        displayZone: displayZone,
        customerName: customerRow.dataset.customerName
    });
    
    // If zones are different, we need to move the customer row
    if ((oldZone || 'No Zone') !== displayZone) {
        console.log('Zones are different, moving customer');
        moveCustomerToNewZone(customerRow, displayZone, oldZone || 'No Zone');
    } else {
        console.log('Zones are the same, no movement needed');
    }
}

function moveCustomerToNewZone(customerRow, newZone, oldZone) {
    // Find the target zone section - match PHP class generation exactly
    // PHP: strtolower(str_replace([' ', '/'], ['-', '-'], $zoneName))
    const targetZoneClass = 'zone-' + newZone.toLowerCase().replace(/[ \/]/g, '-');
    console.log('Looking for zone class:', targetZoneClass, 'for zone:', newZone);
    
    // Debug: Show all available zone classes
    const allZoneHeaders = document.querySelectorAll('[class*="zone-"]');
    console.log('All available zone headers:', Array.from(allZoneHeaders).map(el => ({
        className: el.className,
        textContent: el.textContent.trim()
    })));
    
    const targetZoneSection = document.querySelector(`.${targetZoneClass}`);
    console.log('Target zone section found:', !!targetZoneSection);
    
    if (targetZoneSection && targetZoneSection.nextElementSibling) {
        const targetScheduleTable = targetZoneSection.nextElementSibling.querySelector('.schedule-table');
        console.log('Target schedule table found:', !!targetScheduleTable);
        
        if (targetScheduleTable) {
            // Debug: Show where customer currently is
            const currentParent = customerRow.parentElement;
            console.log('Customer current location:', {
                parentClass: currentParent ? currentParent.className : 'no parent',
                parentTagName: currentParent ? currentParent.tagName : 'no parent'
            });
            
            const targetTableBody = targetScheduleTable.querySelector('.customer-row:last-child');
            
            if (targetTableBody) {
                // Insert after the last customer row
                targetTableBody.insertAdjacentElement('afterend', customerRow);
                console.log('Customer moved after existing customer');
            } else {
                // Insert after the header if no customers exist
                const header = targetScheduleTable.querySelector('.table-header');
                if (header) {
                    header.insertAdjacentElement('afterend', customerRow);
                    console.log('Customer moved after header (first in zone)');
                } else {
                    console.error('Could not find table header for zone:', newZone);
                    showMessage('Zone updated! Please refresh to see changes.', 'success');
                    return;
                }
            }
            
            // Debug: Verify the move
            const newParent = customerRow.parentElement;
            console.log('Customer new location:', {
                parentClass: newParent ? newParent.className : 'no parent',
                parentTagName: newParent ? newParent.tagName : 'no parent'
            });
            
            // Update zone counters
            updateZoneCounters(newZone, oldZone);
            
            // Re-attach event listeners
            attachCustomerRowEvents(customerRow);
            
            console.log('Customer successfully moved to zone:', newZone);
        } else {
            console.error('Could not find schedule table for zone:', newZone);
            showMessage('Zone updated! Please refresh to see changes.', 'success');
        }
    } else {
        console.error('Could not find target zone section:', targetZoneClass);
        console.log('Available zones:', Array.from(document.querySelectorAll('[class*="zone-"]')).map(el => el.className));
        showMessage('Zone updated! Please refresh to see the new zone.', 'success');
    }
}

function confirmRemoveCustomer(event, button) {
    event.stopPropagation();
    const customerId = button.dataset.customerId;
    const customerName = button.dataset.customerName;
    const customerRow = button.closest('.customer-row');

    if (!confirm(`Remove "${customerName}" from the schedule?\n\nThis customer has no route assignments and will be permanently deleted.`)) {
        return;
    }

    removeCustomer(customerId, customerRow);
}

async function removeCustomer(customerId, customerRow) {
    try {
        const formData = new FormData();
        formData.append('action', 'delete_customer');
        formData.append('customer_id', customerId);

        const response = await fetch('customer_schedule.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            const zoneSection = customerRow.closest('.zone-section');
            customerRow.remove();

            if (zoneSection) {
                const remainingRows = zoneSection.querySelectorAll('.customer-row');
                const zoneHeader = zoneSection.querySelector('.zone-header');
                const countSpan = zoneHeader?.querySelector('span:last-child');

                if (remainingRows.length === 0) {
                    zoneSection.remove();
                } else if (countSpan) {
                    countSpan.textContent = `(${remainingRows.length} customers)`;
                }
            }

            updateSummaryStats();
            showMessage('Customer removed', 'success');
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    }
}

function attachCustomerRowEvents(customerRow) {
    // Reattach customer row click event
    customerRow.addEventListener('click', function(e) {
        if (!e.target.closest('.clickable-day') && !e.target.closest('.btn-remove-customer')) {
            openZoneEditModal(this);
        }
    });
    
    // Reattach day cell events
    const dayCells = customerRow.querySelectorAll('.clickable-day');
    dayCells.forEach(cell => {
        // Remove existing listeners by cloning
        const newCell = cell.cloneNode(true);
        cell.parentNode.replaceChild(newCell, cell);
        
        newCell.addEventListener('click', function(e) {
            e.stopPropagation();
            openDriverAssignModal(this);
        });
    });
}

function updateZoneCounters(newZone, oldZone) {
    // Update zone headers with customer counts
    const zones = [newZone, oldZone];
    zones.forEach(zone => {
        if (!zone || zone === 'No Zone') zone = 'no-zone';
        // Match PHP class generation: strtolower(str_replace([' ', '/'], ['-', '-'], $zoneName))
        const zoneClass = 'zone-' + zone.toLowerCase().replace(/[ \/]/g, '-');
        const zoneHeader = document.querySelector(`.${zoneClass}`);
        
        if (zoneHeader) {
            const zoneSection = zoneHeader.closest('.zone-section');
            if (zoneSection) {
                const customerRows = zoneSection.querySelectorAll('.customer-row');
                const count = customerRows.length;
                
                // Update the count in the header
                const countSpan = zoneHeader.querySelector('span:last-child');
                if (countSpan) {
                    countSpan.textContent = `(${count} customers)`;
                    console.log(`Updated ${zone} count to ${count}`);
                }
            }
        }
    });
}

function updateSummaryStats() {
    // This is a simplified update - for full accuracy you might want to recalculate
    // For now, we'll just trigger a subtle visual feedback that something changed
    const summaryCards = document.querySelectorAll('.summary-card');
    summaryCards.forEach(card => {
        card.style.transform = 'scale(1.05)';
        setTimeout(() => {
            card.style.transform = 'scale(1)';
        }, 200);
    });
}

function showMessage(message, type) {
    const messageBar = document.getElementById('messageBar');
    const messageText = document.getElementById('messageText');
    
    messageText.textContent = message;
    messageBar.className = 'message-bar ' + type;
    messageBar.style.display = 'block';
    
    // Auto-hide after 2 seconds for faster workflow
    setTimeout(() => {
        messageBar.style.display = 'none';
    }, 2000);
}

// Close modal when clicking outside
window.onclick = function(event) {
    const zoneModal = document.getElementById('zoneEditModal');
    const driverModal = document.getElementById('driverAssignModal');
    
    if (event.target === zoneModal) {
        hideZoneEditModal();
    } else if (event.target === driverModal) {
        hideDriverAssignModal();
    }
}

// Day Filtering Functionality
let currentDayFilter = null;
const dayNames = {
    '1': 'Monday',
    '2': 'Tuesday',
    '3': 'Wednesday',
    '4': 'Thursday',
    '5': 'Friday',
    '6': 'Saturday',
    '7': 'Sunday'
};

function toggleDayFilter(day, clickedBtn) {
    if (currentDayFilter === day) {
        // Clicking the same day - clear filter
        clearDayFilter();
    } else {
        // Apply new day filter
        applyDayFilter(day, clickedBtn);
    }
}

function applyDayFilter(day, clickedBtn) {
    currentDayFilter = day;
    
    // Update button states
    document.querySelectorAll('.day-filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    clickedBtn.classList.add('active');
    
    // Hide all day columns except the selected one
    const dayIndex = parseInt(day);
    
    // Hide header cells (skip first cell which is "Customer")
    document.querySelectorAll('.table-header .header-cell').forEach((cell, index) => {
        if (index === 0) return; // Skip "Customer" header
        
        const cellDay = cell.dataset.day;
        if (cellDay === String(dayIndex)) {
            cell.style.display = 'block';
            cell.classList.add('highlight-day');
        } else {
            cell.style.display = 'none';
        }
    });
    
    // Hide day cells in customer rows
    document.querySelectorAll('.customer-row').forEach(row => {
        const dayCells = row.querySelectorAll('.day-cell');
        dayCells.forEach(cell => {
            if (cell.dataset.day === String(dayIndex)) {
                cell.style.display = 'flex';
                // Highlight the day indicator if it has delivery
                const dayIndicator = cell.querySelector('.day-indicator');
                if (dayIndicator && dayIndicator.classList.contains('has-delivery')) {
                    dayIndicator.classList.add('highlight-day');
                }
            } else {
                cell.style.display = 'none';
                // Remove highlights from hidden day indicators
                const dayIndicator = cell.querySelector('.day-indicator');
                if (dayIndicator) {
                    dayIndicator.classList.remove('highlight-day');
                }
            }
        });
    });
    
    // Update grid layout to accommodate fewer columns and add filtered class
    document.querySelectorAll('.table-header, .customer-row').forEach(element => {
        element.style.gridTemplateColumns = '2fr 1fr'; // Customer column + 1 day column
        element.classList.add('filtered');
    });
    
    // Count customers with deliveries on this day
    let customersWithDelivery = 0;
    let totalCustomers = 0;
    
    document.querySelectorAll('.customer-row').forEach(row => {
        totalCustomers++;
        const dayCell = row.querySelector(`.day-cell[data-day="${dayIndex}"]`);
        const hasDelivery = dayCell && dayCell.querySelector('.has-delivery');
        if (hasDelivery) {
            customersWithDelivery++;
        }
    });
    
    // Show filter controls and update status
    const filterControls = document.getElementById('filterControls');
    const filterStatus = document.getElementById('filterStatus');
    
    filterControls.style.display = 'flex';
    filterStatus.textContent = `Showing ${dayNames[day]} schedule - ${customersWithDelivery} of ${totalCustomers} customers have deliveries`;
    filterStatus.classList.add('active');
}

function clearDayFilter() {
    currentDayFilter = null;
    
    // Clear button states
    document.querySelectorAll('.day-filter-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.classList.remove('highlight-day');
    });
    
    // Show all day columns
    document.querySelectorAll('.table-header .header-cell').forEach(cell => {
        cell.style.display = 'block';
    });
    
    document.querySelectorAll('.customer-row .day-cell').forEach(cell => {
        cell.style.display = 'flex';
    });
    
    // Reset grid layout by removing inline styles and filtered class so CSS media queries work
    document.querySelectorAll('.table-header, .customer-row').forEach(element => {
        element.style.gridTemplateColumns = ''; // Remove inline style to let CSS take over
        element.classList.remove('filtered'); // Remove filtered class
    });
    
    // Remove highlights from day indicators
    document.querySelectorAll('.day-indicator').forEach(indicator => {
        indicator.classList.remove('highlight-day');
    });
    
    // Reset zone appearances (remove any dimming)
    document.querySelectorAll('.zone-section').forEach(zoneSection => {
        zoneSection.classList.remove('no-visible-customers');
    });
    
    // Hide filter controls
    const filterControls = document.getElementById('filterControls');
    const filterStatus = document.getElementById('filterStatus');
    
    filterControls.style.display = 'none';
    filterStatus.textContent = 'Showing all customers';
    filterStatus.classList.remove('active');
}
