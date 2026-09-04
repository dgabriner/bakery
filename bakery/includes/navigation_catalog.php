<?php
/**
 * Curated, role-aware navigation catalogue.
 *
 * This is intentionally the single source of truth for the current workspace
 * navigation and the module guide.  The previous full navigation is preserved
 * separately in nav_historical.php and exposed to administrators through the
 * Historical Navigation page.
 *
 * Item `usage` signals how often an Admin/Manager typically opens the screen:
 *   everyday   — most operating days
 *   moderate   — weekly / when demand, staffing, or catalog shifts
 *   occasional — setup, rare review, or administrator tooling
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_navigation_usage_levels() {
    return ['everyday', 'moderate', 'occasional'];
}

function bakery_navigation_normalize_usage($usage) {
    $usage = (string)$usage;
    return in_array($usage, bakery_navigation_usage_levels(), true) ? $usage : 'moderate';
}

function bakery_navigation_usage_label($usage) {
    $usage = bakery_navigation_normalize_usage($usage);
    if (function_exists('bakery_t')) {
        return bakery_t('nav.usage.' . $usage);
    }
    $labels = [
        'everyday' => 'Everyday',
        'moderate' => 'Moderate',
        'occasional' => 'Occasional',
    ];
    return $labels[$usage];
}

function bakery_navigation_usage_description($usage) {
    $usage = bakery_navigation_normalize_usage($usage);
    if (function_exists('bakery_t')) {
        return bakery_t('nav.usage_desc.' . $usage);
    }
    $descriptions = [
        'everyday' => 'Open most operating days.',
        'moderate' => 'Weekly or when plans change.',
        'occasional' => 'Setup, rare review, or admin tools.',
    ];
    return $descriptions[$usage];
}

function bakery_navigation_catalog() {
    return [
        [
            'key' => 'workday',
            'label' => 'Workday',
            'description' => 'The at-a-glance starting point for today\'s operation.',
            'tier' => 'primary',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'manager.php', 'label' => 'Bakery Manager', 'description' => 'Command workspace for production, packing, baker activity, routes, and closeout risk.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'daily_run.php', 'label' => 'Daily Run', 'description' => 'Step-by-step operating checklist and end-of-day closeout for the selected date.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'daily_brief.php', 'label' => 'Daily Brief', 'description' => 'One-page shift handoff: changes, production, routes, and exceptions.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'text_comms.php', 'label' => 'Text Command Center', 'description' => 'See every customer, test, and general text: conversations, activity, delivery, and send from one command center.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'text_comms.php?view=surveys', 'nav_key' => 'survey_center', 'label' => 'Survey Center', 'description' => 'Lock stores and set order for tomorrow — Manager HQ plus each driver.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'index.php', 'label' => 'Operations Dashboard', 'description' => 'Today\'s order, production, and delivery snapshot.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'cashier_shop_photos.php', 'label' => 'Shop Photos', 'description' => 'Capture window display and tray photos for the selected day.', 'roles' => ['cashier', 'administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'cashier_add_product.php', 'nav_key' => 'cashier_add_product', 'label' => 'Add Product', 'description' => 'Quickly add a bakery or store item and take its photo.', 'roles' => ['cashier', 'administrator', 'manager'], 'usage' => 'moderate'],
            ],
        ],
        [
            'key' => 'production',
            'label' => 'Production',
            'description' => 'Plan what to make, prepare the bake, and reconcile finished goods.',
            'tier' => 'primary',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'production_manager.php', 'label' => 'Production Manager', 'description' => 'Sense board: dough batches, week order volume, route plan vs actual, and demand vs supply for one delivery day.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'production_center.php', 'label' => 'Production Center', 'description' => 'Production Manager hub: plan one delivery day, assign short bakes to stores, commit for bakers.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'product_manager_plan.php', 'label' => 'Product Manager Plan', 'description' => 'Standards, standing, cover-window demand, and finished goods for the bake horizon.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'ingredient_requirements.php', 'label' => 'Ingredient Planner', 'description' => 'Material requirements from the production plan through formulas to stock and purchase hints.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'baker_mix.php', 'label' => 'Mix Today', 'description' => 'Simple baker view of starter feedings and dough batches to mix.', 'roles' => ['administrator', 'manager', 'baker'], 'usage' => 'everyday'],
                ['href' => 'production.php', 'label' => 'Daily Production', 'description' => 'The bake schedule and daily production quantities.', 'roles' => ['administrator', 'manager', 'baker'], 'usage' => 'everyday'],
                ['href' => 'pack_list.php', 'label' => 'Pack List', 'description' => 'Packing checklist grouped for the selected production day.', 'roles' => ['administrator', 'manager', 'baker'], 'usage' => 'everyday'],
                ['href' => 'inventory.php', 'label' => 'Finished Goods', 'description' => 'Available finished goods for a delivery day.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'driver_load.php', 'label' => 'Driver Pickup Loads', 'description' => 'Load quantities for each driver before departure.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
            ],
        ],
        [
            'key' => 'orders',
            'label' => 'Orders & Customers',
            'description' => 'Maintain the demand that drives production and delivery.',
            'tier' => 'primary',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'daily_orders.php', 'label' => 'Daily Orders', 'description' => 'Compare standing forecast with dated orders and adjust the selected day.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'standing_orders_manager.php', 'label' => 'Standing Orders', 'description' => 'Manage recurring orders by customer and delivery day.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'customers.php', 'label' => 'Customers', 'description' => 'Customer records, contact information, and ordering details.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'customer_record.php', 'label' => 'Customer Hub', 'description' => 'Find one customer and jump to their orders, standing pattern, pricing, billing, and issues.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'billing_center.php', 'label' => 'Billing Center', 'description' => 'Customer accounts, delivery invoices, Square pay links, statements, and accounting export. Not driver COD cash.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'customer_schedule.php', 'label' => 'Customer Schedule', 'description' => 'Delivery schedules by customer and zone.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'zones.php', 'label' => 'Delivery Zones', 'description' => 'Create and maintain delivery zones.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'pan_dulce_pricing.php', 'label' => 'Pan Dulce Pricing', 'description' => 'Zone-specific Pan Dulce pricing.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'customer_pricing.php', 'label' => 'Customer Custom Pricing', 'description' => 'Per-product prices for customers on the custom tier.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'pan_dulce_quantities.php', 'label' => 'Pan Dulce Standards', 'description' => 'Default Pan Dulce quantities by customer or route.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'service_issues.php', 'label' => 'Service Issues', 'description' => 'Customer-reported delivery problems — quantity disputes, damage, and billing questions.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'operational_timeline.php', 'label' => 'Operational Timeline', 'description' => 'Who did what — deliveries, production, loads, and order changes for a day or customer.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'leads.php', 'label' => 'Sales Leads', 'description' => 'Track prospective customers and follow-up.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
            ],
        ],
        [
            'key' => 'delivery',
            'label' => 'Delivery',
            'description' => 'Assign, organize, and supervise routes.',
            'tier' => 'primary',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'driver.php?change_driver=1', 'label' => 'My Route', 'description' => 'Choose a driver identity and work that driver\'s delivery route.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'driver_assignment.php', 'label' => 'Driver Assignment', 'description' => 'Assign delivery work to drivers for a selected date.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'text_comms.php?view=surveys', 'nav_key' => 'survey_center', 'label' => 'Survey Center', 'description' => 'Lock stores and set order for tomorrow — Manager HQ plus each driver.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'daily_route.php', 'label' => 'Daily Route', 'description' => 'See the daily route plan by day, month, or list.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'drivers.php', 'label' => 'Driver Management', 'description' => 'Maintain driver records and their recurring routes.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'standing_routes.php', 'label' => 'Standing Routes', 'description' => 'Maintain the recurring customer-to-driver route plan.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'route_manager.php', 'label' => 'Route Manager', 'description' => 'Review route stops and track COD cash on hand and turn-in totals per driver.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'route_summary.php', 'label' => 'Route Summary', 'description' => 'See the day’s deliveries as photos, with customer, amount sold, and driver on each stop.', 'roles' => ['administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'route_closeout.php', 'label' => 'Route Closeout', 'description' => 'End-of-route loaded vs delivered vs returned vs waste; required before Daily Run closeout.', 'roles' => ['administrator', 'manager', 'driver'], 'usage' => 'everyday'],
                ['href' => 'route_analysis.php', 'label' => 'Route Analysis', 'description' => 'Compare planned and actual timing for a driver route, and update default stop times when needed.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'map.php', 'label' => 'Customer Map', 'description' => 'Map customer locations and delivery zones.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
            ],
        ],
        [
            'key' => 'catalog',
            'label' => 'Products & Recipes',
            'description' => 'Keep the products and formulas used by production accurate.',
            'tier' => 'extras',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'products.php', 'label' => 'Products', 'description' => 'Manage the products that can be ordered and produced.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'product_photos.php', 'label' => 'Product Photos', 'description' => 'Capture and manage catalog images with a primary photo per product.', 'roles' => ['cashier', 'administrator', 'manager'], 'usage' => 'everyday'],
                ['href' => 'dough_types.php', 'label' => 'Dough Types & Lines', 'description' => 'Organize dough types and product lines.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'formulas.php', 'label' => 'Formulas', 'description' => 'Maintain dough formulas and recipe ratios.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
                ['href' => 'ingredients.php', 'label' => 'Ingredients', 'description' => 'Maintain the raw ingredient catalogue.', 'roles' => ['administrator', 'manager'], 'usage' => 'moderate'],
            ],
        ],
        [
            'key' => 'insights',
            'label' => 'Insights',
            'description' => 'Read-only views that help plan and troubleshoot operations.',
            'tier' => 'extras',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'customer_overview.php', 'label' => 'Customer Overview', 'description' => 'Summarize customers and delivery work by zone.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'customer_routes.php', 'label' => 'Customer Routes', 'description' => 'Look up a customer\'s route and delivery information.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'product_distribution.php', 'label' => 'Product Distribution', 'description' => 'Explore expected product quantities across customers and days.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'module_guide.php', 'label' => 'Module Guide', 'description' => 'A role and module reference for the current workspace.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
                ['href' => 'walkthroughs.php', 'label' => 'Walkthroughs', 'description' => 'English and Spanish usage videos recorded on the local production-data mirror.', 'roles' => ['administrator', 'manager'], 'usage' => 'occasional'],
            ],
        ],
        [
            'key' => 'administration',
            'label' => 'Administration',
            'description' => 'Administrator-only access, identity, and retained legacy tools.',
            'tier' => 'extras',
            'roles' => ['administrator'],
            'items' => [
                ['href' => 'sfb_admin_overview.php', 'label' => 'SF Baker Engagement', 'description' => 'Review SF Baker batches, respond to questions, and leave coaching notes.', 'roles' => ['administrator'], 'usage' => 'occasional'],
                ['href' => 'sfb_admin_studio.php', 'label' => 'Synthetic Manager', 'description' => 'Pace the synthetic baker clock, read the action log, and inspect a baker.', 'roles' => ['administrator'], 'usage' => 'occasional'],
                ['href' => 'agent_homebase.php', 'label' => 'Agent Homebase', 'description' => 'Learning studio, whiteboard, bug watchlist, and agent session log for Cursor missions.', 'roles' => ['administrator'], 'usage' => 'occasional'],
                ['href' => 'users.php', 'label' => 'User Management', 'description' => 'Manage staff identities, roles, and sign-in codes.', 'roles' => ['administrator'], 'usage' => 'occasional'],
                ['href' => 'login_history.php', 'label' => 'Login History', 'description' => 'Who is on the floor, which screens they use, and whether today looks unusual.', 'roles' => ['administrator'], 'usage' => 'occasional'],
                ['href' => 'qr_login.php', 'label' => 'Customer QR Login', 'description' => 'Generate a secure portal login QR code for any customer.', 'roles' => ['administrator'], 'usage' => 'occasional'],
                ['href' => 'historical_navigation.php', 'label' => 'Historical Navigation', 'description' => 'The prior full menu and retained legacy entry points.', 'roles' => ['administrator'], 'usage' => 'occasional'],
            ],
        ],
    ];
}

function bakery_navigation_item_available(array $item, $role) {
    return in_array((string)$role, $item['roles'] ?? [], true);
}

function bakery_navigation_item_page_key(array $item) {
    if (!empty($item['nav_key'])) {
        return (string)$item['nav_key'];
    }
    $href = (string)($item['href'] ?? '');
    $path = parse_url($href, PHP_URL_PATH);
    $base = basename((string)$path, '.php');
    return $base !== '' ? $base : 'index';
}

function bakery_navigation_translate_group(array $group) {
    $key = (string)($group['key'] ?? '');
    if ($key !== '' && function_exists('bakery_t')) {
        $group['label'] = bakery_t('nav.group.' . $key);
        $group['description'] = bakery_t('nav.group_desc.' . $key);
    }
    foreach ($group['items'] as &$item) {
        $pageKey = bakery_navigation_item_page_key($item);
        if (function_exists('bakery_t')) {
            $item['label'] = bakery_t('nav.item.' . $pageKey);
            $item['description'] = bakery_t('nav.item_desc.' . $pageKey);
        }
        $item['usage'] = bakery_navigation_normalize_usage($item['usage'] ?? 'moderate');
    }
    unset($item);
    return $group;
}

function bakery_navigation_groups_for_role($role) {
    $groups = [];
    foreach (bakery_navigation_catalog() as $group) {
        if (!bakery_navigation_item_available($group, $role)) {
            continue;
        }
        $items = array_values(array_filter($group['items'], function ($item) use ($role) {
            return bakery_navigation_item_available($item, $role);
        }));
        if ($items) {
            $group['items'] = $items;
            $groups[] = bakery_navigation_translate_group($group);
        }
    }
    return $groups;
}

/**
 * Present the same role-filtered groups in two attention tiers for the
 * operations drawer. The groups remain individually addressable so the
 * complete workspace stays available without putting every module in the
 * primary path.
 */
function bakery_navigation_sections_for_role($role) {
    $sectionDefinitions = [
        [
            'key' => 'primary',
            'label' => 'Primary work',
            'description' => 'The work that keeps today moving.',
            'tier' => 'primary',
        ],
        [
            'key' => 'extras',
            'label' => 'Extras & setup',
            'description' => 'Power tools for planning, setup, and administration.',
            'tier' => 'extras',
        ],
    ];

    $groups = bakery_navigation_groups_for_role($role);
    $sections = [];
    foreach ($sectionDefinitions as $section) {
        $section['groups'] = array_values(array_filter($groups, function ($group) use ($section) {
            return ($group['tier'] ?? 'extras') === $section['tier'];
        }));
        if (!$section['groups']) {
            continue;
        }
        if (function_exists('bakery_t')) {
            $section['label'] = bakery_t('nav.section.' . $section['key']);
            $section['description'] = bakery_t('nav.section_desc.' . $section['key']);
        }
        $sections[] = $section;
    }
    return $sections;
}

function bakery_navigation_role_label($role) {
    if (function_exists('bakery_t')) {
        $key = 'role.' . (string)$role;
        $translated = bakery_t($key);
        if ($translated !== $key) {
            return $translated;
        }
    }
    $labels = [
        'administrator' => 'Administrator',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
        'baker' => 'Baker',
        'driver' => 'Driver',
        'driver_assistant' => 'Driver Assistant',
    ];
    return $labels[$role] ?? (function_exists('bakery_t') ? bakery_t('role.staff') : 'Staff');
}

/** The exact group arrangement from the navigation superseded in August 2026. */
function bakery_historical_navigation_catalog() {
    return [
        'Dashboard' => [
            ['index.php', 'Database Overview'],
        ],
        'Product Management' => [
            ['products.php', 'Products'],
            ['dough_types.php', 'Dough Types'],
            ['formulas.php', 'Formulas'],
            ['ingredients.php', 'Ingredients'],
        ],
        'Customer Management' => [
            ['customers.php', 'Customers'],
            ['customer_schedule.php', 'Schedule'],
            ['customer_routes.php', 'View by Customer'],
            ['customer_overview.php', 'Overview'],
            ['zones.php', 'Zones'],
            ['pan_dulce_pricing.php', 'Pan Dulce Pricing'],
            ['pan_dulce_quantities.php', 'Pan Dulce Quantities'],
            ['leads.php', 'Leads'],
        ],
        'Orders' => [
            ['daily_orders.php', 'Daily Orders'],
            ['standing_orders.php', 'Standing Orders'],
            ['standing_orders_manager.php', 'Standing Orders Manager'],
            ['bread_distribution.php', 'Bread Distribution'],
            ['product_distribution.php', 'Product Distribution'],
            ['invoice_center.php', 'Invoice Center'],
            ['generate_invoice.php', 'Legacy invoice generator (retired)'],
            ['generate_invoice_simple.php', 'Legacy simple invoice (retired)'],
            ['simple_invoice.php', 'Legacy printable invoice (retired)'],
            ['orders.php', 'Orders'],
        ],
        'Production' => [
            ['production.php', 'Production'],
            ['production_manager.php', 'Production Manager'],
            ['production_center.php', 'Production Center'],
            ['product_manager_plan.php', 'Product Manager Plan'],
            ['pack_list.php', 'Pack List'],
            ['inventory.php', 'Finished Goods Inventory'],
            ['driver_load.php', 'Driver Pickup Loads'],
        ],
        'Routes & Delivery' => [
            ['standing_routes.php', 'Standing Routes'],
            ['customer_routes.php', 'View by Customer'],
            ['drivers.php', 'Driver Management'],
            ['users.php', 'User Management'],
            ['daily_route.php', 'Daily Route'],
            ['driver_assignment.php', 'Driver Assignment'],
            ['driver.php', 'Driver Route'],
            ['driver_overview.php', 'Driver Overview'],
            ['driver_list.php', 'Driver Route List'],
            ['route_manager.php', 'Route Manager'],
            ['route_summary.php', 'Route Summary'],
            ['route_closeout.php', 'Route Closeout'],
            ['map.php', 'Map'],
            ['call_headquarters.php', 'Call Headquarters'],
        ],
    ];
}

/**
 * Extra script→roles registrations for JSON/handlers not shown in the menu.
 * Catalog item roles still win for menu pages; this is the escape hatch.
 *
 * @return array<string, list<string>>
 */
function &bakery_navigation_script_registry(): array
{
    static $registry = null;
    if ($registry === null) {
        $registry = [];
        $boot = static function (array &$registry, string $script, array $roles): void {
            $script = basename(strtolower(trim($script)));
            if ($script === '' || !str_ends_with($script, '.php')) {
                return;
            }
            $existing = $registry[$script] ?? [];
            foreach ($roles as $role) {
                $role = strtolower(trim((string)$role));
                if ($role !== '' && !in_array($role, $existing, true)) {
                    $existing[] = $role;
                }
            }
            $registry[$script] = $existing;
        };
        // Bootstrap: preserve today's non-catalog allowlists under default-deny.
        $driverRoles = ['driver', 'driver_assistant', 'administrator', 'manager'];
        foreach ([
            'index.php', 'driver.php', 'driver_stops.php', 'pack_list.php', 'driver_list.php',
            'route_closeout.php', 'complete_delivery.php', 'driver_session_ping.php',
            'upload_driver_photo.php', 'get_customer_order_details.php', 'get_driver_orders.php',
            'global_gps_handler.php', 'call_headquarters.php', 'qr_login.php',
        ] as $script) {
            $boot($registry, $script, $driverRoles);
        }
        $cashierRoles = ['cashier', 'administrator', 'manager'];
        foreach ([
            'cashier_shop_photos.php', 'upload_shop_photo.php', 'product_photos.php',
            'upload_product_photo.php', 'cashier_add_product.php',
        ] as $script) {
            $boot($registry, $script, $cashierRoles);
        }
        $bakerRoles = ['baker', 'administrator', 'manager'];
        foreach (['production.php', 'baker_mix.php', 'pack_list.php'] as $script) {
            $boot($registry, $script, $bakerRoles);
        }
        $managerApiRoles = ['administrator', 'manager'];
        foreach ([
            'billing_api.php', 'daily_orders_api.php', 'standing_orders_manager_api.php', 'driver_assignment_api.php', 'daily_run_api.php', 'text_comms_api.php', 'service_issues_api.php',
            'staff_alerts_api.php', 'auto_push_api.php', 'ping.php',
            'billing_export.php', 'generate_invoice.php', 'invoice_center.php',
            'text_media.php', 'ai_photo_text.php', 'customer_statement.php',
        ] as $script) {
            $boot($registry, $script, $managerApiRoles);
        }
        $boot($registry, 'baker.php', ['baker', 'administrator', 'manager']);
        $adminOnly = ['administrator'];
        foreach ([
            'standing_orders.php', 'bread_distribution.php', 'orders.php', 'driver_overview.php',
            'driver_pages_probe.php', 'trace_driver_list.php',
            'build_id.php', 'deploy_status.php', 'migration_status.php', 'schema_status.php',
            'health_deploy.php', 'health_driver.php', 'health_prod.php',
            'sfb_admin_batch.php', 'sfb_admin_impersonate.php', 'sfb_admin_learn.php',
            'sfb_admin_studio_baker.php',
        ] as $script) {
            $boot($registry, $script, $adminOnly);
        }
    }
    return $registry;
}

/**
 * Escape hatch for JSON/handlers not shown in the navigation catalog.
 *
 * @param list<string> $roles
 */
function bakery_navigation_register_script(string $script, array $roles): void
{
    $script = basename(strtolower(trim($script)));
    if ($script === '' || !str_ends_with($script, '.php')) {
        return;
    }
    $registry = &bakery_navigation_script_registry();
    $existing = $registry[$script] ?? [];
    foreach ($roles as $role) {
        $role = strtolower(trim((string)$role));
        if ($role !== '' && !in_array($role, $existing, true)) {
            $existing[] = $role;
        }
    }
    $registry[$script] = $existing;
}

/**
 * Basename of a catalog href (strips query string).
 */
function bakery_navigation_script_basename(string $href): string
{
    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $href;
    }
    return basename(strtolower($path));
}

/**
 * Roles allowed to open a script: catalog item roles ∪ registry, else empty
 * (caller treats empty as administrator-only default-deny).
 *
 * @return list<string>
 */
function bakery_navigation_roles_for_script(string $script): array
{
    $script = bakery_navigation_script_basename($script);
    $roles = [];
    foreach (bakery_navigation_catalog() as $group) {
        foreach ($group['items'] as $item) {
            if (bakery_navigation_script_basename((string)($item['href'] ?? '')) !== $script) {
                continue;
            }
            foreach (($item['roles'] ?? []) as $role) {
                $role = strtolower(trim((string)$role));
                if ($role !== '' && !in_array($role, $roles, true)) {
                    $roles[] = $role;
                }
            }
        }
    }
    foreach (bakery_navigation_script_registry()[$script] ?? [] as $role) {
        $role = strtolower(trim((string)$role));
        if ($role !== '' && !in_array($role, $roles, true)) {
            $roles[] = $role;
        }
    }
    return $roles;
}

/**
 * Allowlist of script basenames a role may open (catalog ∪ registry).
 *
 * @return list<string>
 */
function bakery_navigation_scripts_for_role(string $role): array
{
    $role = strtolower(trim($role));
    $lookupRole = $role === 'driver_assistant' ? 'driver' : $role;
    $scripts = [];
    foreach (bakery_navigation_catalog() as $group) {
        foreach ($group['items'] as $item) {
            if (!bakery_navigation_item_available($item, $lookupRole)) {
                continue;
            }
            $base = bakery_navigation_script_basename((string)($item['href'] ?? ''));
            if ($base !== '' && str_ends_with($base, '.php')) {
                $scripts[$base] = true;
            }
        }
    }
    foreach (bakery_navigation_script_registry() as $script => $roles) {
        if (in_array($lookupRole, $roles, true) || ($role === 'driver_assistant' && in_array('driver_assistant', $roles, true))) {
            $scripts[$script] = true;
        }
    }
    $out = array_keys($scripts);
    sort($out);
    return $out;
}
