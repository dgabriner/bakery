<?php
/**
 * Legacy period invoice generator — quarantined.
 * Live catalog pricing is not a billing path. Use Billing Center.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/billing.php';

bakery_billing_legacy_generator_emit_quarantine($_GET);
