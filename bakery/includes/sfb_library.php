<?php
/**
 * Staff-authored SF Baker library: canonical debriefs, troubleshooting cards,
 * bilingual display, and the synthetic-text eval Prompt 1 seed must call.
 *
 * Copy lives in lang/en.php and lang/es.php. Database pins store a slug
 * sentinel so the same row renders in the active locale.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_sfb_library_body_sentinel($slug) {
    return 'sfb.library.slug:' . preg_replace('/[^a-z0-9_]/', '', (string)$slug);
}

function bakery_sfb_library_slug_from_topic($topic) {
    $body = is_array($topic) ? (string)($topic['body'] ?? '') : (string)$topic;
    if (preg_match('/^sfb\.library\.slug:([a-z0-9_]+)$/', trim($body), $m)) {
        return $m[1];
    }
    return '';
}

/**
 * 12 canonical teaching pieces + 20 action-first troubleshooting cards.
 *
 * @return array<int, array{slug:string,kind:string,category:string,title_key:string,lead_key:string,action_key:string,point_keys:array<int,string>}>
 */
function bakery_sfb_library_catalog() {
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }
    $catalog = [
        [
            'slug' => 'fermentation',
            'kind' => 'canonical',
            'category' => 'fermentation',
            'title_key' => 'sfb.debrief_fermentation_title',
            'lead_key' => 'sfb.debrief_fermentation_lead',
            'action_key' => 'sfb.canon_fermentation_next',
            'point_keys' => [
                'sfb.debrief_fermentation_point_1',
                'sfb.debrief_fermentation_point_2',
                'sfb.debrief_fermentation_point_3',
            ],
        ],
        [
            'slug' => 'formula',
            'kind' => 'canonical',
            'category' => 'formula',
            'title_key' => 'sfb.debrief_formula_title',
            'lead_key' => 'sfb.debrief_formula_lead',
            'action_key' => 'sfb.canon_formula_next',
            'point_keys' => [
                'sfb.debrief_formula_point_1',
                'sfb.debrief_formula_point_2',
                'sfb.debrief_formula_point_3',
            ],
        ],
        [
            'slug' => 'starter',
            'kind' => 'canonical',
            'category' => 'starter',
            'title_key' => 'sfb.debrief_starter_title',
            'lead_key' => 'sfb.debrief_starter_lead',
            'action_key' => 'sfb.canon_starter_next',
            'point_keys' => [
                'sfb.debrief_starter_point_1',
                'sfb.debrief_starter_point_2',
                'sfb.debrief_starter_point_3',
            ],
        ],
        [
            'slug' => 'strength',
            'kind' => 'canonical',
            'category' => 'shaping_baking',
            'title_key' => 'sfb.debrief_strength_title',
            'lead_key' => 'sfb.debrief_strength_lead',
            'action_key' => 'sfb.canon_strength_next',
            'point_keys' => [
                'sfb.debrief_strength_point_1',
                'sfb.debrief_strength_point_2',
                'sfb.debrief_strength_point_3',
            ],
        ],
        [
            'slug' => 'bake',
            'kind' => 'canonical',
            'category' => 'shaping_baking',
            'title_key' => 'sfb.debrief_bake_title',
            'lead_key' => 'sfb.debrief_bake_lead',
            'action_key' => 'sfb.canon_bake_next',
            'point_keys' => [
                'sfb.debrief_bake_point_1',
                'sfb.debrief_bake_point_2',
                'sfb.debrief_bake_point_3',
            ],
        ],
        [
            'slug' => 'sharing',
            'kind' => 'canonical',
            'category' => 'general',
            'title_key' => 'sfb.debrief_share_title',
            'lead_key' => 'sfb.debrief_share_lead',
            'action_key' => 'sfb.canon_share_next',
            'point_keys' => [
                'sfb.debrief_share_point_1',
                'sfb.debrief_share_point_2',
                'sfb.debrief_share_point_3',
            ],
        ],
        [
            'slug' => 'levain',
            'kind' => 'canonical',
            'category' => 'starter',
            'title_key' => 'sfb.canon_levain_title',
            'lead_key' => 'sfb.canon_levain_lead',
            'action_key' => 'sfb.canon_levain_next',
            'point_keys' => [
                'sfb.canon_levain_point_1',
                'sfb.canon_levain_point_2',
                'sfb.canon_levain_point_3',
            ],
        ],
        [
            'slug' => 'hydration',
            'kind' => 'canonical',
            'category' => 'formula',
            'title_key' => 'sfb.canon_hydration_title',
            'lead_key' => 'sfb.canon_hydration_lead',
            'action_key' => 'sfb.canon_hydration_next',
            'point_keys' => [
                'sfb.canon_hydration_point_1',
                'sfb.canon_hydration_point_2',
                'sfb.canon_hydration_point_3',
            ],
        ],
        [
            'slug' => 'flours_mills',
            'kind' => 'canonical',
            'category' => 'flours_mills',
            'title_key' => 'sfb.canon_flours_title',
            'lead_key' => 'sfb.canon_flours_lead',
            'action_key' => 'sfb.canon_flours_next',
            'point_keys' => [
                'sfb.canon_flours_point_1',
                'sfb.canon_flours_point_2',
                'sfb.canon_flours_point_3',
            ],
        ],
        [
            'slug' => 'weekend_schedule',
            'kind' => 'canonical',
            'category' => 'weekend_schedule',
            'title_key' => 'sfb.canon_weekend_title',
            'lead_key' => 'sfb.canon_weekend_lead',
            'action_key' => 'sfb.canon_weekend_next',
            'point_keys' => [
                'sfb.canon_weekend_point_1',
                'sfb.canon_weekend_point_2',
                'sfb.canon_weekend_point_3',
            ],
        ],
        [
            'slug' => 'bulk_vs_proof',
            'kind' => 'canonical',
            'category' => 'fermentation',
            'title_key' => 'sfb.canon_proof_title',
            'lead_key' => 'sfb.canon_proof_lead',
            'action_key' => 'sfb.canon_proof_next',
            'point_keys' => [
                'sfb.canon_proof_point_1',
                'sfb.canon_proof_point_2',
                'sfb.canon_proof_point_3',
            ],
        ],
        [
            'slug' => 'whole_grain',
            'kind' => 'canonical',
            'category' => 'flours_mills',
            'title_key' => 'sfb.canon_grain_title',
            'lead_key' => 'sfb.canon_grain_lead',
            'action_key' => 'sfb.canon_grain_next',
            'point_keys' => [
                'sfb.canon_grain_point_1',
                'sfb.canon_grain_point_2',
                'sfb.canon_grain_point_3',
            ],
        ],
        [
            'slug' => 'acetone_smell',
            'kind' => 'troubleshooting',
            'category' => 'starter',
            'title_key' => 'sfb.trouble_acetone_title',
            'lead_key' => 'sfb.trouble_acetone_symptom',
            'action_key' => 'sfb.trouble_acetone_next',
            'point_keys' => ['sfb.trouble_acetone_why'],
        ],
        [
            'slug' => 'hooch',
            'kind' => 'troubleshooting',
            'category' => 'starter',
            'title_key' => 'sfb.trouble_hooch_title',
            'lead_key' => 'sfb.trouble_hooch_symptom',
            'action_key' => 'sfb.trouble_hooch_next',
            'point_keys' => ['sfb.trouble_hooch_why'],
        ],
        [
            'slug' => 'feed_ratios',
            'kind' => 'troubleshooting',
            'category' => 'starter',
            'title_key' => 'sfb.trouble_feed_title',
            'lead_key' => 'sfb.trouble_feed_symptom',
            'action_key' => 'sfb.trouble_feed_next',
            'point_keys' => ['sfb.trouble_feed_why'],
        ],
        [
            'slug' => 'bakers_pct',
            'kind' => 'troubleshooting',
            'category' => 'formula',
            'title_key' => 'sfb.trouble_pct_title',
            'lead_key' => 'sfb.trouble_pct_symptom',
            'action_key' => 'sfb.trouble_pct_next',
            'point_keys' => ['sfb.trouble_pct_why'],
        ],
        [
            'slug' => 'dough_temp',
            'kind' => 'troubleshooting',
            'category' => 'fermentation',
            'title_key' => 'sfb.trouble_ddt_title',
            'lead_key' => 'sfb.trouble_ddt_symptom',
            'action_key' => 'sfb.trouble_ddt_next',
            'point_keys' => ['sfb.trouble_ddt_why'],
        ],
        [
            'slug' => 'overproof',
            'kind' => 'troubleshooting',
            'category' => 'fermentation',
            'title_key' => 'sfb.trouble_overproof_title',
            'lead_key' => 'sfb.trouble_overproof_symptom',
            'action_key' => 'sfb.trouble_overproof_next',
            'point_keys' => ['sfb.trouble_overproof_why'],
        ],
        [
            'slug' => 'underproof',
            'kind' => 'troubleshooting',
            'category' => 'fermentation',
            'title_key' => 'sfb.trouble_underproof_title',
            'lead_key' => 'sfb.trouble_underproof_symptom',
            'action_key' => 'sfb.trouble_underproof_next',
            'point_keys' => ['sfb.trouble_underproof_why'],
        ],
        [
            'slug' => 'scoring',
            'kind' => 'troubleshooting',
            'category' => 'shaping_baking',
            'title_key' => 'sfb.trouble_score_title',
            'lead_key' => 'sfb.trouble_score_symptom',
            'action_key' => 'sfb.trouble_score_next',
            'point_keys' => ['sfb.trouble_score_why'],
        ],
        [
            'slug' => 'steam',
            'kind' => 'troubleshooting',
            'category' => 'shaping_baking',
            'title_key' => 'sfb.trouble_steam_title',
            'lead_key' => 'sfb.trouble_steam_symptom',
            'action_key' => 'sfb.trouble_steam_next',
            'point_keys' => ['sfb.trouble_steam_why'],
        ],
        [
            'slug' => 'ear',
            'kind' => 'troubleshooting',
            'category' => 'shaping_baking',
            'title_key' => 'sfb.trouble_ear_title',
            'lead_key' => 'sfb.trouble_ear_symptom',
            'action_key' => 'sfb.trouble_ear_next',
            'point_keys' => ['sfb.trouble_ear_why'],
        ],
        [
            'slug' => 'gummy_crumb',
            'kind' => 'troubleshooting',
            'category' => 'failures',
            'title_key' => 'sfb.trouble_gummy_title',
            'lead_key' => 'sfb.trouble_gummy_symptom',
            'action_key' => 'sfb.trouble_gummy_next',
            'point_keys' => ['sfb.trouble_gummy_why'],
        ],
        [
            'slug' => 'dense_loaf',
            'kind' => 'troubleshooting',
            'category' => 'failures',
            'title_key' => 'sfb.trouble_dense_title',
            'lead_key' => 'sfb.trouble_dense_symptom',
            'action_key' => 'sfb.trouble_dense_next',
            'point_keys' => ['sfb.trouble_dense_why'],
        ],
        [
            'slug' => 'flour_swaps',
            'kind' => 'troubleshooting',
            'category' => 'flours_mills',
            'title_key' => 'sfb.trouble_swap_title',
            'lead_key' => 'sfb.trouble_swap_symptom',
            'action_key' => 'sfb.trouble_swap_next',
            'point_keys' => ['sfb.trouble_swap_why'],
        ],
        [
            'slug' => 'weekend_cold_proof',
            'kind' => 'troubleshooting',
            'category' => 'weekend_schedule',
            'title_key' => 'sfb.trouble_cold_title',
            'lead_key' => 'sfb.trouble_cold_symptom',
            'action_key' => 'sfb.trouble_cold_next',
            'point_keys' => ['sfb.trouble_cold_why'],
        ],
        [
            'slug' => 'starter_not_rising',
            'kind' => 'troubleshooting',
            'category' => 'starter',
            'title_key' => 'sfb.trouble_rise_title',
            'lead_key' => 'sfb.trouble_rise_symptom',
            'action_key' => 'sfb.trouble_rise_next',
            'point_keys' => ['sfb.trouble_rise_why'],
        ],
        [
            'slug' => 'slack_spread',
            'kind' => 'troubleshooting',
            'category' => 'shaping_baking',
            'title_key' => 'sfb.trouble_spread_title',
            'lead_key' => 'sfb.trouble_spread_symptom',
            'action_key' => 'sfb.trouble_spread_next',
            'point_keys' => ['sfb.trouble_spread_why'],
        ],
        [
            'slug' => 'no_spring',
            'kind' => 'troubleshooting',
            'category' => 'shaping_baking',
            'title_key' => 'sfb.trouble_spring_title',
            'lead_key' => 'sfb.trouble_spring_symptom',
            'action_key' => 'sfb.trouble_spring_next',
            'point_keys' => ['sfb.trouble_spring_why'],
        ],
        [
            'slug' => 'pale_crust',
            'kind' => 'troubleshooting',
            'category' => 'shaping_baking',
            'title_key' => 'sfb.trouble_pale_title',
            'lead_key' => 'sfb.trouble_pale_symptom',
            'action_key' => 'sfb.trouble_pale_next',
            'point_keys' => ['sfb.trouble_pale_why'],
        ],
        [
            'slug' => 'burnt_bottom',
            'kind' => 'troubleshooting',
            'category' => 'shaping_baking',
            'title_key' => 'sfb.trouble_burnt_title',
            'lead_key' => 'sfb.trouble_burnt_symptom',
            'action_key' => 'sfb.trouble_burnt_next',
            'point_keys' => ['sfb.trouble_burnt_why'],
        ],
        [
            'slug' => 'slice_too_soon',
            'kind' => 'troubleshooting',
            'category' => 'failures',
            'title_key' => 'sfb.trouble_slice_title',
            'lead_key' => 'sfb.trouble_slice_symptom',
            'action_key' => 'sfb.trouble_slice_next',
            'point_keys' => ['sfb.trouble_slice_why'],
        ],
    ];
    return $catalog;
}

function bakery_sfb_library_piece($slug) {
    $slug = (string)$slug;
    foreach (bakery_sfb_library_catalog() as $piece) {
        if ($piece['slug'] === $slug) {
            return $piece;
        }
    }
    return null;
}

function bakery_sfb_library_kind($kind) {
    $kind = (string)$kind;
    $out = [];
    foreach (bakery_sfb_library_catalog() as $piece) {
        if ($piece['kind'] === $kind) {
            $out[] = $piece;
        }
    }
    return $out;
}

function bakery_sfb_library_for_category($category) {
    $category = (string)$category;
    if ($category === '' || $category === 'all') {
        return bakery_sfb_library_kind('canonical');
    }
    $out = [];
    foreach (bakery_sfb_library_catalog() as $piece) {
        if ($piece['category'] === $category) {
            $out[] = $piece;
        }
    }
    return $out;
}

/** Every lang key the library must ship in en and es. */
function bakery_sfb_library_i18n_keys() {
    $keys = [
        'sfb.library_canonical_title',
        'sfb.library_trouble_title',
        'sfb.library_next_label',
        'sfb.library_pinned_eyebrow',
        'sfb.library_ask',
        'sfb.library_compose_observed',
        'sfb.library_review_title',
        'sfb.library_review_lead',
        'sfb.library_review_open',
        'sfb.library_diagnose_title',
        'sfb.library_diagnose_lead',
        'sfb.library_diagnose_from_card',
        'sfb.community_disclosure',
        'sfb.community_human_loaves',
        'sfb.community_process_hint',
        'sfb.resources_trouble_intro',
    ];
    foreach (bakery_sfb_library_catalog() as $piece) {
        $keys[] = $piece['title_key'];
        $keys[] = $piece['lead_key'];
        $keys[] = $piece['action_key'];
        foreach ($piece['point_keys'] as $pointKey) {
            $keys[] = $pointKey;
        }
    }
    return array_values(array_unique($keys));
}

function bakery_sfb_library_plain_body(array $piece) {
    $lines = [
        bakery_t($piece['lead_key']),
        '',
        bakery_t('sfb.library_next_label') . ': ' . bakery_t($piece['action_key']),
    ];
    foreach ($piece['point_keys'] as $pointKey) {
        $lines[] = '- ' . bakery_t($pointKey);
    }
    return implode("\n", $lines);
}

/**
 * Locale-aware title/body for a community topic. Library pins resolve from
 * lang files instead of frozen English stored in the row.
 *
 * @return array{title:string,body:string,library:?array}
 */
function bakery_sfb_community_topic_copy(array $topic) {
    $slug = bakery_sfb_library_slug_from_topic($topic);
    if ($slug !== '') {
        $piece = bakery_sfb_library_piece($slug);
        if ($piece) {
            return [
                'title' => bakery_t($piece['title_key']),
                'body' => bakery_sfb_library_plain_body($piece),
                'library' => $piece,
            ];
        }
    }
    return [
        'title' => (string)($topic['title'] ?? ''),
        'body' => (string)($topic['body'] ?? ''),
        'library' => null,
    ];
}

function bakery_sfb_library_pin_schema_ready(PDO $db) {
    if (!function_exists('bakery_sfb_community_ready') || !bakery_sfb_community_ready($db)) {
        return false;
    }
    if (!column_exists($db, 'sfb_community_topics', 'is_pinned')) {
        return false;
    }
    if (!column_exists($db, 'sfb_community_topics', 'author_kind')) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['sfb_community_topics', 'author_customer_id']);
    return strtoupper((string)$stmt->fetchColumn()) === 'YES';
}

/**
 * Upsert coach-authored pinned library topics. Stores lang-key sentinels so
 * Prompt 2 can render is_pinned rows in the active locale.
 *
 * @return int Number of library rows present after upsert
 */
function bakery_sfb_upsert_library_pins(PDO $db, $userId = 0) {
    if (!bakery_sfb_library_pin_schema_ready($db)) {
        return 0;
    }
    $userId = (int)$userId;
    if ($userId <= 0) {
        $userId = null;
    }
    $hasUserCol = column_exists($db, 'sfb_community_topics', 'author_user_id');
    $find = $db->prepare('SELECT id FROM sfb_community_topics WHERE body = ? LIMIT 1');
    $insertSql = 'INSERT INTO sfb_community_topics
        (author_customer_id, author_kind, linked_batch_id, category, title, body, is_pinned, is_locked';
    if ($hasUserCol) {
        $insertSql .= ', author_user_id';
    }
    $insertSql .= ') VALUES (NULL, \'coach\', NULL, ?, ?, ?, 1, 0';
    if ($hasUserCol) {
        $insertSql .= ', ?';
    }
    $insertSql .= ')';
    $insert = $db->prepare($insertSql);
    $update = $db->prepare(
        'UPDATE sfb_community_topics
         SET category = ?, title = ?, is_pinned = 1, author_kind = \'coach\', author_customer_id = NULL
         WHERE id = ?'
    );

    $count = 0;
    foreach (bakery_sfb_library_catalog() as $piece) {
        $sentinel = bakery_sfb_library_body_sentinel($piece['slug']);
        $find->execute([$sentinel]);
        $id = (int)$find->fetchColumn();
        $category = $piece['category'];
        if (function_exists('bakery_sfb_community_categories')
            && !in_array($category, bakery_sfb_community_categories(), true)
        ) {
            $category = 'general';
        }
        if ($id > 0) {
            $update->execute([$category, $piece['title_key'], $id]);
        } else {
            $params = [$category, $piece['title_key'], $sentinel];
            if ($hasUserCol) {
                $params[] = $userId;
            }
            $insert->execute($params);
        }
        $count++;
    }
    return $count;
}

/**
 * Eval hook for synthetic seed text (Prompt 1).
 *
 * Reject when the post has no process fact, invents Sour Flour wholesale
 * secrets, or could pass as an unlabeled real baker.
 *
 * @return array{ok:bool,reasons:array<int,string>}
 */
function bakery_sfb_eval_synthetic_text($text, array $context = []) {
    $text = trim((string)$text);
    $reasons = [];
    if ($text === '') {
        return ['ok' => false, 'reasons' => ['empty']];
    }

    $strippedBrand = preg_replace('/sour\s+flour/i', ' ', $text);
    $hasTemp = (bool)preg_match('/\b\d{1,3}(\.\d+)?\s*°?\s*[fc]\b/i', $text)
        || (bool)preg_match('/\b(ddt|dough temp|temperatura( de la masa)?)\b/i', $text);
    $hasPct = strpos($text, '%') !== false
        || (bool)preg_match('/\b\d+(\.\d+)?\s*(percent|por\s*ciento)\b/i', $text)
        || (bool)preg_match('/hidrataci[oó]n/i', $text);
    $hasTime = (bool)preg_match(
        '/\b\d+(\.\d+)?\s*(h|hr|hrs|hour|hours|min|mins|minute|minutes|hora|horas|minuto|minutos)\b/i',
        $text
    );
    $hasFlour = (bool)preg_match(
        '/\b(flours?|harinas?|levain|masa madre|starter|rye|centeno|trigo|wheat|spelt|espelta|integral)\b/i',
        (string)$strippedBrand
    );
    if (!$hasTemp && !$hasPct && !$hasTime && !$hasFlour) {
        $reasons[] = 'no_process_fact';
    }

    $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $secretNeedles = [
        'standing order', 'standing_orders', 'pedido permanente',
        'daily run', 'daily_orders', 'jornada diaria',
        'pack list', 'pack_list', 'lista de empaque',
        'invoice', 'factura mayorista',
        'route assignment', 'asignacion de ruta', 'asignación de ruta',
        'zone_id', 'driver list', 'lista de conductores',
        'login code', 'portal code', 'codigo de acceso', 'código de acceso',
        'use_prod_db', 'bakerysf_local', 'impersonat',
        'wholesale secret', 'secreto mayorista',
        'sfadmin', 'pack_list.php', 'daily_run.php',
    ];
    foreach ($secretNeedles as $needle) {
        if (strpos($lower, $needle) !== false) {
            $reasons[] = 'wholesale_secret';
            break;
        }
    }

    $identityNeedles = [
        "i'm a real baker", 'i am a real baker', 'soy un panadero real', 'soy panadero real',
        'not a synthetic', 'no soy sintetico', 'no soy sintético',
        'this community is all real', 'esta comunidad es real',
        'we have 100 bakers', 'somos 100 panaderos',
        'i work at sour flour', 'trabajo en sour flour',
        'as an administrator', 'como administrador',
        'posted as admin', 'publicado como admin',
        'unlabeled human', 'without a badge', 'sin etiqueta',
    ];
    foreach ($identityNeedles as $needle) {
        if (strpos($lower, $needle) !== false) {
            $reasons[] = 'unlabeled_human_claim';
            break;
        }
    }

    $origin = strtolower(trim((string)($context['origin'] ?? '')));
    if ($origin !== '' && $origin !== 'synthetic') {
        $reasons[] = 'origin_not_synthetic';
    }

    $reasons = array_values(array_unique($reasons));
    return ['ok' => $reasons === [], 'reasons' => $reasons];
}

function bakery_sfb_library_has_process_fact($text) {
    if (function_exists('bakery_sfb_synthetic_eval_process_fact')) {
        return bakery_sfb_synthetic_eval_process_fact($text);
    }
    $eval = bakery_sfb_eval_synthetic_text((string)$text, ['origin' => 'synthetic']);
    return !in_array('no_process_fact', $eval['reasons'], true) && !in_array('empty', $eval['reasons'], true);
}

/**
 * Synthetics cannot publish unlabeled or fact-free community text via the GUI.
 * Humans are not blocked; the compose hint covers them.
 */
function bakery_sfb_guard_synthetic_community_text(PDO $db, $customerId, $title, $body) {
    $customerId = (int)$customerId;
    if ($customerId <= 0 || !bakery_sfb_is_synthetic(bakery_sfb_require_community_baker($db, $customerId))) {
        return;
    }
    $title = trim((string)$title);
    $body = trim((string)$body);
    if (function_exists('bakery_sfb_synthetic_eval_assert_post')) {
        bakery_sfb_synthetic_eval_assert_post([
            'title' => $title,
            'body' => $body,
            'origin' => 'synthetic',
            'author_kind' => 'baker',
        ]);
        return;
    }
    $eval = bakery_sfb_eval_synthetic_text(trim($title . "\n" . $body), ['origin' => 'synthetic']);
    if (!$eval['ok']) {
        throw new InvalidArgumentException('Synthetic post rejected: ' . implode(', ', $eval['reasons']));
    }
}

/**
 * @return array{slug:string,category:string,title:string,body:string,piece:array}|null
 */
function bakery_sfb_library_compose_prefill($slug, $batchId = 0) {
    $piece = bakery_sfb_library_piece($slug);
    if (!$piece) {
        return null;
    }
    $category = $piece['category'];
    if ($category === 'failures' && (int)$batchId <= 0) {
        $category = 'general';
    }
    $title = function_exists('bakery_t') ? bakery_t($piece['title_key']) : $piece['title_key'];
    if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, 160);
    } else {
        $title = substr($title, 0, 160);
    }
    $action = function_exists('bakery_t') ? bakery_t($piece['action_key']) : $piece['action_key'];
    $observed = function_exists('bakery_t') ? bakery_t('sfb.library_compose_observed') : '';
    return [
        'slug' => $piece['slug'],
        'category' => $category,
        'title' => $title,
        'body' => trim($action . "\n\n" . $observed),
        'piece' => $piece,
    ];
}

/** One-tap compose URL. Failures circle only when a bake card is attached. */
function bakery_sfb_library_ask_url($slug, $batchId = 0) {
    $prefill = bakery_sfb_library_compose_prefill($slug, $batchId);
    if (!$prefill) {
        return 'sfb_community.php#start-discussion';
    }
    $overrides = [
        'library' => $prefill['slug'],
        'category' => $prefill['category'],
        'compose' => '1',
        'hash' => 'start-discussion',
    ];
    if ((int)$batchId > 0) {
        $overrides['batch'] = (int)$batchId;
    }
    if (function_exists('bakery_sfb_community_feed_url')) {
        return bakery_sfb_community_feed_url($overrides);
    }
    $params = [
        'library' => $prefill['slug'],
        'category' => $prefill['category'],
        'compose' => '1',
    ];
    if ((int)$batchId > 0) {
        $params['batch'] = (int)$batchId;
    }
    return 'sfb_community.php?' . http_build_query($params) . '#start-discussion';
}

function bakery_sfb_library_review_slugs() {
    return ['fermentation', 'formula', 'starter', 'strength', 'bake', 'sharing'];
}

function bakery_sfb_library_diagnose_common_slugs() {
    return [
        'gummy_crumb',
        'dense_loaf',
        'overproof',
        'underproof',
        'no_spring',
        'ear',
        'acetone_smell',
        'weekend_cold_proof',
    ];
}

/**
 * Honest suggestions from missing process facts — not a fake diagnosis.
 *
 * @param array $batch
 * @param array $turns
 * @param array $temps
 * @param array $formulaLines
 * @return array<int,string>
 */
function bakery_sfb_library_diagnose_suggestions(array $batch, array $turns = [], array $temps = [], array $formulaLines = []) {
    $slugs = [];
    if (!$temps) {
        $slugs[] = 'dough_temp';
    }
    if (!$turns) {
        $slugs[] = 'strength';
    }
    $hay = strtolower((string)($batch['formula_name'] ?? '') . ' ' . (string)($batch['name'] ?? ''));
    foreach ($formulaLines as $line) {
        $hay .= ' ' . strtolower((string)($line['line_name'] ?? $line['name'] ?? ''));
    }
    if (preg_match('/rye|centeno|whole\s*wheat|integral|whole\s*grain|ww\b/', $hay)) {
        $slugs[] = 'flour_swaps';
        $slugs[] = 'whole_grain';
    }
    if (($batch['status'] ?? '') === 'completed') {
        $slugs[] = 'slice_too_soon';
        $slugs[] = 'gummy_crumb';
    }
    $out = [];
    foreach ($slugs as $slug) {
        if (bakery_sfb_library_piece($slug) && !in_array($slug, $out, true)) {
            $out[] = $slug;
        }
    }
    return $out;
}

/**
 * Production-safe pin ensure: one COUNT, write only when the library is short.
 */
function bakery_sfb_ensure_library_pins(PDO $db, $userId = 0) {
    if (!bakery_sfb_library_pin_schema_ready($db)) {
        return 0;
    }
    $needed = count(bakery_sfb_library_catalog());
    $have = (int)$db->query(
        "SELECT COUNT(*) FROM sfb_community_topics WHERE body LIKE 'sfb.library.slug:%'"
    )->fetchColumn();
    if ($have >= $needed) {
        return $have;
    }
    return bakery_sfb_upsert_library_pins($db, $userId);
}
