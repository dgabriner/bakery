<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_pack_yields.php';

$page_title = bakery_t('page.pan_dulce_quantities');
$message = null;
$error = null;

$allowedUnits = ['piece', 'tray', 'gallon', 'barra'];

try {
    $db->exec("CREATE TABLE IF NOT EXISTS pan_dulce_quantity_standards (
        dough_type_id INT NOT NULL PRIMARY KEY,
        standard_quantity INT NOT NULL DEFAULT 12,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_pan_dulce_quantity_standards_dough_type
            FOREIGN KEY (dough_type_id) REFERENCES dough_types(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pan_dulce_product_quantity_standards (
        product_id INT NOT NULL PRIMARY KEY,
        standard_quantity INT NOT NULL DEFAULT 12,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_pan_dulce_product_quantity_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $packReady = bakery_pack_yields_ready($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['form_action'] ?? 'standards');

        if ($action === 'standards') {
            $quantities = $_POST['standard_quantity'] ?? [];
            $stmt = $db->prepare("INSERT INTO pan_dulce_product_quantity_standards (product_id, standard_quantity)
                VALUES (?, ?) ON DUPLICATE KEY UPDATE standard_quantity = VALUES(standard_quantity)");
            foreach ($quantities as $productId => $quantity) {
                $quantity = (int)$quantity;
                if ($quantity < 0 || $quantity > 1000) {
                    throw new Exception(bakery_t('pan_dulce.err_standard_range'));
                }
                $stmt->execute([(int)$productId, $quantity]);
            }
            $message = bakery_t('pan_dulce.msg_standards_saved');
        } elseif ($action === 'yields') {
            if (!$packReady) {
                throw new Exception(bakery_t('pan_dulce.err_pack_not_ready'));
            }
            $units = $_POST['input_unit'] ?? [];
            $piecesPerInput = $_POST['pieces_per_input'] ?? [];
            $traysPerGallon = $_POST['trays_per_gallon'] ?? [];
            $piecesPerTray = $_POST['pieces_per_tray'] ?? [];
            $cutRatio = $_POST['cut_ratio'] ?? [];
            $notes = $_POST['yield_notes'] ?? [];
            $upsert = $db->prepare("
                INSERT INTO product_pack_yields
                  (product_id, input_unit, pieces_per_input, trays_per_gallon, pieces_per_tray, cut_ratio, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  input_unit = VALUES(input_unit),
                  pieces_per_input = VALUES(pieces_per_input),
                  trays_per_gallon = VALUES(trays_per_gallon),
                  pieces_per_tray = VALUES(pieces_per_tray),
                  cut_ratio = VALUES(cut_ratio),
                  notes = VALUES(notes)
            ");
            foreach ($units as $productId => $unit) {
                $productId = (int)$productId;
                $unit = strtolower(trim((string)$unit));
                if (!in_array($unit, $allowedUnits, true)) {
                    throw new Exception(bakery_t('pan_dulce.err_bad_unit'));
                }
                $ppi = trim((string)($piecesPerInput[$productId] ?? ''));
                $tpg = trim((string)($traysPerGallon[$productId] ?? ''));
                $ppt = trim((string)($piecesPerTray[$productId] ?? ''));
                $cr = trim((string)($cutRatio[$productId] ?? ''));
                $note = trim((string)($notes[$productId] ?? ''));
                $upsert->execute([
                    $productId,
                    $unit,
                    $ppi === '' ? null : (float)$ppi,
                    $tpg === '' ? null : (float)$tpg,
                    $ppt === '' ? null : (int)$ppt,
                    $cr === '' ? null : (float)$cr,
                    $note === '' ? null : $note,
                ]);
            }
            $message = bakery_t('pan_dulce.msg_yields_saved');
        } elseif ($action === 'dough_yields') {
            if (!$packReady) {
                throw new Exception(bakery_t('pan_dulce.err_pack_not_ready'));
            }
            $trays = $_POST['dough_trays_per_gallon'] ?? [];
            $pcs = $_POST['dough_pieces_per_tray'] ?? [];
            $notes = $_POST['dough_notes'] ?? [];
            $upsert = $db->prepare("
                INSERT INTO dough_type_pack_yields (dough_type_id, trays_per_gallon, pieces_per_tray, notes)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  trays_per_gallon = VALUES(trays_per_gallon),
                  pieces_per_tray = VALUES(pieces_per_tray),
                  notes = VALUES(notes)
            ");
            foreach ($trays as $doughTypeId => $tpg) {
                $doughTypeId = (int)$doughTypeId;
                $tpg = trim((string)$tpg);
                $ppt = trim((string)($pcs[$doughTypeId] ?? '20'));
                $note = trim((string)($notes[$doughTypeId] ?? ''));
                if ($tpg === '' || (float)$tpg <= 0) {
                    continue;
                }
                $pptInt = (int)$ppt;
                if ($pptInt <= 0) {
                    $pptInt = 20;
                }
                $upsert->execute([
                    $doughTypeId,
                    (float)$tpg,
                    $pptInt,
                    $note === '' ? null : $note,
                ]);
            }
            $message = bakery_t('pan_dulce.msg_dough_yields_saved');
        } elseif ($action === 'aliases') {
            if (!$packReady) {
                throw new Exception(bakery_t('pan_dulce.err_pack_not_ready'));
            }
            $newAlias = bakery_pack_normalize_alias((string)($_POST['new_alias'] ?? ''));
            $newProductId = (int)($_POST['new_alias_product_id'] ?? 0);
            $deleteId = (int)($_POST['delete_alias_id'] ?? 0);
            if ($deleteId > 0) {
                $db->prepare('DELETE FROM product_aliases WHERE id = ?')->execute([$deleteId]);
                $message = bakery_t('pan_dulce.msg_alias_deleted');
            } elseif ($newAlias !== '' && $newProductId > 0) {
                $db->prepare('
                    INSERT INTO product_aliases (alias, product_id, notes)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)
                ')->execute([$newAlias, $newProductId, 'manual']);
                $message = bakery_t('pan_dulce.msg_alias_saved');
            } else {
                throw new Exception(bakery_t('pan_dulce.err_alias_required'));
            }
        }
    }

    $types = $db->query("SELECT p.id AS product_id, p.name AS product_name,
        dt.id AS dough_type_id, dt.name AS dough_type_name,
        COALESCE(s.standard_quantity, 12) AS standard_quantity
        FROM products p
        JOIN dough_types dt ON dt.id = p.dough_type_id
        JOIN product_lines pl ON pl.id = dt.product_line_id AND pl.name = 'Pan Dulce'
        LEFT JOIN pan_dulce_product_quantity_standards s ON s.product_id = p.id
        ORDER BY dt.name, p.name")->fetchAll(PDO::FETCH_ASSOC);

    $yieldByProduct = [];
    $doughYields = [];
    $aliases = [];
    if ($packReady) {
        foreach ($db->query('SELECT * FROM product_pack_yields') as $row) {
            $yieldByProduct[(int)$row['product_id']] = $row;
        }
        $doughYields = $db->query("
            SELECT dt.id AS dough_type_id, dt.name AS dough_type_name,
                   y.trays_per_gallon, y.pieces_per_tray, y.notes
            FROM dough_types dt
            JOIN product_lines pl ON pl.id = dt.product_line_id AND pl.name = 'Pan Dulce'
            LEFT JOIN dough_type_pack_yields y ON y.dough_type_id = dt.id
            ORDER BY dt.name
        ")->fetchAll(PDO::FETCH_ASSOC);
        $aliases = $db->query("
            SELECT a.id, a.alias, a.product_id, a.notes, p.name AS product_name
            FROM product_aliases a
            JOIN products p ON p.id = a.product_id
            JOIN dough_types dt ON dt.id = p.dough_type_id
            JOIN product_lines pl ON pl.id = dt.product_line_id AND pl.name = 'Pan Dulce'
            ORDER BY a.alias
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
    $types = $types ?? [];
    $yieldByProduct = $yieldByProduct ?? [];
    $doughYields = $doughYields ?? [];
    $aliases = $aliases ?? [];
    $packReady = isset($packReady) ? $packReady : false;
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<div class="container" style="max-width:1100px; margin:0 auto; padding:20px;">
    <h1><?= htmlspecialchars(bakery_t('page.pan_dulce_quantities')) ?></h1>
    <p><?= bakery_t('pan_dulce.intro_standards') ?></p>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!$packReady): ?>
        <div class="alert alert-warning"><?= htmlspecialchars(bakery_t('pan_dulce.warn_run_migration')) ?></div>
    <?php endif; ?>

    <h2><?= htmlspecialchars(bakery_t('pan_dulce.heading_standards')) ?></h2>
    <form method="post" novalidate>
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="form_action" value="standards">
        <table class="items-table">
            <thead><tr>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_dough')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_product')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_standard')) ?></th>
            </tr></thead>
            <tbody>
            <?php $lastDoughType = null; foreach ($types ?? [] as $type): ?>
                <?php if ($lastDoughType !== $type['dough_type_name']): $lastDoughType = $type['dough_type_name']; ?>
                    <tr class="dough-group-row"><th colspan="3"><?= htmlspecialchars($lastDoughType) ?></th></tr>
                <?php endif; ?>
                <tr>
                    <td class="dough-type-repeat"><?= htmlspecialchars($type['dough_type_name']) ?></td>
                    <td><strong><?= htmlspecialchars($type['product_name']) ?></strong></td>
                    <td><input class="standard-quantity-input" type="number" min="0" max="1000" step="1" inputmode="numeric" name="standard_quantity[<?= (int)$type['product_id'] ?>]" value="<?= (int)$type['standard_quantity'] ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($types)): ?><p><?= htmlspecialchars(bakery_t('pan_dulce.empty_products')) ?></p><?php else: ?>
            <button class="btn btn-primary" type="submit"><?= htmlspecialchars(bakery_t('pan_dulce.btn_save_standards')) ?></button>
        <?php endif; ?>
    </form>

    <?php if ($packReady): ?>
    <h2 style="margin-top:2.5rem;"><?= htmlspecialchars(bakery_t('pan_dulce.heading_dough_yields')) ?></h2>
    <p><?= bakery_t('pan_dulce.intro_dough_yields') ?></p>
    <form method="post" novalidate>
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="form_action" value="dough_yields">
        <table class="items-table">
            <thead><tr>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_dough')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_trays_per_gal')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_pcs_per_tray')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_gal_pieces')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_notes')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($doughYields as $dy): ?>
                <?php
                $tpg = $dy['trays_per_gallon'];
                $ppt = $dy['pieces_per_tray'];
                $derived = ($tpg !== null && $ppt !== null)
                    ? bakery_pack_round_pieces((float)$tpg * (int)$ppt)
                    : null;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($dy['dough_type_name']) ?></strong></td>
                    <td><input type="number" step="0.000001" min="0" name="dough_trays_per_gallon[<?= (int)$dy['dough_type_id'] ?>]" value="<?= $tpg !== null ? htmlspecialchars((string)$tpg) : '' ?>"></td>
                    <td><input type="number" step="1" min="1" name="dough_pieces_per_tray[<?= (int)$dy['dough_type_id'] ?>]" value="<?= $ppt !== null ? (int)$ppt : 20 ?>"></td>
                    <td><?= $derived !== null ? (int)$derived : '—' ?></td>
                    <td><input type="text" name="dough_notes[<?= (int)$dy['dough_type_id'] ?>]" value="<?= htmlspecialchars((string)($dy['notes'] ?? '')) ?>" style="min-width:12rem;"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="btn btn-primary" type="submit"><?= htmlspecialchars(bakery_t('pan_dulce.btn_save_dough_yields')) ?></button>
    </form>

    <h2 style="margin-top:2.5rem;"><?= htmlspecialchars(bakery_t('pan_dulce.heading_product_yields')) ?></h2>
    <p><?= bakery_t('pan_dulce.intro_product_yields') ?></p>
    <form method="post" novalidate>
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="form_action" value="yields">
        <table class="items-table pack-yield-table">
            <thead><tr>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_dough')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_product')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_input_unit')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_pcs_per_input')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_trays_per_gal')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_pcs_per_tray')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_cut_ratio')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_gal_pieces')) ?></th>
                <th><?= htmlspecialchars(bakery_t('pan_dulce.col_notes')) ?></th>
            </tr></thead>
            <tbody>
            <?php $lastDoughType = null; foreach ($types as $type): ?>
                <?php
                $pid = (int)$type['product_id'];
                $y = $yieldByProduct[$pid] ?? null;
                $unit = $y['input_unit'] ?? 'piece';
                $galPieces = bakery_pack_pieces_per_gallon($db, $pid);
                if ($lastDoughType !== $type['dough_type_name']):
                    $lastDoughType = $type['dough_type_name'];
                ?>
                    <tr class="dough-group-row"><th colspan="9"><?= htmlspecialchars($lastDoughType) ?></th></tr>
                <?php endif; ?>
                <tr>
                    <td class="dough-type-repeat"><?= htmlspecialchars($type['dough_type_name']) ?></td>
                    <td><strong><?= htmlspecialchars($type['product_name']) ?></strong></td>
                    <td>
                        <select name="input_unit[<?= $pid ?>]">
                            <?php foreach ($allowedUnits as $u): ?>
                                <option value="<?= $u ?>"<?= $unit === $u ? ' selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" step="0.0001" min="0" name="pieces_per_input[<?= $pid ?>]" value="<?= $y && $y['pieces_per_input'] !== null ? htmlspecialchars((string)$y['pieces_per_input']) : '' ?>"></td>
                    <td><input type="number" step="0.000001" min="0" name="trays_per_gallon[<?= $pid ?>]" value="<?= $y && $y['trays_per_gallon'] !== null ? htmlspecialchars((string)$y['trays_per_gallon']) : '' ?>"></td>
                    <td><input type="number" step="1" min="0" name="pieces_per_tray[<?= $pid ?>]" value="<?= $y && $y['pieces_per_tray'] !== null ? (int)$y['pieces_per_tray'] : '' ?>"></td>
                    <td><input type="number" step="0.0001" min="0" name="cut_ratio[<?= $pid ?>]" value="<?= $y && $y['cut_ratio'] !== null ? htmlspecialchars((string)$y['cut_ratio']) : '' ?>"></td>
                    <td><?= $galPieces !== null ? (int)$galPieces : '—' ?></td>
                    <td><input type="text" name="yield_notes[<?= $pid ?>]" value="<?= htmlspecialchars((string)($y['notes'] ?? '')) ?>" style="min-width:10rem;"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="btn btn-primary" type="submit"><?= htmlspecialchars(bakery_t('pan_dulce.btn_save_yields')) ?></button>
    </form>

    <h2 style="margin-top:2.5rem;"><?= htmlspecialchars(bakery_t('pan_dulce.heading_aliases')) ?></h2>
    <p><?= bakery_t('pan_dulce.intro_aliases') ?></p>
    <form method="post" class="alias-add-form" novalidate style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:1rem;">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="form_action" value="aliases">
        <label><?= htmlspecialchars(bakery_t('pan_dulce.col_alias')) ?>
            <input type="text" name="new_alias" required maxlength="100">
        </label>
        <label><?= htmlspecialchars(bakery_t('pan_dulce.col_product')) ?>
            <select name="new_alias_product_id" required>
                <option value=""><?= htmlspecialchars(bakery_t('pan_dulce.choose_product')) ?></option>
                <?php foreach ($types as $type): ?>
                    <option value="<?= (int)$type['product_id'] ?>"><?= htmlspecialchars($type['product_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary" type="submit"><?= htmlspecialchars(bakery_t('pan_dulce.btn_add_alias')) ?></button>
    </form>
    <table class="items-table">
        <thead><tr>
            <th><?= htmlspecialchars(bakery_t('pan_dulce.col_alias')) ?></th>
            <th><?= htmlspecialchars(bakery_t('pan_dulce.col_product')) ?></th>
            <th><?= htmlspecialchars(bakery_t('pan_dulce.col_notes')) ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($aliases as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['alias']) ?></td>
                <td><?= htmlspecialchars($a['product_name']) ?></td>
                <td><?= htmlspecialchars((string)($a['notes'] ?? '')) ?></td>
                <td>
                    <form method="post" style="display:inline;" onsubmit="return confirm(<?= json_encode(bakery_t('pan_dulce.confirm_delete_alias')) ?>);">
                        <?php echo bakery_csrf_field(); ?>
                        <input type="hidden" name="form_action" value="aliases">
                        <input type="hidden" name="delete_alias_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-secondary" type="submit"><?= htmlspecialchars(bakery_t('pan_dulce.btn_delete')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($aliases === []): ?>
            <tr><td colspan="4"><?= htmlspecialchars(bakery_t('pan_dulce.empty_aliases')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<style>
.dough-group-row th{padding:9px 10px;background:#f4ebe5;color:#5d3b2d;text-align:left;font-size:.95rem}
.dough-type-repeat{color:#6d7771;font-size:.9rem}
.pack-yield-table input[type=number],.pack-yield-table select{max-width:7.5rem}
@media(max-width:600px){.items-table th,.items-table td{padding:9px 7px;vertical-align:top}.standard-quantity-input{width:90px;min-height:42px;font-size:16px}}
</style>
<?php require_once 'includes/footer.php'; ?>
