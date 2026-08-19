<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}
if (!function_exists('bakery_client_build_id') || !function_exists('bakery_asset_href')) {
    return;
}
?>
<meta name="app-build" content="<?php echo htmlspecialchars(bakery_client_build_id(), ENT_QUOTES, 'UTF-8'); ?>">
<meta name="app-base-url" content="<?php echo htmlspecialchars(defined('BASE_URL') ? BASE_URL : '/', ENT_QUOTES, 'UTF-8'); ?>">
<script defer src="<?php echo bakery_asset_href('includes/client_refresh.js'); ?>"></script>
