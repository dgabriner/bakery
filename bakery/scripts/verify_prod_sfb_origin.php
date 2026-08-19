<?php
/**
 * Read-only production check for Wave 0 origin tags. CLI only.
 */
define('ACCESS_ALLOWED', true);
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$root = dirname(__DIR__);
require_once $root . '/scripts/prod_db_cli.php';
$config = prod_db_load_envs($root);
prod_db_validate_targets($config['prod'], $config['local']);
$prod = $config['prod'];
$db = prod_db_pdo_connect($prod['host'], $prod['port'], $prod['user'], $prod['pass'], $prod['name']);

echo 'db=' . $db->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
$col = $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'sfb_origin'"
)->fetchColumn();
echo 'sfb_origin_column=' . ((int)$col === 1 ? 'yes' : 'NO') . PHP_EOL;

$stmt = $db->query(
    "SELECT id, name, sf_baker_enabled, sfb_origin
     FROM customers
     WHERE name IN ('Customer1','Customer2','65 Fairmount')
     ORDER BY name"
);
foreach ($stmt as $row) {
    echo $row['id'] . "\t" . $row['name'] . "\tbaker=" . $row['sf_baker_enabled'] . "\torigin=" . $row['sfb_origin'] . PHP_EOL;
}

$admin = $db->query(
    "SELECT u.id, u.email, u.is_active, r.slug
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE LOWER(u.email) = 'sfadmin@sourflour.org' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($admin) {
    echo 'SFAdmin id=' . $admin['id'] . ' active=' . $admin['is_active'] . ' role=' . $admin['slug'] . PHP_EOL;
} else {
    echo "SFAdmin MISSING\n";
}

$pinned = 0;
if ($db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sfb_community_topics' AND COLUMN_NAME = 'is_pinned'"
)->fetchColumn()) {
    $pinned = (int)$db->query('SELECT COUNT(*) FROM sfb_community_topics WHERE is_pinned = 1')->fetchColumn();
}
echo "pinned_topics={$pinned}\n";
echo "synthetic_customers=" . (int)$db->query("SELECT COUNT(*) FROM customers WHERE sfb_origin = 'synthetic'")->fetchColumn() . PHP_EOL;

$profiles = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sfb_persona_profiles'"
)->fetchColumn();
echo 'sfb_persona_profiles=' . ($profiles === 1 ? 'yes' : 'NO') . PHP_EOL;

$c1c2 = $db->query(
    "SELECT id FROM customers WHERE name IN ('Customer1','Customer2')"
)->fetchAll(PDO::FETCH_COLUMN);
if ($c1c2) {
    $ids = implode(',', array_map('intval', $c1c2));
    $standing = 0;
    if ((int)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'standing_orders'"
    )->fetchColumn() === 1) {
        $standing = (int)$db->query(
            "SELECT COUNT(*) FROM standing_orders WHERE customer_id IN ({$ids})"
        )->fetchColumn();
    }
    echo "c1_c2_standing_orders={$standing}\n";
}
