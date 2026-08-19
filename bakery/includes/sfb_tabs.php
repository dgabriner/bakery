<?php
/** Shared SF Baker navigation. */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$sfbActiveTab = $sfbActiveTab ?? 'dashboard';
$sfbTabs = [
    'dashboard' => ['sfb_dashboard.php', 'sfb.tab_dashboard'],
    'starters' => ['sfb_starters.php', 'sfb.tab_starters'],
    'ingredients' => ['sfb_ingredients.php', 'sfb.tab_ingredients'],
    'formulas' => ['sfb_formulas.php', 'sfb.tab_formulas'],
    'batches' => ['sfb_batches.php', 'sfb.tab_batches'],
    'resources' => ['sfb_resources.php', 'sfb.tab_resources'],
    'community' => ['sfb_community.php', 'sfb.tab_community'],
];
?>
<nav class="sfb-tabs" aria-label="SF Baker">
  <?php foreach ($sfbTabs as $tab => $item): ?>
    <a href="<?php echo htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $tab === $sfbActiveTab ? ' class="active"' : ''; ?>><?php bakery_te($item[1]); ?></a>
  <?php endforeach; ?>
</nav>
