<?php
/**
 * Language release gate: fixture catalogs, no network.
 * CLI / local only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
define('BAKERY_PROMOTION_WORKER_LIBRARY', true);
require_once $root . '/scripts/hosted_promotion_worker.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

$current = "<?php\nreturn [\n    'keep.me' => 'Stay',\n    'baker_day.title' => 'Baker day',\n];\n";
$replacement = "<?php\nreturn [\n    'keep.me' => 'Stay',\n];\n";
$used = ['pack_list.unassigned_route' => true];
$dropped = promotion_lang_dropped_keys($replacement, $current, $used);
$assert($dropped === ['baker_day.title', 'pack_list.unassigned_route'], 'fixture drop lists the catalog key and the PHP key');
$assert(promotion_lang_dropped_keys($current . "    'added' => 'New',\n", $current, []) === [], 'a superset drops nothing');

$threw = false;
try {
    promotion_lang_gate('lang/en.php', $replacement, $current, $used, [], '', 'tester');
} catch (Throwable $e) {
    $threw = strpos($e->getMessage(), 'baker_day.title') !== false
        && strpos($e->getMessage(), 'pack_list.unassigned_route') !== false;
}
$assert($threw, 'the Live gate prints the missing keys and refuses');

$reasonMissing = false;
try {
    promotion_lang_gate('lang/en.php', $replacement, $current, [], ['baker_day.title'], '', 'tester');
} catch (Throwable $e) {
    $reasonMissing = strpos($e->getMessage(), 'allow-drop-reason') !== false;
}
$assert($reasonMissing, 'an override without a reason is refused');

$audit = promotion_lang_gate(
    'lang/en.php',
    $replacement,
    $current,
    [],
    ['baker_day.title'],
    'Danny approved removing this unused label',
    'repo'
);
$assert(
    strpos($audit, 'LANG KEY DROP ALLOWED by repo') !== false
        && strpos($audit, 'Danny approved removing this unused label') !== false
        && strpos($audit, 'baker_day.title') !== false,
    'an override logs who ran it and why'
);

$fixtureDir = sys_get_temp_dir() . '/lang-gate-' . getmypid();
mkdir($fixtureDir);
file_put_contents($fixtureDir . '/new.php', $replacement);
file_put_contents($fixtureDir . '/current.php', $current);
file_put_contents($fixtureDir . '/page.php', "<?php bakery_te('pack_list.unassigned_route'); bakery_t('keep.me');");
$python = <<<'PY'
import importlib.util, pathlib, sys
root = pathlib.Path(sys.argv[1])
spec = importlib.util.spec_from_file_location("lang_release_gate", root / "scripts" / "lang_release_gate.py")
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
new = pathlib.Path(sys.argv[2]).read_text()
current = pathlib.Path(sys.argv[3]).read_text()
used = mod.php_tree_keys(pathlib.Path(sys.argv[4]))
dropped = mod.dropped_keys(new, current, used)
sys.stdout.write("\n".join(dropped))
PY;
$script = $fixtureDir . '/check.py';
file_put_contents($script, $python);
$cmd = 'python3 ' . escapeshellarg($script) . ' '
    . escapeshellarg($root) . ' '
    . escapeshellarg($fixtureDir . '/new.php') . ' '
    . escapeshellarg($fixtureDir . '/current.php') . ' '
    . escapeshellarg($fixtureDir);
$pythonDropped = [];
exec($cmd, $pythonDropped, $pythonCode);
$assert($pythonCode === 0, 'python fixture gate runs');
$assert(implode("\n", $pythonDropped) === implode("\n", $dropped), 'python and the Live worker agree on the fixture drop');

$stage = (string)file_get_contents($root . '/scripts/cloud_agent_stage.py');
$uploader = (string)file_get_contents($root . '/scripts/sftp_upload.py');
$assert(strpos($stage, '--allow-drop-keys') !== false && strpos($stage, '--allow-drop-reason') !== false, 'cloud agent exposes the override');
$assert(strpos($uploader, 'guard_connected') !== false, 'the shared uploader refuses a language drop before put');
$assert(strpos($stage, '_guard_staging_lang') !== false, 'the Live queue checks language files before it writes a manifest');
$assert(strpos((string)file_get_contents($root . '/scripts/hosted_promotion_worker.php'), 'promotion_lang_gate') !== false, 'the Live worker refuses before it copies language files');

echo "\n$fail failed\n";
exit($fail > 0 ? 1 : 0);
