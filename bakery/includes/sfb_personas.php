<?php
/**
 * Synthetic Studio personas. Data, not GUI accounts.
 *
 * Catalog: 25 beginners, 20 weekend bakers, 15 hydration experimenters,
 * 15 whole-grain/rye, 15 Spanish-first, 10 synthetic mentors.
 * Seed wave 1 is the first 20 named bakers (Customer1/Customer2 reused).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/sfb_agent.php';
require_once __DIR__ . '/sfb_synthetic_eval.php';

function bakery_sfb_persona_reuse_names() {
    return ['Customer1', 'Customer2'];
}

function bakery_sfb_persona_cohorts() {
    return [
        'beginner' => 25,
        'weekend' => 20,
        'hydration' => 15,
        'whole_grain' => 15,
        'spanish' => 15,
        'mentor' => 10,
    ];
}

function bakery_sfb_persona_names() {
    return [
        'beginner' => [
            'Customer1', 'Customer2', 'Mina Park', 'Jordan Hale', 'Priya Nair',
            'Chris Lang', 'Avery Cole', 'Dana Brooks', 'Elliot Nash', 'Sage Patel',
            'Quinn Adler', 'Blair Cho', 'Reese Kim', 'Morgan Vale', 'Casey Dunn',
            'Jamie Ortiz', 'Taylor Singh', 'Drew Patel', 'Skyler Boone', 'Robin Shah',
            'Alex Rivera', 'Samira Bell', 'Nate Crowley', 'Ivy Tran', 'Pauline Cho',
        ],
        'weekend' => [
            'Sam Ortega', 'Riley Chen', 'Noah Blake', 'Harper Quinn',
            'Jules Abram', 'Keiko Mori', 'Owen Fraser', 'Nina Patel',
            'Micah Stone', 'Lena Ortiz', 'Brett Holloway', 'Sasha Green',
            'Ira Mendel', 'Tessa Ward', 'Colin Pike', 'Amara Singh',
            'Jonah West', 'Priya Cole', 'Evan Moss', 'Willa Hart',
        ],
        'hydration' => [
            'Elise Voss', 'Theo Marquez', 'Anika Shah', 'Hugo Lind', 'Nia Brooks',
            'Felix Kwan', 'Mara Ellison', 'Pavel Novak', 'Cora James', 'Yara Haddad',
            'Simon Peck', 'Leah Ortiz', 'Omar Farid', 'Greta Holm', 'Vicente Sol',
        ],
        'whole_grain' => [
            'Gwen Follett', 'Lars Holm', 'Mei Lin', 'Ruth Keller', 'Jonas Berg',
            'Hana Suzuki', 'Pete Rourke', 'Ingrid Dahl', 'Omar Khalil', 'Bea Moreau',
            'Stefan Krug', 'Asha Reddy', 'Nils Aalto', 'Clara Boone', 'Yusuf Rahman',
        ],
        'spanish' => [
            'Lucía Herrera', 'Diego Vargas', 'Carmen Ruiz', 'Sofía Mendoza', 'Mateo Cruz',
            'Valentina Soto', 'Andrés Peña', 'Isabel Navarro', 'Joaquín Reyes', 'Camila Ortiz',
            'Pablo Jiménez', 'Elena Castro', 'Raúl Domínguez', 'Marina López', 'Héctor Silva',
        ],
        'mentor' => [
            'Coach Mara', 'Coach Ben', 'Mentor Asha', 'Mentor Luis', 'Mentor June',
            'Mentor Omar', 'Mentor Rita', 'Mentor Kenji', 'Mentor Elena', 'Mentor Tomas',
        ],
    ];
}

function bakery_sfb_persona_template_names() {
    return [
        'Basic Sourdough',
        'Rustic Country',
        'Whole Wheat Sourdough',
        'High-Hydration Sourdough',
    ];
}

function bakery_sfb_persona_template_for_cohort($cohort) {
    switch ($cohort) {
        case 'hydration':
            return 'High-Hydration Sourdough';
        case 'whole_grain':
            return 'Whole Wheat Sourdough';
        case 'weekend':
            return 'Rustic Country';
        default:
            return 'Basic Sourdough';
    }
}

function bakery_sfb_persona_formula_plan(array $persona) {
    $all = bakery_sfb_persona_template_names();
    $primary = (string)($persona['template'] ?? bakery_sfb_persona_template_for_cohort($persona['cohort'] ?? ''));
    $slot = (int)($persona['slot'] ?? 0);
    $out = [];
    if (in_array($primary, $all, true)) {
        $out[] = $primary;
    }
    $count = !empty($persona['mentor']) ? 4 : 3;
    for ($i = 0; $i < count($all); $i++) {
        $name = $all[($slot + $i) % count($all)];
        if (!in_array($name, $out, true)) {
            $out[] = $name;
        }
        if (count($out) >= $count) {
            break;
        }
    }
    return $out;
}

function bakery_sfb_persona_defaults($cohort, $index) {
    $index = (int)$index;
    $hydration = [
        'beginner' => 68 + ($index % 8),
        'weekend' => 70 + ($index % 6),
        'hydration' => 80 + ($index % 7),
        'whole_grain' => 74 + ($index % 6),
        'spanish' => 70 + ($index % 7),
        'mentor' => 72 + ($index % 8),
    ];
    $temp = [
        'beginner' => 72 + ($index % 7),
        'weekend' => 70 + ($index % 8),
        'hydration' => 74 + ($index % 6),
        'whole_grain' => 73 + ($index % 6),
        'spanish' => 74 + ($index % 5),
        'mentor' => 73 + ($index % 6),
    ];
    $flours = [
        'beginner' => ['bread flour', 'bread flour with 5% rye', 'all-purpose flour', 'bread flour with 10% whole wheat'],
        'weekend' => ['bread flour with 10% whole wheat', 'bread flour', 'country mix with 15% whole wheat'],
        'hydration' => ['bread flour', 'bread flour with 5% rye', 'tipo 00 with bread flour'],
        'whole_grain' => ['30% whole wheat flour', '40% whole wheat flour', '20% rye and 20% whole wheat'],
        'spanish' => ['harina de pan', 'harina de fuerza con 10% integral', 'harina de pan con 15% centeno'],
        'mentor' => ['bread flour', 'bread flour with 12% whole wheat', '20% rye blend'],
    ];
    $speeds = ['hand mix', 'slap and fold', 'short mixer, low', 'autolyse then mix'];
    $turns = ['stretch_fold', 'coil_fold', 'lamination', 'slap_fold'];
    $list = $flours[$cohort] ?? $flours['beginner'];
    return [
        'hydration' => $hydration[$cohort] ?? 75,
        'temp_f' => $temp[$cohort] ?? 76,
        'flour' => $list[$index % count($list)],
        'bulk_hours' => 3 + ($index % 4),
        'feed_ratio' => $cohort === 'beginner' ? (($index % 2) ? '1:2:2' : '1:1:1') : '1:2:2',
        'mix_minutes' => 8 + ($index % 10),
        'mix_speed' => $speeds[$index % count($speeds)],
        'oven_temp' => 450 + (($index % 5) * 10),
        'turn_type' => $turns[$index % count($turns)],
        'loaves' => 2 + ($index % 3),
    ];
}

function bakery_sfb_persona_copy($cohort, $locale, array $defaults, $index = 0, $name = '') {
    $h = (int)$defaults['hydration'];
    $t = (int)$defaults['temp_f'];
    $flour = $defaults['flour'];
    $hours = (int)$defaults['bulk_hours'];
    $ratio = $defaults['feed_ratio'];
    $mix = (int)($defaults['mix_minutes'] ?? 12);
    $oven = (int)($defaults['oven_temp'] ?? 475);
    $loaves = (int)($defaults['loaves'] ?? 2);
    $index = (int)$index;
    $who = trim((string)$name);
    $tag = $who !== '' ? $who : ('baker ' . ($index + 1));

    if ($locale === 'es') {
        $es = [
            [
                'topic_title' => "{$tag}: bulk {$hours}h a {$t}F",
                'topic_body' => "Hoy el bulk fue {$hours} horas a {$t}F. Masa al {$h}% con {$flour}. Mezclé {$mix} minutos y el horno a {$oven}F.",
                'coach_ask' => "Bulk de {$hours}h a {$t}F con {$flour} al {$h}%. ¿Bajo el inóculo o acorto el fermentado?",
                'reply_body' => "A {$t}F yo recortaría 20 minutos del bulk y dejaría {$h}% con {$flour}. No subas el levain.",
            ],
            [
                'topic_title' => "{$tag}: masa floja al {$h}%",
                'topic_body' => "La masa se abrió en el bol. {$h}% de hidratación, {$flour}, {$t}F durante {$hours} horas. El mix fueron {$mix} minutos a mano.",
                'coach_ask' => "Al {$h}% con {$flour} a {$t}F la masa no tiene fuerza. ¿Más pliegues o menos agua?",
                'reply_body' => "Baja 2% de agua y haz un pliegue extra a los 45 minutos. Mantén {$t}F y {$flour}.",
            ],
            [
                'topic_title' => "{$tag}: nevera viernes, horno sábado",
                'topic_body' => "Mezclé el viernes, nevera 12 horas, horneé a {$oven}F. Antes de la nevera el bulk fue {$hours}h a {$t}F con {$flour} al {$h}%.",
                'coach_ask' => "¿12 horas de frío después de {$hours}h a {$t}F es demasiado para {$flour} al {$h}%?",
                'reply_body' => "Para {$flour} al {$h}% yo haría 2.5 horas a {$t}F y luego el frío. El horno a {$oven}F está bien.",
            ],
            [
                'topic_title' => "{$tag}: oreja floja a {$oven}F",
                'topic_body' => "La corteza no abrió. Bulk {$hours}h a {$t}F, {$h}% con {$flour}, vapor 15 minutos a {$oven}F.",
                'coach_ask' => "Sin oreja a {$oven}F. Bulk {$hours}h a {$t}F y {$flour} al {$h}%. ¿Más vapor o menos fermentado?",
                'reply_body' => "Recorta 30 minutos del bulk a {$t}F y deja el vapor 20 minutos. No cambies el {$h}% todavía.",
            ],
        ];
        $chosen = $es[$index % count($es)];
        return [
            'starter' => 'Masa madre de casa',
            'starter_blend' => $flour,
            'topic_title' => $chosen['topic_title'],
            'topic_body' => $chosen['topic_body'],
            'reply_body' => $chosen['reply_body'],
            'coach_ask' => $chosen['coach_ask'],
            'final_notes' => "{$loaves} panes. Masa a {$t}F, {$h}% agua, {$flour}, horno {$oven}F.",
            'peak_notes' => 'Pico en ' . (4 + ($index % 3)) . ' horas a temperatura ambiente',
            'failure_title' => "{$tag}: sin oreja a {$t}F, {$h}%",
            'failure_body' => "La corteza no abrió. Bulk a {$t}F durante {$hours} horas, {$h}% de hidratación, {$flour}. Horno {$oven}F con vapor 15 minutos.",
        ];
    }

    $en = [
        [
            'topic_title' => "{$tag}: first bulk {$hours}h at {$t}F",
            'topic_body' => "First real bulk: {$hours} hours at {$t}F. Formula is {$h}% water and {$flour}. Mixed {$mix} minutes, starter peaked after a {$ratio} feed.",
            'coach_ask' => "Bulk {$hours}h at {$t}F with {$flour} at {$h}%. Should I shorten it next time?",
            'reply_body' => "At {$t}F I would cut 20 minutes off bulk and keep {$h}% with {$flour}. Don't add more starter.",
            'category' => 'fermentation',
        ],
        [
            'topic_title' => "{$tag}: slack dough at {$h}%",
            'topic_body' => "Dough spread in the bowl. {$h}% hydration, {$flour}, {$t}F for {$hours} hours. Mix was {$mix} minutes by hand.",
            'coach_ask' => "At {$h}% with {$flour} at {$t}F I have no strength. Extra fold or less water?",
            'reply_body' => "Drop 2% water and add one fold at 45 minutes. Keep {$t}F and {$flour}.",
            'category' => 'formula',
        ],
        [
            'topic_title' => "{$tag}: Friday mix, Saturday {$oven}F",
            'topic_body' => "Mixed Friday, 12 hours in the fridge, baked Saturday at {$oven}F. Bulk before the fridge was {$hours}h at {$t}F, {$flour} at {$h}%.",
            'coach_ask' => "Is 12 hours cold after {$hours}h at {$t}F too long for {$flour} at {$h}%?",
            'reply_body' => "For {$flour} at {$h}% I would bulk 2.5 hours at {$t}F then refrigerate. {$oven}F is a fine bake.",
            'category' => 'weekend_schedule',
        ],
        [
            'topic_title' => "{$tag}: no ear at {$oven}F",
            'topic_body' => "No ear. Bulk {$hours}h at {$t}F, {$h}% with {$flour}, steam 15 minutes at {$oven}F.",
            'coach_ask' => "No ear at {$oven}F. Bulk {$hours}h at {$t}F, {$flour} at {$h}%. More steam or less ferment?",
            'reply_body' => "Shorten bulk 30 minutes at {$t}F and keep steam 20 minutes. Leave {$h}% alone for one bake.",
            'category' => 'shaping_baking',
        ],
        [
            'topic_title' => "{$tag}: rye in the starter, {$h}% dough",
            'topic_body' => "Fed the starter {$ratio} with rye in the blend. Dough was {$flour} at {$h}%, bulk {$hours}h at {$t}F, baked {$oven}F.",
            'coach_ask' => "Starter with rye, dough {$flour} at {$h}% and {$t}F. Acidity climbing — cut the bulk?",
            'reply_body' => "Yes: shave 30 minutes off bulk at {$t}F and keep the rye in the starter, not the dough, this week.",
            'category' => 'starter',
        ],
        [
            'topic_title' => "{$tag}: whole-grain water at {$h}%",
            'topic_body' => "Used {$flour} at {$h}%. Bulk {$hours} hours at {$t}F felt dry at mix ({$mix} min) then sticky by hour two.",
            'coach_ask' => "{$flour} at {$h}% and {$t}F — do I autolyse longer or just add water?",
            'reply_body' => "Autolyse 40 minutes before salt, keep {$h}% this bake, and judge strength at {$t}F after the second fold.",
            'category' => 'flours_mills',
        ],
        [
            'topic_title' => "{$tag}: coil folds vs stretch at {$t}F",
            'topic_body' => "Switched to coil folds on {$flour} at {$h}%. Dough sat {$hours}h at {$t}F. Mix {$mix} minutes, oven {$oven}F.",
            'coach_ask' => "Coil folds on {$flour} at {$h}% and {$t}F — still need a lamination?",
            'reply_body' => "Skip lamination. Two coils in the first 90 minutes at {$t}F is enough at {$h}%.",
            'category' => 'fermentation',
        ],
        [
            'topic_title' => "{$tag}: salt at 2.2%, {$h}% water",
            'topic_body' => "Bumped salt to 2.2% on {$flour} at {$h}%. Bulk slowed: {$hours}h at {$t}F still looked young. Baked {$oven}F.",
            'coach_ask' => "2.2% salt, {$flour}, {$h}%, {$t}F for {$hours}h — extend bulk or drop salt back?",
            'reply_body' => "Keep 2.2% and give it 30 more minutes at {$t}F. Don't chase it with more starter.",
            'category' => 'formula',
        ],
        [
            'topic_title' => "{$tag}: open crumb hunt at {$h}%",
            'topic_body' => "Pushed {$flour} to {$h}% looking for holes. Gentle coils every 30 minutes for {$hours} hours at {$t}F. Hands were sticky.",
            'coach_ask' => "{$h}% and {$t}F with {$flour} — wet enough, or am I just under-developed?",
            'reply_body' => "You're wet enough. One stronger fold at 30 minutes, then gentle. Keep {$hours}h only if dough temp stays {$t}F.",
            'category' => 'fermentation',
        ],
        [
            'topic_title' => "{$tag}: levain {$ratio}, peak in 5h",
            'topic_body' => "Levain {$ratio} peaked in 5 hours. Dough {$flour} at {$h}%, bulk {$hours}h at {$t}F, mix {$mix} min, bake {$oven}F.",
            'coach_ask' => "Peak in 5h after {$ratio}. Mix went in at {$t}F with {$flour} at {$h}%. Use it younger next time?",
            'reply_body' => "Use it just as it doubles, about 4 hours, then keep {$h}% and {$t}F. Ripe levain is shortening your bulk.",
            'category' => 'starter',
        ],
    ];
    $cohortMap = [
        'beginner' => [0, 4, 6, 9],
        'weekend' => [2, 0, 7, 3],
        'hydration' => [8, 1, 6, 9],
        'whole_grain' => [5, 4, 7, 1],
        'mentor' => [6, 9, 2, 8],
    ];
    $choices = $cohortMap[$cohort] ?? [0, 1, 2, 3];
    $chosen = $en[$choices[$index % count($choices)]];
    return [
        'starter' => 'Home starter',
        'starter_blend' => $flour,
        'topic_title' => $chosen['topic_title'],
        'topic_body' => $chosen['topic_body'],
        'reply_body' => $chosen['reply_body'],
        'coach_ask' => $chosen['coach_ask'],
        'final_notes' => "{$loaves} loaves. Dough {$t}F, {$h}% water, {$flour}, oven {$oven}F.",
        'peak_notes' => 'Peak in ' . (4 + ($index % 3)) . ' hours at room temp',
        'failure_title' => "{$tag}: no ear at {$t}F, {$h}% water",
        'failure_body' => "No ear on this bake. Bulk at {$t}F for {$hours} hours, {$h}% hydration, {$flour}. Oven {$oven}F with steam for 15 minutes.",
        'topic_category_override' => $chosen['category'] ?? null,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function bakery_sfb_persona_catalog() {
    static $catalog = null;
    if (is_array($catalog)) {
        return $catalog;
    }
    $catalog = [];
    $names = bakery_sfb_persona_names();
    $seedSlots = [
        'beginner' => 5,
        'weekend' => 4,
        'hydration' => 3,
        'whole_grain' => 3,
        'spanish' => 3,
        'mentor' => 2,
    ];
    foreach (bakery_sfb_persona_cohorts() as $cohort => $count) {
        $list = $names[$cohort] ?? [];
        $seedCount = (int)($seedSlots[$cohort] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $name = $list[$i] ?? ($cohort . ' baker ' . ($i + 1));
            $locale = $cohort === 'spanish' ? 'es' : 'en';
            $defaults = bakery_sfb_persona_defaults($cohort, $i);
            $copy = bakery_sfb_persona_copy($cohort, $locale, $defaults, $i, $name);
            $reuse = in_array($name, bakery_sfb_persona_reuse_names(), true);
            $category = $copy['topic_category_override'] ?? null;
            if ($category === null) {
                $category = $cohort === 'weekend' ? 'weekend_schedule' : ($cohort === 'whole_grain' ? 'flours_mills' : 'fermentation');
            }
            $catalog[] = [
                'key' => strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)),
                'name' => $name,
                'slot' => $i,
                'code' => $reuse ? ($name === 'Customer1' ? '1101' : '1102') : '',
                'reuse' => $reuse,
                'cohort' => $cohort,
                'locale' => $locale,
                'mentor' => $cohort === 'mentor',
                'seed_wave' => $i < $seedCount ? 1 : 2,
                'template' => bakery_sfb_persona_template_for_cohort($cohort),
                'formulas' => bakery_sfb_persona_formula_plan([
                    'cohort' => $cohort,
                    'slot' => $i,
                    'template' => bakery_sfb_persona_template_for_cohort($cohort),
                    'mentor' => $cohort === 'mentor',
                ]),
                'starter_name' => $copy['starter'],
                'starter_blend' => $copy['starter_blend'],
                'hydration' => $defaults['hydration'],
                'temp_f' => $defaults['temp_f'],
                'bulk_hours' => $defaults['bulk_hours'],
                'feed_ratio' => $defaults['feed_ratio'],
                'flour' => $defaults['flour'],
                'mix_minutes' => $defaults['mix_minutes'],
                'mix_speed' => $defaults['mix_speed'],
                'oven_temp' => $defaults['oven_temp'],
                'turn_type' => $defaults['turn_type'],
                'loaves' => $defaults['loaves'],
                'topic_category' => $category,
                'topic_title' => $copy['topic_title'],
                'topic_body' => $copy['topic_body'],
                'reply_body' => $copy['reply_body'],
                'coach_ask' => $copy['coach_ask'],
                'final_notes' => $copy['final_notes'],
                'peak_notes' => $copy['peak_notes'] ?? 'Peak in 5 hours at room temp',
                'failure_title' => $copy['failure_title'] ?? '',
                'failure_body' => $copy['failure_body'] ?? '',
                'post_failure' => in_array($name, ['Jordan Hale', 'Elise Voss', 'Carmen Ruiz'], true),
            ];
        }
    }
    return $catalog;
}

function bakery_sfb_persona_seed_set($limit = 20) {
    $limit = max(1, (int)$limit);
    $out = [];
    foreach (bakery_sfb_persona_catalog() as $persona) {
        if ((int)$persona['seed_wave'] === 1) {
            $out[] = $persona;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function bakery_sfb_persona_ensure_profiles_table(PDO $db, $allowProduction = false) {
    if (table_exists($db, 'sfb_persona_profiles')) {
        return;
    }
    $ddlOk = !function_exists('bakery_runtime_schema_ddl_allowed') || bakery_runtime_schema_ddl_allowed();
    if (!$ddlOk && !$allowProduction) {
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS sfb_persona_profiles (
            customer_id INT NOT NULL,
            persona_key VARCHAR(80) NOT NULL,
            cohort VARCHAR(40) NOT NULL,
            locale VARCHAR(8) NOT NULL DEFAULT 'en',
            is_mentor TINYINT(1) NOT NULL DEFAULT 0,
            seeded_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (customer_id),
            KEY idx_sfb_persona_key (persona_key),
            CONSTRAINT fk_sfb_persona_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (function_exists('bakery_forget_table_exists')) {
        bakery_forget_table_exists('sfb_persona_profiles');
    }
}

function bakery_sfb_persona_save_profile(PDO $db, $customerId, array $persona) {
    bakery_sfb_persona_ensure_profiles_table($db);
    if (!table_exists($db, 'sfb_persona_profiles')) {
        return;
    }
    $stmt = $db->prepare(
        'INSERT INTO sfb_persona_profiles (customer_id, persona_key, cohort, locale, is_mentor, seeded_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE persona_key = VALUES(persona_key), cohort = VALUES(cohort),
            locale = VALUES(locale), is_mentor = VALUES(is_mentor), seeded_at = VALUES(seeded_at)'
    );
    $stmt->execute([
        (int)$customerId,
        (string)$persona['key'],
        (string)$persona['cohort'],
        (string)$persona['locale'],
        !empty($persona['mentor']) ? 1 : 0,
    ]);
}

function bakery_sfb_persona_is_mentor(PDO $db, $customerId) {
    if (!table_exists($db, 'sfb_persona_profiles')) {
        return false;
    }
    $stmt = $db->prepare('SELECT is_mentor FROM sfb_persona_profiles WHERE customer_id = ? LIMIT 1');
    $stmt->execute([(int)$customerId]);
    return (int)$stmt->fetchColumn() === 1;
}

function bakery_sfb_agent_assert_test_db(PDO $db, $allowProduction = false) {
    $name = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
    if ($allowProduction) {
        if (!defined('USE_PROD_DB') || !USE_PROD_DB) {
            throw new RuntimeException('Production studio seed requires USE_PROD_DB=true.');
        }
        if ($name !== 'bakerysf') {
            throw new RuntimeException(
                'Production studio seed requires database bakerysf, got ' . ($name !== '' ? $name : '(none)') . '.'
            );
        }
        return;
    }
    bakery_sfb_agent_assert_local($db);
    if ($name !== 'bakerysf_test') {
        throw new RuntimeException(
            'Synthetic Studio seed is bakerysf_test only, or production with USE_PROD_DB and --allow-production.'
        );
    }
}

function bakery_sfb_persona_connect_fresh() {
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        PDO::ATTR_PERSISTENT => false,
    ];
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
        $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 15;
    }
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        $options
    );
    try {
        $db->exec('SET SESSION wait_timeout=28800, interactive_timeout=28800');
    } catch (Throwable $e) {
        // Host may forbid SESSION timeout changes.
    }
    return $db;
}

function bakery_sfb_persona_live_db(PDO $db) {
    try {
        $db->query('SELECT 1');
        return $db;
    } catch (Throwable $e) {
        $fresh = bakery_sfb_persona_connect_fresh();
        $GLOBALS['db'] = $fresh;
        return $fresh;
    }
}

function bakery_sfb_persona_is_gone_away(Throwable $e) {
    $msg = strtolower($e->getMessage());
    return strpos($msg, '2006') !== false
        || strpos($msg, 'gone away') !== false
        || strpos($msg, '2013') !== false;
}

function bakery_sfb_persona_feed_grams($ratio) {
    if ($ratio === '1:1:1') {
        return [50, 50, 50];
    }
    return [50, 100, 100];
}

function bakery_sfb_persona_find_studio_batch(PDO $db, $customerId, $needle = 'Studio journal') {
    foreach (bakery_sfb_batches($db, $customerId, 40) as $batch) {
        if (strpos((string)$batch['name'], $needle) !== false) {
            return $batch;
        }
    }
    return null;
}

function bakery_sfb_persona_find_batch_named(PDO $db, $customerId, $name) {
    $want = strtolower(trim((string)$name));
    foreach (bakery_sfb_batches($db, $customerId, 40) as $batch) {
        if (strtolower(trim((string)$batch['name'])) === $want) {
            return $batch;
        }
    }
    return null;
}

function bakery_sfb_persona_find_topic_titled(PDO $db, $customerId, $title) {
    if (!bakery_sfb_community_ready($db)) {
        return 0;
    }
    $stmt = $db->prepare(
        'SELECT id FROM sfb_community_topics
         WHERE author_customer_id = ? AND title = ?
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int)$customerId, trim((string)$title)]);
    return (int)$stmt->fetchColumn();
}

function bakery_sfb_persona_open_phases() {
    return ['mix', 'development', 'shape', 'bake'];
}

function bakery_sfb_persona_completed_bake_count(array $persona) {
    $counts = [
        'beginner' => 4,
        'weekend' => 5,
        'hydration' => 5,
        'whole_grain' => 4,
        'spanish' => 4,
        'mentor' => 6,
    ];
    return (int)($counts[$persona['cohort'] ?? ''] ?? 4);
}

function bakery_sfb_persona_batch_name(array $persona, array $spec) {
    $n = (int)($spec['n'] ?? 1);
    $name = (string)$persona['name'];
    if ($n === 1) {
        return 'Studio journal — ' . $name;
    }
    if ($n === 2 && in_array($persona['cohort'] ?? '', ['weekend', 'hydration'], true)) {
        return 'Studio journal week 2 — ' . $name;
    }
    if (empty($spec['complete'])) {
        return 'Open bake — ' . $name;
    }
    return 'Studio bake ' . $n . ' — ' . $name;
}

function bakery_sfb_persona_bake_plan(array $persona) {
    $formulas = $persona['formulas'] ?? bakery_sfb_persona_formula_plan($persona);
    $slot = (int)($persona['slot'] ?? 0);
    $completed = bakery_sfb_persona_completed_bake_count($persona);
    $loaves = [2, 2, 3, 2, 4, 3];
    $phases = bakery_sfb_persona_open_phases();
    $bakes = [];
    for ($n = 1; $n <= $completed; $n++) {
        $bakes[] = [
            'n' => $n,
            'formula' => $formulas[($n - 1) % count($formulas)],
            'days_ago' => 2 + ($n * 4) + ($slot % 3),
            'loaves' => $loaves[($n + $slot) % count($loaves)],
            'complete' => true,
            'phase' => 'done',
            'share' => $n <= 3 || ($n % 2 === 1),
            'post' => $n <= 2,
            'ask' => $n === 1 || $n === 3,
        ];
    }
    $bakes[] = [
        'n' => $completed + 1,
        'formula' => $formulas[$slot % count($formulas)],
        'days_ago' => 0,
        'loaves' => 0,
        'complete' => false,
        'phase' => $phases[$slot % count($phases)],
        'share' => false,
        'post' => false,
        'ask' => false,
    ];
    return $bakes;
}

function bakery_sfb_persona_extra_posts(array $persona) {
    $h = (int)$persona['hydration'];
    $t = (int)$persona['temp_f'];
    $flour = (string)$persona['flour'];
    $hours = (int)$persona['bulk_hours'];
    $oven = (int)($persona['oven_temp'] ?? 475);
    $tag = (string)$persona['name'];
    if (($persona['locale'] ?? 'en') === 'es') {
        return [
            [
                'title' => "{$tag}: harina y {$h}%",
                'body' => "Segunda tanda con {$flour} al {$h}%. Bulk {$hours}h a {$t}F, horno {$oven}F. El gluten se sintió distinto al primer mix.",
                'category' => 'flours_mills',
            ],
            [
                'title' => "{$tag}: pliegues a {$t}F",
                'body' => "Cambié a pliegues cada 40 minutos. Masa {$h}% con {$flour}, {$hours} horas a {$t}F.",
                'category' => 'fermentation',
            ],
        ];
    }
    $posts = [
        [
            'title' => "{$tag}: second formula, {$h}% still",
            'body' => "Copied a second formula. Still {$flour} at {$h}%, bulk {$hours}h at {$t}F, oven {$oven}F. The first card was too thin.",
            'category' => 'formula',
        ],
        [
            'title' => "{$tag}: folds at {$t}F this week",
            'body' => "Changed fold timing on {$flour} at {$h}%. Bulk {$hours} hours at {$t}F. Strength showed up after the second fold.",
            'category' => 'fermentation',
        ],
        [
            'title' => "{$tag}: bake notes at {$oven}F",
            'body' => "Baked at {$oven}F after {$hours}h bulk at {$t}F. {$flour} at {$h}%. Steam 15 minutes, lid off 20.",
            'category' => 'shaping_baking',
        ],
    ];
    $slot = (int)($persona['slot'] ?? 0);
    $pick = [$posts[$slot % 3], $posts[($slot + 1) % 3]];
    return $pick;
}

function bakery_sfb_persona_find_topic_for_batch(PDO $db, $customerId, $batchId) {
    if (!bakery_sfb_community_ready($db) || (int)$batchId <= 0) {
        return 0;
    }
    $stmt = $db->prepare(
        'SELECT id FROM sfb_community_topics
         WHERE author_customer_id = ? AND linked_batch_id = ?
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int)$customerId, (int)$batchId]);
    return (int)$stmt->fetchColumn();
}

function bakery_sfb_persona_log_bulk(PDO $db, $customerId, $batchId, array $persona, $startedAt, $untilPhase = 'done') {
    $start = new DateTimeImmutable($startedAt);
    $temp = (int)$persona['temp_f'];
    $hydration = (int)$persona['hydration'];
    $flour = (string)$persona['flour'];
    $primaryTurn = (string)($persona['turn_type'] ?? 'stretch_fold');
    $turns = [
        [30, $primaryTurn],
        [90, $primaryTurn === 'coil_fold' ? 'stretch_fold' : 'coil_fold'],
        [150, $primaryTurn],
    ];
    $order = ['mix' => 1, 'development' => 2, 'shape' => 3, 'bake' => 4, 'done' => 5];
    $until = $order[$untilPhase] ?? 5;

    if ($until >= 2) {
        foreach ($turns as $turn) {
            $at = $start->modify('+' . $turn[0] . ' minutes')->format('Y-m-d H:i:s');
            bakery_sfb_add_batch_turn(
                $db,
                $customerId,
                $batchId,
                $turn[1],
                $temp,
                $at,
                $temp . 'F dough, ' . $hydration . '% water'
            );
        }
        bakery_sfb_add_batch_temp($db, $customerId, $batchId, max(70, $temp - 2), 'mix', $startedAt, $flour);
        bakery_sfb_add_batch_temp(
            $db,
            $customerId,
            $batchId,
            $temp,
            'development',
            $start->modify('+90 minutes')->format('Y-m-d H:i:s'),
            $flour
        );
    }
    if ($until >= 4) {
        bakery_sfb_add_batch_temp(
            $db,
            $customerId,
            $batchId,
            $temp + 2,
            'bake',
            $start->modify('+6 hours')->format('Y-m-d H:i:s'),
            $flour
        );
    }
}

function bakery_sfb_persona_apply_phases(PDO $db, $customerId, $batchId, array $persona, $startedAt, $untilPhase = 'done', $allowCompleted = false) {
    $start = new DateTimeImmutable($startedAt);
    $hours = (int)($persona['bulk_hours'] ?? 4);
    $mixMin = (int)($persona['mix_minutes'] ?? 12);
    $oven = (int)($persona['oven_temp'] ?? 475);
    $flour = (string)$persona['flour'];
    $temp = (int)$persona['temp_f'];
    $order = ['mix' => 1, 'development' => 2, 'shape' => 3, 'bake' => 4, 'done' => 5];
    $until = $order[$untilPhase] ?? 5;
    if ($until < 2) {
        return;
    }

    bakery_sfb_save_batch_mix(
        $db,
        $customerId,
        $batchId,
        $mixMin,
        (string)($persona['mix_speed'] ?? 'hand mix'),
        $mixMin . ' min mix, ' . $flour,
        $start->modify('+' . $mixMin . ' minutes')->format('Y-m-d H:i:s'),
        $allowCompleted
    );
    $bulkStart = $start->modify('+' . $mixMin . ' minutes');
    $bulkEnd = $start->modify('+' . $hours . ' hours');
    bakery_sfb_save_batch_bulk(
        $db,
        $customerId,
        $batchId,
        $bulkStart->format('Y-m-d H:i:s'),
        $until >= 3 ? $bulkEnd->format('Y-m-d H:i:s') : '',
        $allowCompleted
    );
    if ($until < 3) {
        return;
    }
    bakery_sfb_save_batch_shape(
        $db,
        $customerId,
        $batchId,
        $bulkEnd->format('Y-m-d H:i:s'),
        'Shaped two boules, ' . $temp . 'F dough',
        $allowCompleted
    );
    if ($until < 4) {
        return;
    }
    $bakeStart = $bulkEnd->modify('+90 minutes');
    bakery_sfb_save_batch_bake(
        $db,
        $customerId,
        $batchId,
        $oven,
        $bakeStart->format('Y-m-d H:i:s'),
        $until >= 5 ? $bakeStart->modify('+45 minutes')->format('Y-m-d H:i:s') : '',
        $oven . 'F, steam 15 minutes, ' . $flour,
        $allowCompleted
    );
}

function bakery_sfb_persona_write_batch(PDO $db, $customerId, array $persona, $formulaId, $name, $startedAt, $endedAt, array $spec = []) {
    $complete = !array_key_exists('complete', $spec) || !empty($spec['complete']);
    $phase = $complete ? 'done' : (string)($spec['phase'] ?? 'development');
    $batch = bakery_sfb_agent_start_batch($db, $name, $formulaId, $customerId, $startedAt);
    $batchId = (int)$batch['batch_id'];
    bakery_sfb_persona_apply_phases($db, $customerId, $batchId, $persona, $startedAt, $phase, false);
    bakery_sfb_persona_log_bulk($db, $customerId, $batchId, $persona, $startedAt, $phase);
    if ($complete) {
        $loaves = (int)($spec['loaves'] ?? $persona['loaves'] ?? 2);
        bakery_sfb_complete_batch($db, $customerId, $batchId, $loaves, $persona['final_notes'], $endedAt);
        if (!isset($spec['share']) || !empty($spec['share'])) {
            bakery_sfb_share_batch($db, $customerId, $batchId);
        }
        if (!empty($spec['ask'])) {
            bakery_sfb_add_batch_message(
                $db,
                $batchId,
                'baker',
                (string)$persona['name'],
                (string)$persona['coach_ask'],
                'question',
                $customerId
            );
        }
    }
    return $batchId;
}

function bakery_sfb_persona_post(PDO $db, array $customer, array $persona, $title, $body, $category, $batchId) {
    bakery_sfb_synthetic_eval_assert_post([
        'title' => $title,
        'body' => $body,
        'customer' => $customer,
        'origin' => 'synthetic',
        'is_mentor' => !empty($persona['mentor']),
        'author_kind' => 'baker',
        'author_type' => 'baker',
    ]);
    return bakery_sfb_create_community_topic(
        $db,
        (int)$customer['id'],
        $title,
        $body,
        $category,
        $batchId
    );
}

function bakery_sfb_persona_reply_once(PDO $db, $topicId, array $reply) {
    $topicId = (int)$topicId;
    $customerId = (int)$reply['customer_id'];
    if ($topicId <= 0 || $customerId <= 0) {
        return 0;
    }
    $exists = $db->prepare(
        'SELECT id FROM sfb_community_replies WHERE topic_id = ? AND author_customer_id = ? LIMIT 1'
    );
    $exists->execute([$topicId, $customerId]);
    if ($exists->fetchColumn()) {
        return 0;
    }
    bakery_sfb_synthetic_eval_assert_post([
        'body' => $reply['body'],
        'customer' => $reply['customer'],
        'origin' => 'synthetic',
        'is_mentor' => !empty($reply['is_mentor']),
        'author_kind' => 'baker',
        'author_type' => 'baker',
    ]);
    return bakery_sfb_add_community_reply($db, $topicId, $customerId, $reply['body']);
}

/**
 * Fill journals, formulas, posts, and an in-progress batch past mix.
 *
 * @return bool True when anything new was written
 */
function bakery_sfb_persona_activate(PDO $db, array $customer, array $persona) {
    $customerId = (int)$customer['id'];
    $changed = false;
    bakery_sfb_agent_login_as_customer($db, $customerId);

    $starter = bakery_sfb_ensure_starter(
        $db,
        $customerId,
        $persona['starter_name'],
        $persona['starter_blend'],
        100,
        $persona['flour']
    );
    $feedings = bakery_sfb_starter_feedings($db, (int)$starter['id'], 50);
    if (count($feedings) < 6) {
        [$starterG, $flourG, $waterG] = bakery_sfb_persona_feed_grams($persona['feed_ratio']);
        $now = new DateTimeImmutable('now');
        $peak = (string)($persona['peak_notes'] ?? 'Peak in 5 hours at room temp');
        for ($day = 21; $day >= 1; $day -= 2) {
            bakery_sfb_add_starter_feeding(
                $db,
                $customerId,
                (int)$starter['id'],
                $starterG,
                $flourG,
                $waterG,
                $now->modify('-' . $day . ' days')->format('Y-m-d H:i:s'),
                $peak,
                $persona['feed_ratio'] . ' feed'
            );
        }
        $changed = true;
    }

    $formulaIds = [];
    foreach (bakery_sfb_persona_formula_plan($persona) as $template) {
        $formulaIds[$template] = bakery_sfb_agent_copy_formula($db, $template, $customerId);
    }

    foreach (bakery_sfb_batches($db, $customerId, 40) as $batch) {
        if (empty($batch['mix_completed_at'])) {
            bakery_sfb_persona_apply_phases(
                $db,
                $customerId,
                (int)$batch['id'],
                $persona,
                (string)($batch['started_at'] ?? date('Y-m-d H:i:s')),
                ($batch['status'] ?? '') === 'completed' ? 'done' : 'mix',
                true
            );
            $changed = true;
        }
    }

    $now = new DateTimeImmutable('now');
    $firstBatchId = 0;
    $active = bakery_sfb_active_batch($db, $customerId);
    foreach (bakery_sfb_persona_bake_plan($persona) as $spec) {
        $batchName = bakery_sfb_persona_batch_name($persona, $spec);
        $existing = bakery_sfb_persona_find_batch_named($db, $customerId, $batchName);
        $formulaName = (string)$spec['formula'];
        $formulaId = $formulaIds[$formulaName] ?? (int)reset($formulaIds);
        if (empty($spec['complete']) && $active && !$existing) {
            $existing = $active;
        }
        if ($existing) {
            if ($firstBatchId <= 0 && (int)$spec['n'] === 1) {
                $firstBatchId = (int)$existing['id'];
            }
            if (empty($existing['mix_completed_at'])) {
                bakery_sfb_persona_apply_phases(
                    $db,
                    $customerId,
                    (int)$existing['id'],
                    $persona,
                    (string)($existing['started_at'] ?? date('Y-m-d H:i:s')),
                    !empty($spec['complete']) ? 'done' : (string)$spec['phase'],
                    ($existing['status'] ?? '') === 'completed'
                );
                $changed = true;
            }
            if (empty($spec['complete'])
                && ($existing['status'] ?? '') === 'in_progress'
                && bakery_sfb_batch_phase($existing) === 'mix'
                && ($spec['phase'] ?? 'mix') !== 'mix'
            ) {
                bakery_sfb_persona_apply_phases(
                    $db,
                    $customerId,
                    (int)$existing['id'],
                    $persona,
                    (string)$existing['started_at'],
                    (string)$spec['phase'],
                    false
                );
                if (!bakery_sfb_batch_turns($db, (int)$existing['id'])) {
                    bakery_sfb_persona_log_bulk(
                        $db,
                        $customerId,
                        (int)$existing['id'],
                        $persona,
                        (string)$existing['started_at'],
                        (string)$spec['phase']
                    );
                }
                $changed = true;
            }
            continue;
        }

        $daysAgo = max(0, (int)$spec['days_ago']);
        $started = $daysAgo > 0 ? $now->modify('-' . $daysAgo . ' days') : $now;
        $batchId = bakery_sfb_persona_write_batch(
            $db,
            $customerId,
            $persona,
            $formulaId,
            $batchName,
            $started->format('Y-m-d H:i:s'),
            $started->modify('+8 hours')->format('Y-m-d H:i:s'),
            $spec
        );
        $changed = true;
        if ((int)$spec['n'] === 1) {
            $firstBatchId = $batchId;
        }
        if (empty($spec['complete'])) {
            $active = bakery_sfb_batch($db, $customerId, $batchId);
        }
    }

    if ($firstBatchId <= 0) {
        $studio = bakery_sfb_persona_find_studio_batch($db, $customerId, 'Studio journal —');
        $firstBatchId = $studio ? (int)$studio['id'] : 0;
    }

    if ($firstBatchId > 0 && bakery_sfb_persona_find_topic_titled($db, $customerId, $persona['topic_title']) <= 0) {
        bakery_sfb_persona_post(
            $db,
            $customer,
            $persona,
            $persona['topic_title'],
            $persona['topic_body'],
            $persona['topic_category'],
            $firstBatchId
        );
        $changed = true;
    }

    foreach (bakery_sfb_persona_extra_posts($persona) as $post) {
        if (bakery_sfb_persona_find_topic_titled($db, $customerId, $post['title']) > 0) {
            continue;
        }
        bakery_sfb_persona_post(
            $db,
            $customer,
            $persona,
            $post['title'],
            $post['body'],
            $post['category'],
            $firstBatchId
        );
        $changed = true;
    }

    if (!empty($persona['post_failure']) && ($persona['failure_title'] ?? '') !== '') {
        if (bakery_sfb_persona_find_topic_titled($db, $customerId, $persona['failure_title']) <= 0) {
            bakery_sfb_persona_post(
                $db,
                $customer,
                $persona,
                $persona['failure_title'],
                $persona['failure_body'],
                'failures',
                $firstBatchId
            );
            $changed = true;
        }
    }

    if ($firstBatchId > 0) {
        $messages = bakery_sfb_batch_messages($db, $firstBatchId);
        $hasQuestion = false;
        foreach ($messages as $message) {
            if (($message['message_type'] ?? '') === 'question') {
                $hasQuestion = true;
                break;
            }
        }
        if (!$hasQuestion) {
            bakery_sfb_add_batch_message(
                $db,
                $firstBatchId,
                'baker',
                (string)$persona['name'],
                (string)$persona['coach_ask'],
                'question',
                $customerId
            );
            $changed = true;
        }
    }

    return $changed;
}

/**
 * Write a full journal for a persona. Existing bakers are activated, not cloned.
 *
 * @return array{customer:array, created:bool, reused:bool, batch_id:int, topic_id:int, skipped:bool, enriched:bool}
 */
function bakery_sfb_persona_seed_one(PDO $db, array $persona, $refresh = false, $allowProduction = false) {
    bakery_ensure_sfb_schema($db);
    $created = bakery_sfb_agent_create_baker($db, $persona['name'], (string)($persona['code'] ?? ''), [
        'origin' => 'synthetic',
        'persona' => $persona['key'],
        'locale' => $persona['locale'],
        'adopt_reserved' => !empty($persona['reuse']),
        'allow_production' => $allowProduction,
        'cohort' => $persona['cohort'],
        'mentor' => !empty($persona['mentor']),
    ]);
    $customer = $created['customer'];
    $customerId = (int)$customer['id'];
    bakery_sfb_persona_save_profile($db, $customerId, $persona);
    bakery_sfb_agent_login_as_customer($db, $customerId);

    $hadStudio = bakery_sfb_persona_find_studio_batch($db, $customerId, 'Studio journal —');
    $enriched = bakery_sfb_persona_activate($db, $customer, $persona);
    $studio = bakery_sfb_persona_find_studio_batch($db, $customerId, 'Studio journal —');
    $topicId = bakery_sfb_persona_find_topic_titled($db, $customerId, $persona['topic_title']);
    if ($topicId <= 0 && $studio) {
        $topicId = bakery_sfb_persona_find_topic_for_batch($db, $customerId, (int)$studio['id']);
    }

    return [
        'customer' => $customer,
        'created' => $created['created'],
        'reused' => !$created['created'],
        'batch_id' => $studio ? (int)$studio['id'] : 0,
        'topic_id' => $topicId,
        'skipped' => (bool)$hadStudio,
        'enriched' => $enriched,
    ];
}

/** Additive history for bakers who already have a Studio journal. */
function bakery_sfb_persona_enrich_one(PDO $db, array $customer, array $persona, $studioBatchId = 0) {
    return bakery_sfb_persona_activate($db, $customer, $persona);
}

/**
 * Seed the first N personas on bakerysf_test. Mentors reply as bakers.
 *
 * @return array{seeded:int, reused:int, skipped:int, enriched:int, bakers:array}
 */
function bakery_sfb_persona_seed(PDO $db, $limit = 20, $refresh = false, $allowProduction = false) {
    bakery_sfb_agent_assert_test_db($db, $allowProduction);
    if ($allowProduction) {
        $limit = min(20, max(1, (int)$limit));
    }
    bakery_sfb_persona_ensure_profiles_table($db, $allowProduction);
    $admin = bakery_sfb_agent_login($db);
    if ($allowProduction) {
        $db = bakery_sfb_persona_live_db($db);
    }
    $personas = bakery_sfb_persona_seed_set($limit);
    $results = [
        'seeded' => 0,
        'reused' => 0,
        'skipped' => 0,
        'enriched' => 0,
        'failed' => 0,
        'pinned' => 0,
        'bakers' => [],
        'errors' => [],
        'catalog' => count(bakery_sfb_persona_catalog()),
        'limit' => count($personas),
        'database' => (string)$db->query('SELECT DATABASE()')->fetchColumn(),
    ];
    $circleTopics = [];
    $mentorReplies = [];

    foreach ($personas as $persona) {
        try {
            if ($allowProduction) {
                $db = bakery_sfb_persona_live_db($db);
            }
            $row = bakery_sfb_persona_seed_one($db, $persona, $refresh, $allowProduction);
        } catch (Throwable $e) {
            if ($allowProduction && bakery_sfb_persona_is_gone_away($e)) {
                try {
                    $db = bakery_sfb_persona_connect_fresh();
                    $GLOBALS['db'] = $db;
                    $row = bakery_sfb_persona_seed_one($db, $persona, $refresh, $allowProduction);
                } catch (Throwable $retry) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'name' => $persona['name'],
                        'error' => $retry->getMessage(),
                    ];
                    continue;
                }
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'name' => $persona['name'],
                    'error' => $e->getMessage(),
                ];
                continue;
            }
        }
        if ($row['skipped']) {
            $results['skipped']++;
        } else {
            $results['seeded']++;
        }
        if (!empty($row['enriched'])) {
            $results['enriched']++;
        }
        if ($row['reused'] || in_array($persona['name'], bakery_sfb_persona_reuse_names(), true)) {
            $results['reused']++;
        }
        if ((int)$row['topic_id'] > 0) {
            $circleTopics[] = (int)$row['topic_id'];
        }
        if (!empty($persona['mentor'])) {
            $mentorReplies[] = [
                'customer_id' => (int)$row['customer']['id'],
                'body' => $persona['reply_body'],
                'customer' => $row['customer'],
                'is_mentor' => true,
            ];
        }
        $results['bakers'][] = [
            'id' => (int)$row['customer']['id'],
            'name' => $row['customer']['name'],
            'origin' => $row['customer']['sfb_origin'] ?? 'synthetic',
            'cohort' => $persona['cohort'],
            'locale' => $persona['locale'],
            'reused' => $row['reused'] || in_array($persona['name'], bakery_sfb_persona_reuse_names(), true),
            'batch_id' => (int)$row['batch_id'],
            'topic_id' => (int)$row['topic_id'],
            'skipped' => $row['skipped'],
            'enriched' => !empty($row['enriched']),
            'reply_body' => $persona['reply_body'],
            'mentor' => !empty($persona['mentor']),
        ];
        fwrite(STDERR, ($row['skipped'] ? 'activated' : 'seeded') . ' ' . $persona['name'] . "\n");
    }

    $targets = $circleTopics;
    if ($allowProduction) {
        $db = bakery_sfb_persona_live_db($db);
    }
    if ($targets) {
        foreach ($mentorReplies as $i => $reply) {
            try {
                $topicId = $targets[$i % count($targets)];
                bakery_sfb_persona_reply_once($db, $topicId, $reply);
                if (isset($targets[($i + 3) % count($targets)])) {
                    bakery_sfb_persona_reply_once($db, $targets[($i + 3) % count($targets)], $reply);
                }
            } catch (Throwable $e) {
                $results['errors'][] = [
                    'name' => $reply['customer']['name'] ?? 'mentor',
                    'error' => 'mentor reply: ' . $e->getMessage(),
                ];
            }
        }
        $bakers = $results['bakers'];
        $count = count($bakers);
        for ($i = 0; $i < $count; $i++) {
            $peer = $bakers[($i + 5) % $count];
            if ((int)$peer['topic_id'] <= 0 || (int)$peer['id'] === (int)$bakers[$i]['id']) {
                continue;
            }
            try {
                bakery_sfb_persona_reply_once($db, (int)$peer['topic_id'], [
                    'customer_id' => (int)$bakers[$i]['id'],
                    'body' => (string)$bakers[$i]['reply_body'],
                    'customer' => ['id' => $bakers[$i]['id'], 'name' => $bakers[$i]['name']],
                    'is_mentor' => !empty($bakers[$i]['mentor']),
                ]);
            } catch (Throwable $e) {
                $results['errors'][] = [
                    'name' => $bakers[$i]['name'],
                    'error' => 'peer reply: ' . $e->getMessage(),
                ];
            }
        }
    }

    bakery_sfb_agent_stop_impersonation();
    if (function_exists('bakery_sfb_upsert_library_pins')) {
        try {
            $results['pinned'] = (int)bakery_sfb_upsert_library_pins($db, (int)($admin['id'] ?? 0));
        } catch (Throwable $e) {
            $results['errors'][] = [
                'name' => 'library pins',
                'error' => $e->getMessage(),
            ];
        }
    }
    return $results;
}

/**
 * @return array{ok:bool, bakers:int, customer1:int, customer2:int, standing_orders:int, topics:int, errors:array}
 */
function bakery_sfb_persona_verify(PDO $db, $limit = 20, $allowProduction = false) {
    bakery_sfb_agent_assert_test_db($db, $allowProduction);
    if ($allowProduction) {
        $limit = min(20, max(1, (int)$limit));
    }
    $errors = [];
    $names = [];
    foreach (bakery_sfb_persona_seed_set($limit) as $persona) {
        $names[] = $persona['name'];
    }
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $db->prepare(
        "SELECT id, name, sfb_origin, zone, zone_id, delivery_time
         FROM customers WHERE name IN ({$placeholders})"
    );
    $stmt->execute($names);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== count($names)) {
        $errors[] = 'expected ' . count($names) . ' named bakers, found ' . count($rows);
    }
    $c1 = 0;
    $c2 = 0;
    foreach ($rows as $row) {
        if (($row['name'] ?? '') === 'Customer1') {
            $c1++;
        }
        if (($row['name'] ?? '') === 'Customer2') {
            $c2++;
        }
        if (bakery_sfb_normalize_origin($row['sfb_origin'] ?? '') !== 'synthetic') {
            $errors[] = $row['name'] . ' origin is not synthetic';
        }
        if (($row['zone'] ?? null) !== null && $row['zone'] !== '') {
            $errors[] = $row['name'] . ' has a zone';
        }
        if ($row['zone_id'] !== null && $row['zone_id'] !== '') {
            $errors[] = $row['name'] . ' has a zone_id';
        }
    }
    if ($c1 !== 1) {
        $errors[] = 'Customer1 must exist exactly once';
    }
    if ($c2 !== 1) {
        $errors[] = 'Customer2 must exist exactly once';
    }

    $ids = array_map(function ($row) {
        return (int)$row['id'];
    }, $rows);
    $standing = 0;
    if ($ids && table_exists($db, 'standing_orders')) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $so = $db->prepare("SELECT COUNT(*) FROM standing_orders WHERE customer_id IN ({$in})");
        $so->execute($ids);
        $standing = (int)$so->fetchColumn();
        if ($standing > 0) {
            $errors[] = 'synthetics have standing orders';
        }
    }

    $topics = 0;
    $titles = [];
    if ($ids && bakery_sfb_community_ready($db)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $topicStmt = $db->prepare(
            "SELECT t.title, t.body, c.sfb_origin, c.name
             FROM sfb_community_topics t
             JOIN customers c ON c.id = t.author_customer_id
             WHERE t.author_customer_id IN ({$in})"
        );
        $topicStmt->execute($ids);
        foreach ($topicStmt->fetchAll(PDO::FETCH_ASSOC) as $topic) {
            $topics++;
            $title = trim((string)$topic['title']);
            if ($title !== '') {
                $titles[$title] = ($titles[$title] ?? 0) + 1;
            }
            $eval = bakery_sfb_eval_synthetic_text(
                trim($topic['title'] . "\n" . $topic['body']),
                ['origin' => $topic['sfb_origin']]
            );
            if (!$eval['ok']) {
                $errors[] = 'topic by ' . $topic['name'] . ' failed eval: ' . implode(',', $eval['reasons']);
            }
        }
        $replyStmt = $db->prepare(
            "SELECT r.body, r.author_kind, c.sfb_origin, c.name
             FROM sfb_community_replies r
             JOIN customers c ON c.id = r.author_customer_id
             WHERE r.author_customer_id IN ({$in})"
        );
        $replyStmt->execute($ids);
        foreach ($replyStmt->fetchAll(PDO::FETCH_ASSOC) as $reply) {
            if (strtolower((string)($reply['author_kind'] ?? 'baker')) === 'coach') {
                $errors[] = $reply['name'] . ' replied as coach';
            }
            $eval = bakery_sfb_eval_synthetic_text($reply['body'], ['origin' => $reply['sfb_origin']]);
            if (!$eval['ok']) {
                $errors[] = 'reply by ' . $reply['name'] . ' failed eval: ' . implode(',', $eval['reasons']);
            }
        }
    }

    $mixMissing = 0;
    $formulaNames = [];
    $completedCounts = [];
    $openPhases = [];
    $loaves = [];
    if ($ids && table_exists($db, 'sfb_batches')) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $batchStmt = $db->prepare(
            "SELECT customer_id, status, mix_completed_at, formula_id, loaf_count, name
             FROM sfb_batches WHERE customer_id IN ({$in})"
        );
        $batchStmt->execute($ids);
        $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($batches as $batch) {
            $cid = (int)$batch['customer_id'];
            if (($batch['status'] ?? '') === 'completed') {
                $completedCounts[$cid] = ($completedCounts[$cid] ?? 0) + 1;
                $loaves[$cid] = ($loaves[$cid] ?? 0) + (int)$batch['loaf_count'];
                if (empty($batch['mix_completed_at'])) {
                    $mixMissing++;
                }
            }
        }
        $phaseStmt = $db->prepare(
            "SELECT customer_id, mix_completed_at, bulk_started_at, shaped_at, bake_started_at, status
             FROM sfb_batches WHERE customer_id IN ({$in}) AND status = 'in_progress'"
        );
        $phaseStmt->execute($ids);
        foreach ($phaseStmt->fetchAll(PDO::FETCH_ASSOC) as $open) {
            $openPhases[] = bakery_sfb_batch_phase($open);
        }
        $formStmt = $db->prepare(
            "SELECT DISTINCT b.customer_id, COALESCE(s.formula_name, f.name) AS formula_name
             FROM sfb_batches b
             LEFT JOIN sfb_formulas f ON f.id = b.formula_id
             LEFT JOIN sfb_batch_formula_snapshots s ON s.batch_id = b.id
             WHERE b.customer_id IN ({$in})"
        );
        $formStmt->execute($ids);
        foreach ($formStmt->fetchAll(PDO::FETCH_ASSOC) as $formRow) {
            $cid = (int)$formRow['customer_id'];
            $fname = (string)$formRow['formula_name'];
            if ($fname === '') {
                continue;
            }
            $formulaNames[$cid][$fname] = true;
        }
    }
    foreach ($ids as $cid) {
        if (($completedCounts[$cid] ?? 0) < 3) {
            $errors[] = 'customer ' . $cid . ' has fewer than 3 completed batches';
        }
        if (count($formulaNames[$cid] ?? []) < 2) {
            $errors[] = 'customer ' . $cid . ' used fewer than 2 formulas';
        }
    }
    if ($mixMissing > 0) {
        $errors[] = $mixMissing . ' completed batches are still missing mix (stuck on step 1)';
    }
    $mixOpens = 0;
    foreach ($openPhases as $phase) {
        if ($phase === 'mix') {
            $mixOpens++;
        }
    }
    if ($openPhases && $mixOpens === count($openPhases) && count($openPhases) > 3) {
        $errors[] = 'every in-progress batch is still on mix';
    }
    foreach (bakery_sfb_persona_seed_set($limit) as $persona) {
        $want = trim((string)$persona['topic_title']);
        if ($want === '') {
            continue;
        }
        if ((int)($titles[$want] ?? 0) !== 1) {
            $errors[] = $persona['name'] . ' unique topic is missing';
        }
    }

    return [
        'ok' => $errors === [],
        'bakers' => count($rows),
        'customer1' => $c1,
        'customer2' => $c2,
        'standing_orders' => $standing,
        'topics' => $topics,
        'errors' => $errors,
        'completed_batches' => array_sum($completedCounts),
        'open_phases' => $openPhases,
    ];
}


