<?php
/** Route Analysis -- photo-timed route review for a driver and day. */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

$user = bakery_current_user();
if ($user && bakery_is_driver_route_role($user['role_slug'] ?? '')) {
    header('Location: ' . BASE_URL . 'driver.php');
    exit;
}

$page_title = 'Route Analysis';
$today = date('Y-m-d');
$dateRaw = (string)($_GET['date'] ?? $today);
$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
$selectedDate = ($dateObject && $dateObject->format('Y-m-d') === $dateRaw) ? $dateRaw : $today;
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        bakery_require_csrf();
        if ((string)($_POST['action'] ?? '') !== 'update_default_time') {
            throw new RuntimeException('Unknown route analysis action.');
        }
        $postDate = (string)($_POST['date'] ?? $selectedDate);
        $postDriverId = max(0, (int)($_POST['driver_id'] ?? 0));
        $customerId = max(0, (int)($_POST['customer_id'] ?? 0));
        $minutes = max(0, (int)($_POST['delivery_time'] ?? 0));
        if ($customerId < 1 || $minutes < 1 || $minutes > 120) {
            throw new RuntimeException('Choose a default stop time between 1 and 120 minutes.');
        }
        $stmt = $db->prepare('UPDATE customers SET delivery_time = ? WHERE id = ?');
        $stmt->execute([$minutes, $customerId]);
        $flash = 'Default stop time updated.';
        $selectedDate = $postDate;
        $_GET['driver_id'] = $postDriverId;
    } catch (Throwable $e) {
        $flashType = 'danger';
        $flash = $e->getMessage();
    }
}

$driverStmt = $db->prepare("SELECT DISTINCT d.id, d.name
    FROM daily_order_assignments doa JOIN drivers d ON d.id = doa.driver_id
    WHERE doa.delivery_date = ? ORDER BY d.name");
$driverStmt->execute([$selectedDate]);
$drivers = $driverStmt->fetchAll(PDO::FETCH_ASSOC);
$driverIds = array_map(static fn($driver) => (int)$driver['id'], $drivers);
$requestedDriverId = max(0, (int)($_GET['driver_id'] ?? 0));
$selectedDriverId = in_array($requestedDriverId, $driverIds, true) ? $requestedDriverId : (int)($driverIds[0] ?? 0);
$stops = [];
$photoTimesByCustomer = [];
$photosAvailable = table_exists($db, 'driver_photos');

if ($selectedDriverId > 0) {
    $stopStmt = $db->prepare("SELECT doa.route_order, doa.delivery_status, doa.actual_delivery_time,
            c.id AS customer_id, c.name AS customer_name, c.zone, c.delivery_time AS default_minutes
        FROM daily_order_assignments doa
        JOIN daily_orders daily_order ON daily_order.id = doa.daily_order_id
        JOIN customers c ON c.id = daily_order.customer_id
        WHERE doa.delivery_date = ? AND doa.driver_id = ?
        ORDER BY COALESCE(doa.route_order, 9999), c.name");
    $stopStmt->execute([$selectedDate, $selectedDriverId]);
    $stops = $stopStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($photosAvailable) {
        $photoStmt = $db->prepare("SELECT customer_id, created_at, photo_type FROM driver_photos
            WHERE driver_id = ? AND delivery_date = ? AND customer_id IS NOT NULL
            ORDER BY customer_id, created_at, id");
        $photoStmt->execute([$selectedDriverId, $selectedDate]);
        foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
            $photoTimesByCustomer[(int)$photo['customer_id']][] = $photo;
        }
    }
}

function route_analysis_minutes_between(?string $start, ?string $end): ?int {
    if (!$start || !$end) return null;
    try {
        $startAt = new DateTimeImmutable($start);
        $endAt = new DateTimeImmutable($end);
        if ($endAt < $startAt) return null;
        return (int)round(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60);
    } catch (Throwable $e) {
        return null;
    }
}
function route_analysis_datetime(?string $time): string {
    return $time ? date('g:i A', strtotime($time)) : '—';
}

$stats = ['stops' => count($stops), 'completed' => 0, 'timed_total' => 0, 'timed_count' => 0, 'second_photo_count' => 0];
foreach ($stops as &$stop) {
    $times = $photoTimesByCustomer[(int)$stop['customer_id']] ?? [];
    $stop['first_photo_at'] = $times[0]['created_at'] ?? null;
    $stop['second_photo_at'] = $times[1]['created_at'] ?? null;
    $stop['first_photo_type'] = $times[0]['photo_type'] ?? null;
    $stop['second_photo_type'] = $times[1]['photo_type'] ?? null;
    $stop['photo_count'] = count($times);
    $stop['load_minutes'] = route_analysis_minutes_between($stop['first_photo_at'], $stop['second_photo_at']);
    if ($stop['delivery_status'] === 'delivered') $stats['completed']++;
    if ($stop['second_photo_at']) $stats['second_photo_count']++;
    if ($stop['load_minutes'] !== null) {
        $stats['timed_total'] += $stop['load_minutes'];
        $stats['timed_count']++;
    }
}
unset($stop);
$avgLoad = $stats['timed_count'] ? (int)round($stats['timed_total'] / $stats['timed_count']) : null;
$selectedDriverName = '';
foreach ($drivers as $driver) if ((int)$driver['id'] === $selectedDriverId) $selectedDriverName = $driver['name'];

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/route_analysis.css'); ?>">
<main class="route-analysis">
  <header class="ra-header">
    <div><p class="ra-eyebrow">Delivery intelligence</p><h1>Route Analysis</h1><p>Use the first and second delivery photos to see how long each store took to load, then decide whether its default stop time should change.</p></div>
    <form class="ra-filter" method="get" action="">
      <label><span>Date</span><input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>"></label>
      <label><span>Driver</span><select name="driver_id"<?php echo $drivers ? '' : ' disabled'; ?>><?php foreach ($drivers as $driver): ?><option value="<?php echo (int)$driver['id']; ?>"<?php echo (int)$driver['id'] === $selectedDriverId ? ' selected' : ''; ?>><?php echo htmlspecialchars($driver['name']); ?></option><?php endforeach; ?></select></label>
      <button type="submit">View route</button>
    </form>
  </header>
  <?php if ($flash): ?><div class="ra-alert ra-alert--<?php echo $flashType; ?>" role="alert"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
  <?php if (!$drivers): ?>
    <section class="ra-empty"><h2>No route on this date</h2><p>Choose another day with assigned deliveries to review its route.</p></section>
  <?php else: ?>
    <section class="ra-context" aria-label="Route being analyzed"><strong><?php echo htmlspecialchars($selectedDriverName); ?></strong><span><?php echo htmlspecialchars(date('l, F j, Y', strtotime($selectedDate))); ?></span><span><?php echo $stats['stops']; ?> planned stops</span></section>
    <section class="ra-summary" aria-label="Route summary">
      <div><span>Completed</span><strong><?php echo $stats['completed']; ?>/<?php echo $stats['stops']; ?></strong><small>stops delivered</small></div>
      <div><span>Average load time</span><strong><?php echo $avgLoad !== null ? $avgLoad . ' min' : '—'; ?></strong><small>first photo to second photo</small></div>
      <div><span>Stops timed</span><strong><?php echo $stats['timed_count']; ?>/<?php echo $stats['stops']; ?></strong><small>at least two photos recorded</small></div>
      <div><span>Need second photo</span><strong><?php echo max(0, $stats['stops'] - $stats['second_photo_count']); ?></strong><small>stops without a photo pair</small></div>
    </section>
    <?php if (!$photosAvailable): ?><div class="ra-note">Photo timing is unavailable because the delivery-photo table is not installed.</div>
    <?php elseif ($stats['timed_count'] === 0): ?><div class="ra-note">This route has no photo pairs yet. Load time appears as soon as a stop has a first and second delivery photo.</div><?php endif; ?>
    <section class="ra-section">
      <div class="ra-section-head"><div><p class="ra-eyebrow">Stop-by-stop replay</p><h2>Photo-timed loading</h2></div><p>The first two photos at each stop define its observed load time. The suggested default rounds that time up to the next five minutes.</p></div>
      <div class="ra-table-wrap"><table class="ra-table">
        <thead><tr><th>Stop</th><th>First photo</th><th>Second photo</th><th>Load time</th><th>Default time</th></tr></thead>
        <tbody><?php foreach ($stops as $stop):
          $suggested = $stop['load_minutes'] !== null ? (int)(ceil($stop['load_minutes'] / 5) * 5) : null;
          $shouldSuggest = $suggested !== null && $suggested !== (int)$stop['default_minutes'];
        ?><tr>
          <td><span class="ra-stop-num">#<?php echo (int)$stop['route_order']; ?></span><strong><?php echo htmlspecialchars($stop['customer_name']); ?></strong><?php if ($stop['zone']): ?><small><?php echo htmlspecialchars($stop['zone']); ?></small><?php endif; ?></td>
          <td><strong><?php echo route_analysis_datetime($stop['first_photo_at']); ?></strong><small><?php echo $stop['first_photo_at'] ? htmlspecialchars((string)$stop['first_photo_type']) . ' · photo 1 of ' . $stop['photo_count'] : 'not taken'; ?></small></td>
          <td><strong><?php echo route_analysis_datetime($stop['second_photo_at']); ?></strong><small><?php echo $stop['second_photo_at'] ? htmlspecialchars((string)$stop['second_photo_type']) . ' · photo 2' : 'not taken'; ?></small></td>
          <td><strong><?php echo $stop['load_minutes'] !== null ? $stop['load_minutes'] . ' min' : '—'; ?></strong><small><?php echo $stop['load_minutes'] !== null ? 'between photos' : 'needs two photos'; ?></small></td>
          <td><div class="ra-default"><strong><?php echo (int)$stop['default_minutes']; ?> min</strong><?php if ($shouldSuggest): ?><span>Observed: <?php echo $suggested; ?> min</span><form method="post" action=""><input type="hidden" name="action" value="update_default_time"><?php echo bakery_csrf_field(); ?><input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>"><input type="hidden" name="driver_id" value="<?php echo $selectedDriverId; ?>"><input type="hidden" name="customer_id" value="<?php echo (int)$stop['customer_id']; ?>"><input type="hidden" name="delivery_time" value="<?php echo $suggested; ?>"><button type="submit">Use <?php echo $suggested; ?> min</button></form><?php else: ?><small><?php echo $suggested !== null ? 'Matches observed time' : 'No change suggested'; ?></small><?php endif; ?></div></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    </section>
  <?php endif; ?>
</main>
<?php require_once 'includes/footer.php'; ?>
