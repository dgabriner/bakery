<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

$page_title = 'View by Customer';

$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday',
];

$dayShort = [
    1 => 'Mon',
    2 => 'Tue',
    3 => 'Wed',
    4 => 'Thu',
    5 => 'Fri',
    6 => 'Sat',
    7 => 'Sun',
];

$driverColors = [
    '#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1',
    '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_route') {
    header('Content-Type: application/json');
    try {
        $driverId = (int)$_POST['driver_id'];
        $customerId = (int)$_POST['customer_id'];
        $dayOfWeek = bakery_normalize_standing_day((int)$_POST['day_of_week']);

        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new Exception('Invalid day of week');
        }

        if ($driverId > 0) {
            bakery_ensure_drivers_archived_column($db);
            $driverRow = bakery_get_driver_by_id($db, $driverId);
            if (!$driverRow) {
                throw new Exception('Driver not found');
            }
            if ((int)($driverRow['archived'] ?? 0) === 1) {
                throw new Exception('Cannot assign an archived driver. Restore the driver first.');
            }
        }

        if ($dayOfWeek === 7) {
            $stmt = $db->prepare('DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week IN (0, 7)');
            $stmt->execute([$customerId]);
        } else {
            $stmt = $db->prepare('DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week = ?');
            $stmt->execute([$customerId, $dayOfWeek]);
        }

        if ($driverId > 0) {
            $stmt = $db->prepare('INSERT INTO standing_routes (driver_id, customer_id, day_of_week) VALUES (?, ?, ?)');
            $stmt->execute([$driverId, $customerId, $dayOfWeek]);
        }

        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$drivers = [];
$customersByZone = [];
$error = null;

function customerRoutesZoneClass(?string $zone): string
{
    $zone = $zone ?: 'No Zone';
    return 'zone-' . strtolower(str_replace([' ', '/'], ['-', '-'], $zone));
}

try {
    foreach (bakery_get_drivers($db) as $index => $driver) {
        $drivers[$driver['id']] = [
            'name' => $driver['name'],
            'color' => $driverColors[$index % count($driverColors)],
        ];
    }

    $stmt = $db->query("
        SELECT
            c.id,
            c.name,
            c.address,
            c.zone,
            sr.day_of_week,
            sr.driver_id,
            d.name AS driver_name
        FROM customers c
        LEFT JOIN standing_routes sr ON c.id = sr.customer_id
        LEFT JOIN drivers d ON sr.driver_id = d.id
        ORDER BY
            CASE WHEN c.zone IS NULL OR c.zone = '' THEN 'ZZZ_No Zone' ELSE c.zone END,
            c.name,
            sr.day_of_week
    ");

    $customerMap = [];
    foreach ($stmt->fetchAll() as $row) {
        $customerId = (int)$row['id'];
        if (!isset($customerMap[$customerId])) {
            $customerMap[$customerId] = [
                'id' => $customerId,
                'name' => $row['name'],
                'address' => $row['address'],
                'zone' => $row['zone'] ?: 'No Zone',
                'days' => [],
            ];
        }

        if ($row['day_of_week'] !== null) {
            $dayOfWeek = bakery_normalize_standing_day((int)$row['day_of_week']);
            $driverId = (int)$row['driver_id'];
            $driverInfo = $drivers[$driverId] ?? null;
            $customerMap[$customerId]['days'][$dayOfWeek] = [
                'driver_id' => $driverId,
                'driver_name' => $row['driver_name'],
                'driver_color' => $driverInfo['color'] ?? '#6c757d',
                'driver_initial' => $row['driver_name']
                    ? strtoupper(substr($row['driver_name'], 0, 1))
                    : '?',
            ];
        }
    }

    foreach ($customerMap as $customer) {
        $zone = $customer['zone'];
        if (!isset($customersByZone[$zone])) {
            $customersByZone[$zone] = [];
        }
        $customersByZone[$zone][] = $customer;
    }
} catch (Exception $e) {
    $error = 'Error loading data: ' . htmlspecialchars($e->getMessage());
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<div class="container customer-routes-page">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <h1>View by Customer</h1>

    <div class="filter-info">
        <span id="filter-status">Showing: All Days</span>
        <button id="clear-filter" class="btn btn-sm btn-secondary" style="display:none;">Show All Days</button>
    </div>

    <div class="instruction-text">
        Customers grouped by zone. Click any day cell to assign or change the driver for that customer. Click a day header to filter the view.
    </div>

    <div class="zone-legend">
        <h4>Zone Color Legend</h4>
        <div class="zone-colors">
            <div class="zone-color-item zone-centro"><span>Centro</span></div>
            <div class="zone-color-item zone-mission"><span>Mission</span></div>
            <div class="zone-color-item zone-ruta-sour-flour"><span>Ruta Sour Flour</span></div>
            <div class="zone-color-item zone-daly-city-san-mateo"><span>Daly City San Mateo</span></div>
            <div class="zone-color-item zone-north-bay"><span>North Bay</span></div>
            <div class="zone-color-item zone-east-bay"><span>East Bay</span></div>
            <div class="zone-color-item zone-no-zone"><span>No Zone</span></div>
        </div>
    </div>

    <?php if (empty($customersByZone)): ?>
        <div class="alert alert-info">No customers found.</div>
    <?php else: ?>
        <?php foreach ($customersByZone as $zoneName => $zoneCustomers):
            $zoneClass = customerRoutesZoneClass($zoneName);
            $zoneIcons = [
                'Centro' => '🏢',
                'Mission' => '🌮',
                'Ruta Sour Flour' => '🍞',
                'Daly City/San Mateo' => '🌉',
                'North Bay' => '🌲',
                'East Bay' => '🏔️',
                'No Zone' => '📍',
            ];
        ?>
            <div class="zone-group-block">
                <div class="zone-group-header <?php echo $zoneClass; ?>">
                    <h3><?php echo ($zoneIcons[$zoneName] ?? '🗺️') . ' ' . htmlspecialchars($zoneName); ?></h3>
                    <span><?php echo count($zoneCustomers); ?> customers</span>
                </div>

                <div class="routes-table-wrap">
                    <div class="routes-table">
                        <div class="routes-table-header">
                            <div class="customer-col">Customer</div>
                            <?php foreach ($days as $dayNum => $dayName): ?>
                                <div class="day-col clickable-day" data-day="<?php echo $dayNum; ?>" title="Filter by <?php echo $dayName; ?>">
                                    <?php echo $dayShort[$dayNum]; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php foreach ($zoneCustomers as $customer): ?>
                            <div class="routes-table-row" data-customer-id="<?php echo $customer['id']; ?>">
                                <div class="customer-col">
                                    <div class="customer-name"><?php echo htmlspecialchars($customer['name']); ?></div>
                                    <?php if (!empty($customer['address'])): ?>
                                        <div class="customer-address"><?php echo htmlspecialchars($customer['address']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php foreach ($days as $dayNum => $dayName):
                                    $assignment = $customer['days'][$dayNum] ?? null;
                                    $hasDriver = $assignment !== null;
                                ?>
                                    <div class="day-col day-slot" data-day="<?php echo $dayNum; ?>">
                                        <button type="button"
                                                class="day-assignment <?php echo $hasDriver ? 'assigned' : 'unassigned'; ?>"
                                                style="<?php echo $hasDriver ? 'background:' . htmlspecialchars($assignment['driver_color']) . ';' : ''; ?>"
                                                data-customer-id="<?php echo $customer['id']; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                data-day="<?php echo $dayNum; ?>"
                                                data-day-name="<?php echo $dayName; ?>"
                                                data-driver-id="<?php echo $hasDriver ? (int)$assignment['driver_id'] : 0; ?>"
                                                title="<?php echo $dayName . ': ' . ($hasDriver ? htmlspecialchars($assignment['driver_name']) : 'Click to assign driver'); ?>">
                                            <?php if ($hasDriver): ?>
                                                <span class="driver-initial"><?php echo htmlspecialchars($assignment['driver_initial']); ?></span>
                                                <span class="driver-name"><?php echo htmlspecialchars($assignment['driver_name']); ?></span>
                                            <?php else: ?>
                                                <span class="no-driver">—</span>
                                            <?php endif; ?>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Driver Assignment Modal -->
<div id="assignment-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Assign Driver</h3>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <p>Assign driver for <strong id="modal-customer-name"></strong> on <strong id="modal-day-name"></strong>:</p>
            <div class="driver-icons-grid">
                <div class="driver-icon-option no-driver" onclick="selectDriverInModal('0')" data-driver-id="0">
                    <div class="driver-icon-preview"><span class="driver-icon-initial">✕</span></div>
                    <span class="driver-icon-name">No Driver</span>
                </div>
                <?php foreach ($drivers as $driverId => $driverInfo): ?>
                    <div class="driver-icon-option" onclick="selectDriverInModal('<?php echo $driverId; ?>')" data-driver-id="<?php echo $driverId; ?>">
                        <div class="driver-icon-preview" style="background: <?php echo $driverInfo['color']; ?>;">
                            <span class="driver-icon-initial"><?php echo strtoupper(substr($driverInfo['name'], 0, 1)); ?></span>
                        </div>
                        <span class="driver-icon-name"><?php echo htmlspecialchars($driverInfo['name']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
.customer-routes-page { max-width: 1400px; margin: 0 auto; padding: 20px; }
.customer-routes-page h1 { margin-bottom: 16px; color: #2c3e50; }

.filter-info {
    margin-bottom: 12px;
    padding: 10px;
    background: #e9ecef;
    border-radius: 6px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.instruction-text {
    color: #6c757d;
    font-style: italic;
    margin-bottom: 16px;
    font-size: 0.92rem;
}

.zone-legend {
    background: #f8f9fa;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.zone-legend h4 { margin: 0 0 8px; font-size: 0.9rem; }
.zone-colors { display: flex; flex-wrap: wrap; gap: 8px; }
.zone-color-item {
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #fff;
}

.zone-group-block {
    margin-bottom: 28px;
    border: 1px solid #dee2e6;
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.zone-group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    color: #fff;
}

.zone-group-header h3 { margin: 0; font-size: 1.05rem; }

.routes-table-wrap { overflow-x: auto; }
.routes-table { min-width: 900px; }

.routes-table-header,
.routes-table-row {
    display: grid;
    grid-template-columns: minmax(200px, 2fr) repeat(7, minmax(90px, 1fr));
    border-bottom: 1px solid #eef1f4;
}

.routes-table-header {
    background: #f1f3f5;
    font-weight: 700;
    font-size: 0.85rem;
    color: #495057;
}

.customer-col {
    padding: 12px 16px;
    border-right: 1px solid #eef1f4;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.day-col {
    padding: 8px;
    border-right: 1px solid #eef1f4;
    display: flex;
    align-items: center;
    justify-content: center;
}

.routes-table-header .day-col {
    padding: 12px 8px;
    text-align: center;
}

.day-col.clickable-day { cursor: pointer; user-select: none; }
.day-col.clickable-day:hover { background: #dee2e6; }
.day-col.clickable-day.active-filter { background: #4e73df; color: #fff; }

.routes-table-row:hover { background: #fafbfc; }

.customer-name { font-weight: 600; color: #2c3e50; font-size: 0.95rem; }
.customer-address { color: #6c757d; font-size: 0.78rem; margin-top: 2px; line-height: 1.3; }

.day-assignment {
    width: 100%;
    min-height: 52px;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: 6px 4px;
    transition: transform 0.15s, box-shadow 0.15s;
    background: #fff;
}

.day-assignment:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
}

.day-assignment.assigned {
    border-color: transparent;
    color: #fff;
    text-shadow: 0 1px 2px rgba(0,0,0,0.5);
}

.day-assignment.unassigned { color: #adb5bd; }
.day-assignment.unassigned:hover { border-color: #4e73df; color: #4e73df; }

.driver-initial { font-weight: 800; font-size: 1rem; line-height: 1; }
.driver-name {
    font-size: 0.68rem;
    line-height: 1.1;
    text-align: center;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.customer-routes-page.filtered-view .day-col:not(.show-day) { display: none; }
.customer-routes-page.filtered-view .routes-table-header .day-col:not(.show-day):not(.customer-col) { display: none; }

.zone-centro, .zone-color-item.zone-centro { background: #007bff !important; }
.zone-mission, .zone-color-item.zone-mission { background: #dc3545 !important; }
.zone-ruta-sour-flour, .zone-color-item.zone-ruta-sour-flour { background: #28a745 !important; }
.zone-daly-city-san-mateo, .zone-color-item.zone-daly-city-san-mateo { background: #fd7e14 !important; }
.zone-north-bay, .zone-color-item.zone-north-bay { background: #6f42c1 !important; }
.zone-east-bay, .zone-color-item.zone-east-bay { background: #20c997 !important; }
.zone-no-zone, .zone-color-item.zone-no-zone { background: #6c757d !important; }

.modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(0,0,0,0.5);
}

.modal-content {
    background: #fff;
    margin: 8% auto;
    padding: 24px;
    border-radius: 12px;
    width: 90%;
    max-width: 640px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.modal-header h3 { margin: 0; }
.close { font-size: 28px; cursor: pointer; color: #aaa; line-height: 1; }
.close:hover { color: #000; }

.driver-icons-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: 12px;
    margin-top: 12px;
}

.driver-icon-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 12px;
    border: 2px solid #dee2e6;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
}

.driver-icon-option:hover { border-color: #007bff; background: #f8f9ff; }
.driver-icon-option.selected { border-color: #28a745; background: #f8fff9; }
.driver-icon-option.no-driver { border-color: #dc3545; color: #dc3545; }

.driver-icon-preview {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #6c757d;
    color: #fff;
    font-weight: 700;
}

.driver-icon-name { font-size: 0.82rem; font-weight: 600; text-align: center; }

.btn {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.btn-secondary { background: #6c757d; color: #fff; }
.btn-sm { font-size: 12px; }

@media (max-width: 768px) {
    .driver-name { display: none; }
    .day-assignment { min-height: 44px; }
}
</style>

<script>
const days = <?php echo json_encode($days); ?>;
let filteredDay = null;
let currentCustomerId = null;
let currentDayOfWeek = null;

const modal = document.getElementById('assignment-modal');
const filterStatus = document.getElementById('filter-status');
const clearFilterBtn = document.getElementById('clear-filter');
const pageRoot = document.querySelector('.customer-routes-page');

document.querySelectorAll('.clickable-day').forEach(header => {
    header.addEventListener('click', () => filterByDay(header.dataset.day));
});

clearFilterBtn.addEventListener('click', clearDayFilter);

document.querySelectorAll('.day-assignment').forEach(btn => {
    btn.addEventListener('click', function() {
        openAssignmentModal(this);
    });
});

document.querySelector('.close').addEventListener('click', () => { modal.style.display = 'none'; });
window.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });

function openAssignmentModal(button) {
    currentCustomerId = button.dataset.customerId;
    currentDayOfWeek = button.dataset.day;

    document.getElementById('modal-customer-name').textContent = button.dataset.customerName;
    document.getElementById('modal-day-name').textContent = button.dataset.dayName;

    const currentDriverId = button.dataset.driverId || '0';
    document.querySelectorAll('.driver-icon-option').forEach(opt => {
        opt.classList.toggle('selected', opt.dataset.driverId === currentDriverId);
    });

    modal.style.display = 'block';
}

window.selectDriverInModal = async function(driverId) {
    if (!currentCustomerId || !currentDayOfWeek) return;

    if (filteredDay) {
        localStorage.setItem('customerRoutesFilterDay', filteredDay);
    }

    try {
        const response = await fetch('customer_routes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_route&driver_id=' + driverId + '&customer_id=' + currentCustomerId + '&day_of_week=' + currentDayOfWeek
        });
        const result = await response.json();
        if (result.success) {
            modal.style.display = 'none';
            window.location.reload();
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Error saving assignment');
    }
};

function filterByDay(day) {
    filteredDay = day;
    document.querySelectorAll('.clickable-day').forEach(h => {
        h.classList.toggle('active-filter', h.dataset.day === day);
    });
    document.querySelectorAll('.day-col[data-day]').forEach(cell => {
        cell.classList.toggle('show-day', cell.dataset.day === day);
    });
    pageRoot.classList.add('filtered-view');
    filterStatus.textContent = 'Showing: ' + days[day];
    clearFilterBtn.style.display = 'inline-block';
}

function clearDayFilter() {
    filteredDay = null;
    document.querySelectorAll('.clickable-day').forEach(h => h.classList.remove('active-filter'));
    document.querySelectorAll('.day-col[data-day]').forEach(cell => cell.classList.remove('show-day'));
    pageRoot.classList.remove('filtered-view');
    filterStatus.textContent = 'Showing: All Days';
    clearFilterBtn.style.display = 'none';
}

const preserved = localStorage.getItem('customerRoutesFilterDay');
if (preserved) {
    localStorage.removeItem('customerRoutesFilterDay');
    setTimeout(() => filterByDay(preserved), 100);
}
</script>

<?php require_once 'includes/footer.php'; ?>
