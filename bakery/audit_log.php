<?php
/**
 * Administrator audit trail: one row per changed field, before and after.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit_log.php';

bakery_require_role(['administrator']);

$filters = bakery_audit_parse_filters($_GET);
$ready = bakery_audit_ready($db);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log.csv"');
    echo bakery_audit_csv($db, $filters);
    exit;
}

$actors = $db->query('SELECT id, display_name FROM users ORDER BY display_name')->fetchAll(PDO::FETCH_ASSOC);
$total = $ready ? bakery_audit_count($db, $filters) : 0;
$rows = $ready ? bakery_audit_rows($db, $filters, 500, 'DESC') : [];

$exportQuery = $_GET;
unset($exportQuery['export']);
$exportQuery['export'] = 'csv';

$page_title = bakery_t('page.audit_log');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

function bakery_audit_page_choice(string $group, string $token): string
{
    if ($token === '') {
        return bakery_t('audit_log.all');
    }
    $key = 'audit_log.' . $group . '.' . $token;
    $label = bakery_t($key);
    return $label === $key ? $token : $label;
}
?>
<style>
.audit-page { max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem 2rem; font-family: Segoe UI, sans-serif; }
.audit-page h1 { margin: 0 0 0.35rem; color: #2c3e50; font-size: 1.6rem; }
.audit-page .lead { color: #666; margin: 0 0 1.25rem; }
.audit-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.25rem; }
.audit-filters { display: flex; flex-wrap: wrap; gap: 0.75rem 1rem; align-items: end; margin-bottom: 1rem; }
.audit-filters label { display: block; font-weight: 600; color: #333; font-size: 0.85rem; margin-bottom: 0.25rem; }
.audit-filters input, .audit-filters select { padding: 0.45rem 0.55rem; border: 1px solid #ccc; border-radius: 4px; }
.audit-filters button, .audit-export {
  font-size: 0.9rem; padding: 0.45rem 0.75rem; border-radius: 4px; border: 1px solid #1a5276;
  background: #1a5276; color: #fff; text-decoration: none; cursor: pointer;
}
.audit-export { background: #fff; color: #1a5276; }
.audit-note { color: #666; margin: 0 0 0.75rem; }
.audit-missing { background: #fff3cd; color: #856404; padding: 0.75rem 1rem; border-radius: 4px; }
.audit-wrap { overflow-x: auto; }
.audit-table { width: 100%; border-collapse: collapse; }
.audit-table th, .audit-table td { text-align: left; padding: 0.55rem 0.45rem; border-bottom: 1px solid #eee; vertical-align: top; font-size: 0.92rem; }
.audit-table th { color: #555; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; }
.audit-table code { font-size: 0.85rem; }
</style>
<div class="audit-page">
  <h1><?php echo htmlspecialchars(bakery_t('page.audit_log')); ?></h1>
  <p class="lead"><?php echo htmlspecialchars(bakery_t('audit_log.lead')); ?></p>
  <div class="audit-panel">
    <?php if (!$ready): ?>
      <p class="audit-missing"><?php echo htmlspecialchars(bakery_t('audit_log.missing_table')); ?></p>
    <?php endif; ?>
    <form class="audit-filters" method="get" action="audit_log.php">
      <div>
        <label for="audit-from"><?php bakery_te('audit_log.from'); ?></label>
        <input id="audit-from" type="date" name="from" value="<?php echo htmlspecialchars($filters['from']); ?>">
      </div>
      <div>
        <label for="audit-to"><?php bakery_te('audit_log.to'); ?></label>
        <input id="audit-to" type="date" name="to" value="<?php echo htmlspecialchars($filters['to']); ?>">
      </div>
      <div>
        <label for="audit-user"><?php bakery_te('audit_log.user'); ?></label>
        <select id="audit-user" name="user_id">
          <option value="0"><?php bakery_te('audit_log.all'); ?></option>
          <?php foreach ($actors as $actor): ?>
            <option value="<?php echo (int)$actor['id']; ?>" <?php echo (int)$filters['user_id'] === (int)$actor['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars((string)$actor['display_name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="audit-entity"><?php bakery_te('audit_log.entity'); ?></label>
        <select id="audit-entity" name="entity_type">
          <option value=""><?php bakery_te('audit_log.all'); ?></option>
          <?php foreach (bakery_audit_known_entities() as $entity): ?>
            <option value="<?php echo htmlspecialchars($entity); ?>" <?php echo $filters['entity_type'] === $entity ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars(bakery_audit_page_choice('entity', $entity)); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="audit-action"><?php bakery_te('audit_log.action'); ?></label>
        <select id="audit-action" name="action">
          <option value=""><?php bakery_te('audit_log.all'); ?></option>
          <?php foreach (bakery_audit_known_actions() as $action): ?>
            <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $filters['action'] === $action ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars(bakery_audit_page_choice('action', $action)); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit"><?php bakery_te('audit_log.apply'); ?></button>
      <a class="audit-export" href="audit_log.php?<?php echo htmlspecialchars(http_build_query($exportQuery)); ?>"><?php bakery_te('audit_log.export'); ?></a>
    </form>
    <?php if ($ready): ?>
      <p class="audit-note"><?php echo htmlspecialchars(bakery_t('audit_log.showing', ['shown' => (string)count($rows), 'total' => (string)$total])); ?></p>
    <?php endif; ?>
    <?php if ($ready && $rows === []): ?>
      <p class="audit-note"><?php bakery_te('audit_log.empty'); ?></p>
    <?php elseif ($rows !== []): ?>
      <div class="audit-wrap">
        <table class="audit-table">
          <thead>
            <tr>
              <th><?php bakery_te('audit_log.when'); ?></th>
              <th><?php bakery_te('audit_log.actor'); ?></th>
              <th><?php bakery_te('audit_log.entity'); ?></th>
              <th><?php bakery_te('audit_log.action'); ?></th>
              <th><?php bakery_te('audit_log.field'); ?></th>
              <th><?php bakery_te('audit_log.old'); ?></th>
              <th><?php bakery_te('audit_log.new'); ?></th>
              <th><?php bakery_te('audit_log.ip'); ?></th>
              <th><?php bakery_te('audit_log.path'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
                $actorName = trim((string)($row['actor_name'] ?? ''));
                if ($actorName === '') {
                    $actorName = bakery_audit_page_choice('actor_type', (string)$row['actor_type']);
                }
              ?>
              <tr>
                <td><?php echo htmlspecialchars((string)$row['created_at']); ?></td>
                <td><?php echo htmlspecialchars($actorName); ?></td>
                <td><?php echo htmlspecialchars((string)$row['entity_label']); ?><br><code><?php echo htmlspecialchars((string)$row['entity_type']); ?> #<?php echo (int)$row['entity_id']; ?></code></td>
                <td><?php echo htmlspecialchars(bakery_audit_page_choice('action', (string)$row['action'])); ?></td>
                <td><code><?php echo htmlspecialchars((string)$row['field']); ?></code></td>
                <td><?php echo htmlspecialchars((string)($row['old_value'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($row['new_value'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($row['ip'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($row['request_path'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
