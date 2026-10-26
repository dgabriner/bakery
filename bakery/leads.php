<?php
/**
 * Sales Leads. Administrators, managers, and sales.
 * List, edit, activity log, and CSV import / export live on this page.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sales_leads.php';

bakery_sales_leads_require_access();

$actor = bakery_current_user();
$actorId = (int)($actor['id'] ?? 0);
$salesUser = bakery_user_is_sales();
$ready = isset($db) && $db instanceof PDO && bakery_sales_leads_ready($db);
$notice = '';
$error = '';
$importReport = null;
$convertOpen = false;
$convertDuplicateId = 0;

if (!$ready) {
    $error = bakery_t('leads.schema_missing');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postedView = (string)($_GET['view'] ?? '');
    if (!in_array($postedView, bakery_sales_leads_view_names(), true)) {
        $postedView = '';
    }
    if ($action === 'merge') {
        if (!bakery_sales_leads_can_merge()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo bakery_t('leads.sales_forbidden_won') . "\n";
            exit;
        }
        $merged = bakery_sales_leads_merge(
            $db,
            (int)($_POST['keep_id'] ?? 0),
            (int)($_POST['drop_id'] ?? 0),
            $actorId
        );
        if (!empty($merged['ok'])) {
            $savedLead = bakery_sales_leads_find($db, (string)(int)$merged['keep_id']);
            $target = $savedLead && trim((string)($savedLead['external_id'] ?? '')) !== ''
                ? (string)$savedLead['external_id']
                : (string)(int)$merged['keep_id'];
            $query = ['lead' => $target, 'merged' => 1];
            if ($postedView !== '') {
                $query['view'] = $postedView;
            }
            header('Location: ' . BASE_URL . 'leads.php?' . http_build_query($query));
            exit;
        }
        $error = bakery_sales_leads_error_text((string)($merged['error'] ?? 'save_failed'));
    } elseif ($salesUser && bakery_sales_leads_sales_write_blocked($db, $_POST, $postedView)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo bakery_t('leads.sales_forbidden_won') . "\n";
        exit;
    } elseif ($action === 'save') {
        $payload = $_POST;
        if ($salesUser) {
            $payload = bakery_sales_leads_workspace_input($db, $payload);
        }
        $saved = bakery_sales_leads_save($db, $payload, $actorId);
        if (!empty($saved['ok'])) {
            $target = (string)($saved['external_id'] !== '' ? $saved['external_id'] : $saved['id']);
            header('Location: ' . BASE_URL . 'leads.php?lead=' . rawurlencode($target) . '&saved=1');
            exit;
        }
        $error = bakery_sales_leads_error_text((string)($saved['error'] ?? 'save_failed'));
    } elseif ($action === 'add_note') {
        $leadId = (int)($_POST['internal_id'] ?? 0);
        $added = bakery_sales_leads_add_note(
            $db,
            $leadId,
            $actorId,
            (string)($_POST['note'] ?? ''),
            (string)($_POST['activity_date'] ?? '')
        );
        if (!empty($added['ok'])) {
            $lead = bakery_sales_leads_find($db, (string)$leadId);
            $target = $lead && trim((string)($lead['external_id'] ?? '')) !== ''
                ? (string)$lead['external_id']
                : (string)$leadId;
            header('Location: ' . BASE_URL . 'leads.php?lead=' . rawurlencode($target) . '&noted=1');
            exit;
        }
        $error = bakery_sales_leads_error_text((string)($added['error'] ?? 'save_failed'));
    } elseif ($action === 'import_preview' || $action === 'import_commit') {
        $csv = '';
        if ($action === 'import_commit') {
            $token = (string)($_POST['import_token'] ?? '');
            $stored = $_SESSION['sales_leads_import'] ?? null;
            $storedToken = is_array($stored) ? (string)($stored['token'] ?? '') : '';
            if ($storedToken === '' || $token === '' || !hash_equals($storedToken, $token)) {
                $error = bakery_t('leads.import_expired');
            } else {
                $csv = (string)($stored['csv'] ?? '');
            }
        } elseif (!isset($_FILES['csv']) || (int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = bakery_t('leads.import_none');
        } else {
            $tmp = (string)($_FILES['csv']['tmp_name'] ?? '');
            $size = (int)($_FILES['csv']['size'] ?? 0);
            if ($tmp === '' || !is_uploaded_file($tmp) || $size > 2000000) {
                $error = bakery_t('leads.error.file_too_large');
            } else {
                $raw = file_get_contents($tmp);
                $csv = $raw === false ? '' : $raw;
            }
        }
        if ($error === '' && $csv !== '') {
            $report = bakery_sales_leads_import($db, $csv, [
                'dry_run' => $action === 'import_preview',
                'user_id' => $actorId,
            ]);
            if (empty($report['ok'])) {
                $error = bakery_sales_leads_error_text((string)($report['error'] ?? 'save_failed'));
            } elseif ($action === 'import_preview') {
                $token = bin2hex(random_bytes(16));
                $_SESSION['sales_leads_import'] = ['token' => $token, 'csv' => $csv];
                $report['token'] = $token;
                $importReport = $report;
            } else {
                unset($_SESSION['sales_leads_import']);
                $query = http_build_query([
                    'imported' => 1,
                    'new' => (int)$report['new'],
                    'updated' => (int)$report['updated'],
                    'unchanged' => (int)$report['unchanged'],
                    'rejected' => (int)$report['rejected'],
                ]);
                header('Location: ' . BASE_URL . 'leads.php?' . $query);
                exit;
            }
        }
    } elseif ($action === 'convert_create' || $action === 'convert_link') {
        $leadId = (int)($_POST['internal_id'] ?? 0);
        $converted = bakery_sales_leads_convert($db, $leadId, $actorId, [
            'mode' => $action === 'convert_link' ? 'link' : 'create',
            'name' => (string)($_POST['name'] ?? ''),
            'address' => (string)($_POST['address'] ?? ''),
            'neighborhood' => (string)($_POST['neighborhood'] ?? ''),
            'contact_channel' => (string)($_POST['contact_channel'] ?? ''),
            'notes' => (string)($_POST['notes'] ?? ''),
            'zone_id' => (string)($_POST['zone_id'] ?? ''),
            'link_customer_id' => (int)($_POST['link_customer_id'] ?? 0),
        ]);
        if (!empty($converted['ok'])) {
            $savedLead = bakery_sales_leads_find($db, (string)$leadId);
            $target = $savedLead && trim((string)($savedLead['external_id'] ?? '')) !== ''
                ? (string)$savedLead['external_id']
                : (string)$leadId;
            header('Location: ' . BASE_URL . 'leads.php?lead=' . rawurlencode($target) . '&converted=1');
            exit;
        }
        $error = bakery_sales_leads_error_text((string)($converted['error'] ?? 'save_failed'));
        $convertOpen = true;
        $convertDuplicateId = (int)($converted['customer_id'] ?? 0);
    } elseif ($action === 'log_sample') {
        $leadId = (int)($_POST['internal_id'] ?? 0);
        $logged = bakery_sales_leads_log_sample(
            $db,
            $leadId,
            $actorId,
            (string)($_POST['sample_date'] ?? '')
        );
        if (!empty($logged['ok'])) {
            $savedLead = bakery_sales_leads_find($db, (string)$leadId);
            $target = $savedLead && trim((string)($savedLead['external_id'] ?? '')) !== ''
                ? (string)$savedLead['external_id']
                : (string)$leadId;
            header('Location: ' . BASE_URL . 'leads.php?lead=' . rawurlencode($target) . '&sampled=1');
            exit;
        }
        $error = bakery_sales_leads_error_text((string)($logged['error'] ?? 'save_failed'));
    }
}

if (isset($_GET['saved'])) {
    $notice = bakery_t('leads.saved');
} elseif (isset($_GET['noted'])) {
    $notice = bakery_t('leads.note_saved');
} elseif (isset($_GET['converted'])) {
    $notice = bakery_t('leads.converted_saved');
} elseif (isset($_GET['sampled'])) {
    $notice = bakery_t('leads.sample_saved');
} elseif (isset($_GET['merged'])) {
    $notice = bakery_t('leads.merged');
} elseif (isset($_GET['imported'])) {
    $notice = bakery_t('leads.imported', [
        'new' => (string)(int)($_GET['new'] ?? 0),
        'updated' => (string)(int)($_GET['updated'] ?? 0),
        'unchanged' => (string)(int)($_GET['unchanged'] ?? 0),
        'rejected' => (string)(int)($_GET['rejected'] ?? 0),
    ]);
}

$view = (string)($_GET['view'] ?? '');
if (!in_array($view, bakery_sales_leads_view_names(), true)) {
    $view = '';
}
$zones = $ready ? bakery_sales_leads_zones($db) : [];
$editing = $ready && array_key_exists('lead', $_GET);
$leadKey = $editing ? (string)$_GET['lead'] : '';
$lead = ($editing && $leadKey !== 'new') ? bakery_sales_leads_find($db, $leadKey) : null;
if ($editing && $leadKey !== 'new' && !$lead && $error === '') {
    $error = bakery_sales_leads_error_text('not_found');
    $editing = false;
}
if ($salesUser && $ready && !$editing && (string)($_GET['export'] ?? '') !== 'csv') {
    $legacyTarget = '';
    if ($view === '') {
        $legacyTarget = 'view=pipeline';
    } elseif ($view === 'leads') {
        $legacyTarget = 'view=pipeline&focus=leads';
    } elseif ($view === 'prospects') {
        $legacyTarget = 'view=pipeline&focus=prospects';
    } elseif ($view === 'followups') {
        $legacyTarget = 'view=pipeline&due=1';
    } elseif ($view === 'won') {
        $legacyTarget = 'view=pipeline&stage=Won';
    }
    if ($legacyTarget !== '') {
        $keptStage = (string)($_GET['stage'] ?? '');
        if ($keptStage !== '' && $view !== 'won' && in_array($keptStage, bakery_sales_leads_stages(), true)) {
            $legacyTarget .= '&stage=' . rawurlencode($keptStage);
        }
        header('Location: ' . BASE_URL . 'leads.php?' . $legacyTarget);
        exit;
    }
}
if ($salesUser && $ready && !$editing && $view === '' && (string)($_GET['export'] ?? '') === 'csv') {
    $view = 'pipeline';
}

$filters = [
    'stage' => '',
    'priority' => (string)($_GET['priority'] ?? ''),
    'zone' => (string)($_GET['zone'] ?? ''),
    'show_lost' => '',
];
if ($view === 'followups') {
    $filters['followups'] = '1';
} elseif ($view !== '') {
    $filters['stages'] = bakery_sales_leads_view_stages($view);
    $requestedStage = (string)($_GET['stage'] ?? '');
    if (in_array($requestedStage, $filters['stages'], true)) {
        $filters['stage'] = $requestedStage;
    }
} elseif (!$salesUser) {
    $filters['stage'] = (string)($_GET['stage'] ?? '');
    $filters['show_lost'] = (string)($_GET['show_lost'] ?? '') === '1' ? '1' : '';
}

if ($ready && isset($_GET['export']) && (string)$_GET['export'] === 'csv' && !$editing) {
    $exportRows = bakery_sales_leads_list($db, $filters);
    $csv = bakery_sales_leads_export_csv($exportRows);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales-leads.csv"');
    header('Cache-Control: private, no-store');
    echo $csv;
    exit;
}

$viewTitleKeys = [
    'leads' => 'leads.view_leads',
    'prospects' => 'leads.view_prospects',
    'followups' => 'leads.view_followups',
    'won' => 'leads.view_won',
];
$viewHelpKeys = [
    'leads' => 'leads.view_leads_help',
    'prospects' => 'leads.view_prospects_help',
    'followups' => 'leads.view_followups_help',
    'won' => 'leads.view_won_help',
];
$duplicateIndex = $ready ? bakery_sales_leads_duplicate_index($db) : [];
$recordWonReadOnly = $salesUser && is_array($lead) && (string)($lead['stage'] ?? '') === 'Won';
$showWriterTools = !$salesUser || in_array($view, ['leads', 'prospects'], true);
$canMerge = bakery_sales_leads_can_merge();
$duplicateOthers = (is_array($lead) && isset($duplicateIndex[(int)$lead['id']])) ? $duplicateIndex[(int)$lead['id']] : [];
$page_title = isset($viewTitleKeys[$view]) ? bakery_t($viewTitleKeys[$view]) : bakery_t('page.leads');
$pageHelp = isset($viewHelpKeys[$view]) ? bakery_t($viewHelpKeys[$view]) : bakery_t('leads.subtitle');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
if ($salesUser) {
    require __DIR__ . '/includes/sales_workspace_page.php';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$form = [
    'internal_id' => '0',
    'external_id' => '',
    'name' => '',
    'type' => '',
    'address' => '',
    'neighborhood' => '',
    'website' => '',
    'contact_channel' => '',
    'contact_verified' => '',
    'current_supplier' => '',
    'cuisine' => '',
    'fit_score' => '',
    'fit_reason' => '',
    'priority' => '',
    'stage' => 'New',
    'next_action' => '',
    'next_action_date' => '',
    'route_fit' => '',
    'zone_id' => '',
    'source' => '',
    'source_url' => '',
    'notes' => '',
    'last_updated' => date('Y-m-d'),
    'change_note' => '',
];
if ($lead) {
    $form = [
        'internal_id' => (string)$lead['id'],
        'external_id' => (string)($lead['external_id'] ?? ''),
        'name' => (string)$lead['customer_name'],
        'type' => (string)($lead['lead_type'] ?? ''),
        'address' => (string)($lead['address'] ?? ''),
        'neighborhood' => (string)($lead['neighborhood'] ?? ''),
        'website' => (string)($lead['website'] ?? ''),
        'contact_channel' => (string)($lead['contact_channel'] ?? ''),
        'contact_verified' => (string)($lead['contact_verified'] ?? ''),
        'current_supplier' => (string)($lead['current_supplier'] ?? ''),
        'cuisine' => (string)($lead['cuisine'] ?? ''),
        'fit_score' => (string)($lead['fit_score'] ?? ''),
        'fit_reason' => (string)($lead['fit_reason'] ?? ''),
        'priority' => (string)($lead['priority'] ?? ''),
        'stage' => (string)($lead['stage'] ?? 'New'),
        'next_action' => (string)($lead['next_action'] ?? ''),
        'next_action_date' => (string)($lead['next_action_date'] ?? ''),
        'route_fit' => (string)($lead['route_fit'] ?? ''),
        'zone_id' => (string)($lead['zone_id'] ?? ''),
        'source' => (string)($lead['source'] ?? ''),
        'source_url' => (string)($lead['source_url'] ?? ''),
        'notes' => (string)($lead['notes'] ?? ''),
        'last_updated' => (string)($lead['last_updated'] ?? date('Y-m-d')),
        'change_note' => '',
    ];
}
if ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save') {
    foreach ($form as $key => $unused) {
        if (isset($_POST[$key])) {
            $form[$key] = (string)$_POST[$key];
        }
    }
}

$convertForm = [
    'name' => $lead ? (string)$lead['customer_name'] : '',
    'address' => $lead ? (string)($lead['address'] ?? '') : '',
    'neighborhood' => $lead ? (string)($lead['neighborhood'] ?? '') : '',
    'contact_channel' => $lead ? (string)($lead['contact_channel'] ?? '') : '',
    'notes' => $lead ? (string)($lead['notes'] ?? '') : '',
    'zone_id' => $lead ? (string)($lead['zone_id'] ?? '') : '',
];
if ($lead && empty($lead['customer_id']) && (string)($lead['stage'] ?? '') === 'Won' && isset($_GET['convert'])) {
    $convertOpen = true;
}
if ($convertOpen && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($convertForm as $key => $unused) {
        if (isset($_POST[$key])) {
            $convertForm[$key] = (string)$_POST[$key];
        }
    }
}
if ($convertOpen && $convertDuplicateId <= 0 && $ready) {
    $convertDuplicateId = bakery_sales_leads_customer_id_by_name($db, $convertForm['name']);
}

$exportQuery = http_build_query(array_filter([
    'export' => 'csv',
    'view' => $view,
    'stage' => $filters['stage'],
    'priority' => $filters['priority'],
    'zone' => $filters['zone'],
    'show_lost' => $filters['show_lost'],
], static function ($value) {
    return $value !== '';
}));
?>
<div class="container sales-leads">
    <div class="page-header">
        <h1><?php echo bakery_sales_leads_h($page_title); ?></h1>
        <p><?php echo bakery_sales_leads_h($pageHelp); ?></p>
    </div>

    <?php if ($notice !== ''): ?>
        <p class="sales-leads-banner" role="status"><?php echo bakery_sales_leads_h($notice); ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="sales-leads-error" role="alert"><?php echo bakery_sales_leads_h($error); ?></p>
    <?php endif; ?>

    <?php if ($ready && $editing): ?>
        <p><a class="btn btn-secondary" href="<?php echo bakery_sales_leads_h(BASE_URL . 'leads.php' . ($view !== '' ? '?view=' . rawurlencode($view) : '')); ?>"><?php echo bakery_sales_leads_h(bakery_t('leads.back')); ?></a></p>
        <?php if ($duplicateOthers !== []): ?>
            <p class="sales-leads-duplicate" role="status"><?php echo bakery_sales_leads_h(bakery_t('leads.duplicate_flag')); ?>
                <?php foreach ($duplicateOthers as $other): ?>
                    <?php
                    $otherId = trim((string)($other['external_id'] ?? ''));
                    if ($otherId === '') {
                        $otherId = (string)(int)$other['id'];
                    }
                    $otherHref = BASE_URL . 'leads.php?lead=' . rawurlencode($otherId) . ($view !== '' ? '&view=' . rawurlencode($view) : '');
                    ?>
                    <a href="<?php echo bakery_sales_leads_h($otherHref); ?>"><?php echo bakery_sales_leads_h($otherId); ?></a>
                <?php endforeach; ?>
            </p>
            <?php if ($canMerge): ?>
                <?php foreach ($duplicateOthers as $other): ?>
                    <form method="post" class="sales-leads-actions" action="<?php echo bakery_sales_leads_h(BASE_URL . 'leads.php?lead=' . rawurlencode($leadKey) . ($view !== '' ? '&view=' . rawurlencode($view) : '')); ?>">
                        <?php echo bakery_csrf_field(); ?>
                        <input type="hidden" name="action" value="merge">
                        <input type="hidden" name="keep_id" value="<?php echo (int)$lead['id']; ?>">
                        <input type="hidden" name="drop_id" value="<?php echo (int)$other['id']; ?>">
                        <button type="submit" class="btn btn-secondary"><?php echo bakery_sales_leads_h(bakery_t('leads.merge')); ?> <?php echo bakery_sales_leads_h(trim((string)($other['external_id'] ?? '')) !== '' ? (string)$other['external_id'] : (string)(int)$other['id']); ?></button>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($recordWonReadOnly): ?>
            <p class="sales-leads-banner"><?php echo bakery_sales_leads_h(bakery_t('leads.won_readonly')); ?></p>
        <?php endif; ?>
        <?php if (!$recordWonReadOnly): ?>
        <h2><?php echo bakery_sales_leads_h($lead ? bakery_t('leads.edit_title') : bakery_t('leads.new_title')); ?></h2>
        <?php if ($lead): ?>
            <?php
            $legacyContact = trim((string)($lead['contact_name'] ?? ''));
            $legacyPhone = trim((string)($lead['phone'] ?? ''));
            $legacyEmail = trim((string)($lead['email'] ?? ''));
            $legacyBits = [];
            if ($legacyContact !== '') {
                $legacyBits[] = bakery_t('leads.legacy_contact') . ': ' . $legacyContact;
            }
            if ($legacyPhone !== '') {
                $legacyBits[] = bakery_t('leads.legacy_phone') . ': ' . $legacyPhone;
            }
            if ($legacyEmail !== '') {
                $legacyBits[] = bakery_t('leads.legacy_email') . ': ' . $legacyEmail;
            }
            ?>
            <?php if ($legacyBits !== []): ?>
                <p><?php echo bakery_sales_leads_h(implode('. ', $legacyBits)); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <form method="post" class="sales-leads-form" action="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php?lead=<?php echo bakery_sales_leads_h($leadKey !== '' ? $leadKey : 'new'); ?>">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="internal_id" value="<?php echo bakery_sales_leads_h($form['internal_id']); ?>">
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_id')); ?>
                <input type="text" name="external_id" value="<?php echo bakery_sales_leads_h($form['external_id']); ?>" maxlength="64">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_name')); ?>
                <input type="text" name="name" required value="<?php echo bakery_sales_leads_h($form['name']); ?>" maxlength="255">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_type')); ?>
                <input type="text" name="type" value="<?php echo bakery_sales_leads_h($form['type']); ?>" maxlength="120">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_priority')); ?>
                <select name="priority">
                    <option value=""><?php echo bakery_sales_leads_h(bakery_t('leads.priority_none')); ?></option>
                    <?php foreach (bakery_sales_leads_priorities() as $priority): ?>
                        <option value="<?php echo bakery_sales_leads_h($priority); ?>" <?php echo $form['priority'] === $priority ? 'selected' : ''; ?>><?php echo bakery_sales_leads_h($priority); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_stage')); ?>
                <select name="stage">
                    <?php foreach (bakery_sales_leads_stages() as $stage): ?>
                        <option value="<?php echo bakery_sales_leads_h($stage); ?>" <?php echo $form['stage'] === $stage ? 'selected' : ''; ?>><?php echo bakery_sales_leads_h(bakery_t('leads.stage.' . $stage)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_fit_score')); ?>
                <select name="fit_score">
                    <option value=""><?php echo bakery_sales_leads_h(bakery_t('leads.score_none')); ?></option>
                    <?php foreach ([1, 2, 3, 4, 5] as $score): ?>
                        <option value="<?php echo $score; ?>" <?php echo (string)$form['fit_score'] === (string)$score ? 'selected' : ''; ?>><?php echo $score; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_address')); ?>
                <input type="text" name="address" value="<?php echo bakery_sales_leads_h($form['address']); ?>">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_neighborhood')); ?>
                <input type="text" name="neighborhood" value="<?php echo bakery_sales_leads_h($form['neighborhood']); ?>" maxlength="120">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_cuisine')); ?>
                <input type="text" name="cuisine" value="<?php echo bakery_sales_leads_h($form['cuisine']); ?>" maxlength="120">
            </label>
            <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_website')); ?>
                <input type="text" name="website" value="<?php echo bakery_sales_leads_h($form['website']); ?>" maxlength="255">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_contact_channel')); ?>
                <input type="text" name="contact_channel" value="<?php echo bakery_sales_leads_h($form['contact_channel']); ?>" maxlength="255">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_contact_verified')); ?>
                <input type="text" name="contact_verified" value="<?php echo bakery_sales_leads_h($form['contact_verified']); ?>" maxlength="255">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_current_supplier')); ?>
                <input type="text" name="current_supplier" value="<?php echo bakery_sales_leads_h($form['current_supplier']); ?>" maxlength="255">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_zone')); ?>
                <select name="zone_id">
                    <option value=""><?php echo bakery_sales_leads_h(bakery_t('leads.zone_none')); ?></option>
                    <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo (int)$zone['id']; ?>" <?php echo (string)$form['zone_id'] === (string)$zone['id'] ? 'selected' : ''; ?>><?php echo bakery_sales_leads_h($zone['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_route_fit')); ?>
                <textarea name="route_fit" rows="2"><?php echo bakery_sales_leads_h($form['route_fit']); ?></textarea>
            </label>
            <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_fit_reason')); ?>
                <textarea name="fit_reason" rows="2"><?php echo bakery_sales_leads_h($form['fit_reason']); ?></textarea>
            </label>
            <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_next_action')); ?>
                <input type="text" name="next_action" value="<?php echo bakery_sales_leads_h($form['next_action']); ?>" maxlength="255">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_next_action_date')); ?>
                <input type="date" name="next_action_date" value="<?php echo bakery_sales_leads_h(substr($form['next_action_date'], 0, 10)); ?>">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_last_updated')); ?>
                <input type="date" name="last_updated" value="<?php echo bakery_sales_leads_h(substr($form['last_updated'], 0, 10)); ?>">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_source')); ?>
                <input type="text" name="source" value="<?php echo bakery_sales_leads_h($form['source']); ?>" maxlength="255">
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_source_url')); ?>
                <input type="text" name="source_url" value="<?php echo bakery_sales_leads_h($form['source_url']); ?>" maxlength="512">
            </label>
            <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_notes')); ?>
                <textarea name="notes" rows="4"><?php echo bakery_sales_leads_h($form['notes']); ?></textarea>
            </label>
            <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_change_note')); ?>
                <input type="text" name="change_note" value="<?php echo bakery_sales_leads_h($form['change_note']); ?>">
            </label>
            <div class="span-2 sales-leads-actions">
                <button type="submit" class="btn btn-primary"><?php echo bakery_sales_leads_h(bakery_t('common.save')); ?></button>
            </div>
        </form>

        <?php if ($lead && !empty($lead['customer_id'])): ?>
            <p>
                <strong class="sales-leads-marker"><?php echo bakery_sales_leads_h(bakery_t('leads.converted_marker')); ?></strong>
                <?php echo bakery_sales_leads_h(bakery_t('leads.linked_customer')); ?>
                <a href="<?php echo bakery_sales_leads_h(BASE_URL); ?>customer_record.php?customer_id=<?php echo (int)$lead['customer_id']; ?>">#<?php echo (int)$lead['customer_id']; ?></a>
            </p>
        <?php elseif ($lead && (string)($lead['stage'] ?? '') === 'Won'): ?>
            <?php if ($convertOpen): ?>
                <h2><?php echo bakery_sales_leads_h(bakery_t('leads.convert_confirm_title')); ?></h2>
                <p><?php echo bakery_sales_leads_h(bakery_t('leads.convert_handoff')); ?></p>
                <p><?php echo bakery_sales_leads_h(bakery_t('leads.convert_notes_hint')); ?></p>
                <?php if ($convertDuplicateId > 0): ?>
                    <p class="sales-leads-error" role="alert">
                        <?php echo bakery_sales_leads_h(bakery_t('leads.duplicate_name')); ?>
                        <a href="<?php echo bakery_sales_leads_h(BASE_URL); ?>customer_record.php?customer_id=<?php echo (int)$convertDuplicateId; ?>">#<?php echo (int)$convertDuplicateId; ?></a>
                    </p>
                <?php endif; ?>
                <form method="post" class="sales-leads-form" action="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php?lead=<?php echo bakery_sales_leads_h($leadKey); ?>&amp;convert=1">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="internal_id" value="<?php echo (int)$lead['id']; ?>">
                    <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_name')); ?>
                        <input type="text" name="name" value="<?php echo bakery_sales_leads_h($convertForm['name']); ?>" maxlength="100" required>
                    </label>
                    <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_address')); ?>
                        <input type="text" name="address" value="<?php echo bakery_sales_leads_h($convertForm['address']); ?>">
                    </label>
                    <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_neighborhood')); ?>
                        <input type="text" name="neighborhood" value="<?php echo bakery_sales_leads_h($convertForm['neighborhood']); ?>" maxlength="120">
                    </label>
                    <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_contact_channel')); ?>
                        <input type="text" name="contact_channel" value="<?php echo bakery_sales_leads_h($convertForm['contact_channel']); ?>" maxlength="255">
                    </label>
                    <label><?php echo bakery_sales_leads_h(bakery_t('leads.field_zone')); ?>
                        <select name="zone_id" required>
                            <option value=""><?php echo bakery_sales_leads_h(bakery_t('leads.zone_none')); ?></option>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo (int)$zone['id']; ?>" <?php echo $convertForm['zone_id'] === (string)$zone['id'] ? 'selected' : ''; ?>><?php echo bakery_sales_leads_h($zone['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.field_notes')); ?>
                        <textarea name="notes" rows="3"><?php echo bakery_sales_leads_h($convertForm['notes']); ?></textarea>
                    </label>
                    <div class="span-2 sales-leads-actions">
                        <?php if ($convertDuplicateId > 0): ?>
                            <button type="submit" class="btn btn-primary" name="action" value="convert_link"><?php echo bakery_sales_leads_h(bakery_t('leads.link_existing')); ?></button>
                            <input type="hidden" name="link_customer_id" value="<?php echo (int)$convertDuplicateId; ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn <?php echo $convertDuplicateId > 0 ? 'btn-secondary' : 'btn-primary'; ?>" name="action" value="convert_create"><?php echo bakery_sales_leads_h(bakery_t('leads.convert_confirm')); ?></button>
                    </div>
                </form>
                <?php if ($convertDuplicateId > 0): ?>
                    <p><?php echo bakery_sales_leads_h(bakery_t('leads.link_existing_help')); ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p class="sales-leads-actions">
                    <a class="btn btn-primary" href="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php?lead=<?php echo bakery_sales_leads_h($leadKey); ?>&amp;convert=1"><?php echo bakery_sales_leads_h(bakery_t('leads.convert')); ?></a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($lead && empty($lead['customer_id'])): ?>
            <p><?php echo bakery_sales_leads_h(bakery_t('leads.sample_needs_customer')); ?></p>
        <?php elseif ($lead && empty($lead['sample_daily_order_id'])): ?>
            <h2><?php echo bakery_sales_leads_h(bakery_t('leads.sample_title')); ?></h2>
            <p><?php echo bakery_sales_leads_h(bakery_t('leads.sample_handoff')); ?></p>
            <form method="post" class="sales-leads-form" action="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php?lead=<?php echo bakery_sales_leads_h($leadKey); ?>">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="internal_id" value="<?php echo (int)$lead['id']; ?>">
                <label><?php echo bakery_sales_leads_h(bakery_t('leads.sample_date')); ?>
                    <input type="date" name="sample_date" value="<?php echo bakery_sales_leads_h(date('Y-m-d')); ?>" required>
                </label>
                <div class="span-2">
                    <button type="submit" class="btn btn-primary" name="action" value="log_sample"><?php echo bakery_sales_leads_h(bakery_t('leads.sample_submit')); ?></button>
                </div>
            </form>
        <?php elseif ($lead && !empty($lead['sample_daily_order_id'])): ?>
            <p><?php echo bakery_sales_leads_h(bakery_t('leads.sample_order', ['id' => (string)(int)$lead['sample_daily_order_id']])); ?></p>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($lead): ?>
            <?php $activity = bakery_sales_leads_activity($db, (int)$lead['id']); ?>
            <h2><?php echo bakery_sales_leads_h(bakery_t('leads.activity_title')); ?></h2>
            <?php if ($activity === []): ?>
                <p><?php echo bakery_sales_leads_h(bakery_t('leads.activity_empty')); ?></p>
            <?php else: ?>
                <div class="sales-leads-table-wrap">
                    <table class="sales-leads-table">
                        <thead>
                            <tr>
                                <th><?php echo bakery_sales_leads_h(bakery_t('leads.activity_date')); ?></th>
                                <th><?php echo bakery_sales_leads_h(bakery_t('leads.activity_user')); ?></th>
                                <th><?php echo bakery_sales_leads_h(bakery_t('leads.activity_stage')); ?></th>
                                <th><?php echo bakery_sales_leads_h(bakery_t('leads.activity_note')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activity as $entry): ?>
                            <?php
                            $from = trim((string)($entry['stage_from'] ?? ''));
                            $to = trim((string)($entry['stage_to'] ?? ''));
                            $stageText = '';
                            if ($from !== '' && $to !== '') {
                                $stageText = bakery_t('leads.activity_stage_change', [
                                    'from' => bakery_t('leads.stage.' . $from),
                                    'to' => bakery_t('leads.stage.' . $to),
                                ]);
                            } elseif ($to !== '') {
                                $stageText = bakery_t('leads.activity_stage_set', [
                                    'to' => bakery_t('leads.stage.' . $to),
                                ]);
                            }
                            $userName = trim((string)($entry['user_name'] ?? ''));
                            if ($userName === '') {
                                $userName = bakery_t('leads.activity_system');
                            }
                            ?>
                            <tr>
                                <td><?php echo bakery_sales_leads_h((string)$entry['activity_date']); ?></td>
                                <td><?php echo bakery_sales_leads_h($userName); ?></td>
                                <td><?php echo bakery_sales_leads_h($stageText); ?></td>
                                <td><?php echo bakery_sales_leads_h((string)($entry['note'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if (!$recordWonReadOnly): ?>
            <form method="post" class="sales-leads-form" action="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php?lead=<?php echo bakery_sales_leads_h($leadKey); ?>">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="internal_id" value="<?php echo (int)$lead['id']; ?>">
                <label><?php echo bakery_sales_leads_h(bakery_t('leads.activity_date')); ?>
                    <input type="date" name="activity_date" value="<?php echo bakery_sales_leads_h(date('Y-m-d')); ?>" required>
                </label>
                <label class="span-2"><?php echo bakery_sales_leads_h(bakery_t('leads.activity_note')); ?>
                    <textarea name="note" rows="2" required></textarea>
                </label>
                <div class="span-2">
                    <button type="submit" class="btn btn-secondary"><?php echo bakery_sales_leads_h(bakery_t('leads.activity_add')); ?></button>
                </div>
            </form>
            <?php endif; ?>

            <?php $contacts = bakery_sales_leads_contacts($db, (int)$lead['id']); ?>
            <?php if ($contacts !== []): ?>
                <h2><?php echo bakery_sales_leads_h(bakery_t('leads.earlier_contacts')); ?></h2>
                <div class="sales-leads-table-wrap">
                    <table class="sales-leads-table">
                        <thead>
                            <tr>
                                <th><?php echo bakery_sales_leads_h(bakery_t('leads.activity_date')); ?></th>
                                <th><?php echo bakery_sales_leads_h(bakery_t('leads.contact_mode')); ?></th>
                                <th><?php echo bakery_sales_leads_h(bakery_t('leads.activity_note')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contacts as $contact): ?>
                            <tr>
                                <td><?php echo bakery_sales_leads_h((string)$contact['contact_date']); ?></td>
                                <td><?php echo bakery_sales_leads_h((string)$contact['contact_mode']); ?></td>
                                <td><?php echo bakery_sales_leads_h((string)($contact['comment'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif ($ready): ?>
        <?php if ($showWriterTools): ?>
        <div class="sales-leads-actions">
            <a class="btn btn-primary" href="<?php echo bakery_sales_leads_h(BASE_URL . 'leads.php?lead=new' . ($view !== '' ? '&view=' . rawurlencode($view) : '')); ?>"><?php echo bakery_sales_leads_h(bakery_t('leads.add')); ?></a>
            <a class="btn btn-secondary" href="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php?<?php echo bakery_sales_leads_h($exportQuery); ?>"><?php echo bakery_sales_leads_h(bakery_t('leads.export')); ?></a>
        </div>
        <?php else: ?>
        <div class="sales-leads-actions">
            <a class="btn btn-secondary" href="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php?<?php echo bakery_sales_leads_h($exportQuery); ?>"><?php echo bakery_sales_leads_h(bakery_t('leads.export')); ?></a>
        </div>
        <?php endif; ?>

        <form method="get" class="sales-leads-filters" action="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php">
            <?php if ($view !== ''): ?>
                <input type="hidden" name="view" value="<?php echo bakery_sales_leads_h($view); ?>">
            <?php endif; ?>
            <?php if (!$salesUser && $view === ''): ?>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.filter_stage')); ?>
                <select name="stage">
                    <option value=""><?php echo bakery_sales_leads_h(bakery_t('common.all')); ?></option>
                    <?php foreach (bakery_sales_leads_stages() as $stage): ?>
                        <option value="<?php echo bakery_sales_leads_h($stage); ?>" <?php echo $filters['stage'] === $stage ? 'selected' : ''; ?>><?php echo bakery_sales_leads_h(bakery_t('leads.stage.' . $stage)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.filter_priority')); ?>
                <select name="priority">
                    <option value=""><?php echo bakery_sales_leads_h(bakery_t('common.all')); ?></option>
                    <?php foreach (bakery_sales_leads_priorities() as $priority): ?>
                        <option value="<?php echo bakery_sales_leads_h($priority); ?>" <?php echo strtoupper($filters['priority']) === $priority ? 'selected' : ''; ?>><?php echo bakery_sales_leads_h($priority); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.filter_zone')); ?>
                <select name="zone">
                    <option value=""><?php echo bakery_sales_leads_h(bakery_t('leads.zone_any')); ?></option>
                    <option value="none" <?php echo $filters['zone'] === 'none' ? 'selected' : ''; ?>><?php echo bakery_sales_leads_h(bakery_t('leads.zone_none')); ?></option>
                    <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo (int)$zone['id']; ?>" <?php echo $filters['zone'] === (string)$zone['id'] ? 'selected' : ''; ?>><?php echo bakery_sales_leads_h($zone['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if (!$salesUser && $view === ''): ?>
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.show_lost')); ?>
                <input type="checkbox" name="show_lost" value="1" <?php echo $filters['show_lost'] === '1' ? 'checked' : ''; ?>>
            </label>
            <?php endif; ?>
            <button type="submit" class="btn btn-secondary"><?php echo bakery_sales_leads_h(bakery_t('leads.filter_apply')); ?></button>
        </form>

        <?php
        $leads = bakery_sales_leads_list($db, $filters);
        $followupGroups = ['overdue' => [], 'today' => [], 'upcoming' => []];
        if ($view === 'followups') {
            $followupToday = date('Y-m-d');
            foreach ($leads as $followupRow) {
                $bucket = bakery_sales_leads_followup_bucket($followupRow, $followupToday);
                if (isset($followupGroups[$bucket])) {
                    $followupGroups[$bucket][] = $followupRow;
                }
            }
        }
        ?>
        <?php if ($leads === []): ?>
            <p><?php echo bakery_sales_leads_h(bakery_t('leads.empty')); ?></p>
        <?php elseif ($view === 'followups'): ?>
            <?php foreach (['overdue' => 'leads.followups_overdue', 'today' => 'leads.followups_today', 'upcoming' => 'leads.followups_upcoming'] as $bucket => $bucketKey): ?>
                <h2><?php echo bakery_sales_leads_h(bakery_t($bucketKey)); ?> (<?php echo count($followupGroups[$bucket]); ?>)</h2>
                <?php if ($followupGroups[$bucket] === []): ?>
                    <p><?php echo bakery_sales_leads_h(bakery_t('leads.empty')); ?></p>
                <?php else: ?>
                    <div class="sales-leads-table-wrap">
                        <table class="sales-leads-table">
                            <thead>
                                <tr>
                                    <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_name')); ?></th>
                                    <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_stage')); ?></th>
                                    <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_next_action')); ?></th>
                                    <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_next_action_date')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($followupGroups[$bucket] as $item): ?>
                                <?php
                                $publicId = trim((string)($item['external_id'] ?? ''));
                                if ($publicId === '') {
                                    $publicId = (string)$item['id'];
                                }
                                $href = BASE_URL . 'leads.php?lead=' . rawurlencode($publicId) . '&view=followups';
                                ?>
                                <tr>
                                    <td><a href="<?php echo bakery_sales_leads_h($href); ?>"><?php echo bakery_sales_leads_h((string)$item['customer_name']); ?></a>
                                        <?php if (!empty($duplicateIndex[(int)$item['id']])): ?>
                                            <strong class="sales-leads-duplicate"><?php echo bakery_sales_leads_h(bakery_t('leads.duplicate_flag')); ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo bakery_sales_leads_h(bakery_t('leads.stage.' . (string)($item['stage'] ?? ''))); ?></td>
                                    <td><?php echo bakery_sales_leads_h((string)($item['next_action'] ?? '')); ?></td>
                                    <td><?php echo bakery_sales_leads_h((string)($item['next_action_date'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="sales-leads-table-wrap">
                <table class="sales-leads-table">
                    <thead>
                        <tr>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_id')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_name')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_priority')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_stage')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_zone')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_next_action')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_next_action_date')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_fit_score')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($leads as $item): ?>
                        <?php
                        $publicId = trim((string)($item['external_id'] ?? ''));
                        if ($publicId === '') {
                            $publicId = (string)$item['id'];
                        }
                        $href = BASE_URL . 'leads.php?lead=' . rawurlencode($publicId) . ($view !== '' ? '&view=' . rawurlencode($view) : '');
                        $zoneLabel = trim((string)($item['zone_name'] ?? ''));
                        if ($zoneLabel === '') {
                            $zoneLabel = trim((string)($item['route_fit'] ?? ''));
                        }
                        ?>
                        <tr>
                            <td><a href="<?php echo bakery_sales_leads_h($href); ?>"><?php echo bakery_sales_leads_h($publicId); ?></a></td>
                            <td><?php echo bakery_sales_leads_h((string)$item['customer_name']); ?>
                                <?php if (!empty($duplicateIndex[(int)$item['id']])): ?>
                                    <strong class="sales-leads-duplicate"><?php echo bakery_sales_leads_h(bakery_t('leads.duplicate_flag')); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><?php echo bakery_sales_leads_h((string)($item['priority'] ?? '')); ?></td>
                            <td><?php
                                $stageName = (string)($item['stage'] ?? '');
                                echo bakery_sales_leads_h($stageName !== '' ? bakery_t('leads.stage.' . $stageName) : '');
                                if (!empty($item['customer_id'])) {
                                    echo ' <strong class="sales-leads-marker">' . bakery_sales_leads_h(bakery_t('leads.converted_marker')) . '</strong>';
                                }
                            ?></td>
                            <td><?php echo bakery_sales_leads_h($zoneLabel); ?></td>
                            <td><?php echo bakery_sales_leads_h((string)($item['next_action'] ?? '')); ?></td>
                            <td><?php echo bakery_sales_leads_h((string)($item['next_action_date'] ?? '')); ?></td>
                            <td><?php echo bakery_sales_leads_h((string)($item['fit_score'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($showWriterTools): ?>
        <h2><?php echo bakery_sales_leads_h(bakery_t('leads.import')); ?></h2>
        <p><?php echo bakery_sales_leads_h(bakery_t('leads.import_help')); ?></p>
        <form method="post" enctype="multipart/form-data" action="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="import_preview">
            <label><?php echo bakery_sales_leads_h(bakery_t('leads.import_file')); ?>
                <input type="file" name="csv" accept=".csv,text/csv" required>
            </label>
            <button type="submit" class="btn btn-secondary"><?php echo bakery_sales_leads_h(bakery_t('leads.import_preview')); ?></button>
        </form>

        <?php if (is_array($importReport)): ?>
            <p class="sales-leads-banner">
                <?php echo bakery_sales_leads_h(bakery_t('leads.imported', [
                    'new' => (string)(int)$importReport['new'],
                    'updated' => (string)(int)$importReport['updated'],
                    'unchanged' => (string)(int)$importReport['unchanged'],
                    'rejected' => (string)(int)$importReport['rejected'],
                ])); ?>
                <?php echo bakery_sales_leads_h(bakery_t('leads.dry_run_note')); ?>
            </p>
            <?php if ((int)$importReport['rejected'] < count($importReport['rows'])): ?>
                <form method="post" action="<?php echo bakery_sales_leads_h(BASE_URL); ?>leads.php">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="action" value="import_commit">
                    <input type="hidden" name="import_token" value="<?php echo bakery_sales_leads_h((string)$importReport['token']); ?>">
                    <button type="submit" class="btn btn-primary"><?php echo bakery_sales_leads_h(bakery_t('leads.import_commit')); ?></button>
                </form>
            <?php endif; ?>
            <div class="sales-leads-table-wrap">
                <table class="sales-leads-table">
                    <thead>
                        <tr>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.preview_line')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_id')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.field_name')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.preview_outcome')); ?></th>
                            <th><?php echo bakery_sales_leads_h(bakery_t('leads.preview_reason')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($importReport['rows'] as $previewRow): ?>
                        <tr>
                            <td><?php echo (int)$previewRow['line']; ?></td>
                            <td><?php echo bakery_sales_leads_h((string)$previewRow['id']); ?></td>
                            <td><?php echo bakery_sales_leads_h((string)$previewRow['name']); ?></td>
                            <td><?php echo bakery_sales_leads_h(bakery_t('leads.outcome.' . (string)$previewRow['outcome'])); ?></td>
                            <td><?php
                                $reason = (string)$previewRow['reason'];
                                echo bakery_sales_leads_h($reason === '' ? '' : bakery_sales_leads_error_text($reason));
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<style>
.sales-leads-banner,
.sales-leads-error {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin: 0.75rem 0;
}
.sales-leads-banner { background: #e7f4ec; }
.sales-leads-marker { font-weight: 700; }
.sales-leads-error { background: #fdecea; }
.sales-leads-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 0.75rem 0; }
.sales-leads-filters,
.sales-leads-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: 0.75rem 1rem;
    align-items: end;
    margin: 1rem 0 1.5rem;
}
.sales-leads-form .span-2 { grid-column: 1 / -1; }
.sales-leads-table-wrap { overflow-x: auto; margin: 0.5rem 0 1.25rem; }
.sales-leads-table { width: 100%; border-collapse: collapse; }
.sales-leads-table th,
.sales-leads-table td {
    text-align: left;
    padding: 0.45rem 0.55rem;
    border-bottom: 1px solid var(--sf-border, #ddd);
    vertical-align: top;
}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
