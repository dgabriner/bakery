<?php
/**
 * Curated, role-aware navigation catalogue.
 *
 * This is intentionally the single source of truth for the current workspace
 * navigation and the module guide.  The previous full navigation is preserved
 * separately in nav_historical.php and exposed to administrators through the
 * Historical Navigation page.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_navigation_catalog() {
    return [
        [
            'key' => 'workday',
            'label' => 'Workday',
            'description' => 'The at-a-glance starting point for today\'s operation.',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'index.php', 'label' => 'Operations Dashboard', 'description' => 'Today\'s order, production, and delivery snapshot.', 'roles' => ['administrator', 'manager']],
            ],
        ],
        [
            'key' => 'production',
            'label' => 'Production',
            'description' => 'Plan what to make, prepare the bake, and reconcile finished goods.',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'production_center.php', 'label' => 'Production Center', 'description' => 'Weekly finished-goods planning using orders and stock.', 'roles' => ['administrator', 'manager']],
                ['href' => 'production.php', 'label' => 'Daily Production', 'description' => 'The bake schedule and daily production quantities.', 'roles' => ['administrator', 'manager', 'baker']],
                ['href' => 'pack_list.php', 'label' => 'Pack List', 'description' => 'Packing checklist grouped for the selected production day.', 'roles' => ['administrator', 'manager', 'baker']],
                ['href' => 'inventory.php', 'label' => 'Finished Goods', 'description' => 'Available finished goods for a delivery day.', 'roles' => ['administrator', 'manager']],
                ['href' => 'driver_load.php', 'label' => 'Driver Pickup Loads', 'description' => 'Load quantities for each driver before departure.', 'roles' => ['administrator', 'manager']],
            ],
        ],
        [
            'key' => 'orders',
            'label' => 'Orders & Customers',
            'description' => 'Maintain the demand that drives production and delivery.',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'daily_orders.php', 'label' => 'Daily Orders', 'description' => 'Review and adjust the active day\'s customer orders.', 'roles' => ['administrator', 'manager']],
                ['href' => 'standing_orders_manager.php', 'label' => 'Standing Orders', 'description' => 'Manage recurring orders by customer and delivery day.', 'roles' => ['administrator', 'manager']],
                ['href' => 'customers.php', 'label' => 'Customers', 'description' => 'Customer records, contact information, and ordering details.', 'roles' => ['administrator', 'manager']],
                ['href' => 'customer_schedule.php', 'label' => 'Customer Schedule', 'description' => 'Delivery schedules by customer and zone.', 'roles' => ['administrator', 'manager']],
                ['href' => 'zones.php', 'label' => 'Delivery Zones', 'description' => 'Create and maintain delivery zones.', 'roles' => ['administrator', 'manager']],
                ['href' => 'pan_dulce_pricing.php', 'label' => 'Pan Dulce Pricing', 'description' => 'Zone-specific Pan Dulce pricing.', 'roles' => ['administrator', 'manager']],
                ['href' => 'pan_dulce_quantities.php', 'label' => 'Pan Dulce Standards', 'description' => 'Default Pan Dulce quantities by customer or route.', 'roles' => ['administrator', 'manager']],
                ['href' => 'invoice_center.php', 'label' => 'Invoice Center', 'description' => 'Review and generate delivery invoices.', 'roles' => ['administrator', 'manager']],
                ['href' => 'leads.php', 'label' => 'Sales Leads', 'description' => 'Track prospective customers and follow-up.', 'roles' => ['administrator', 'manager']],
            ],
        ],
        [
            'key' => 'delivery',
            'label' => 'Delivery',
            'description' => 'Assign, organize, and supervise routes.',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'driver_assignment.php', 'label' => 'Driver Assignment', 'description' => 'Assign delivery work to drivers for a selected date.', 'roles' => ['administrator', 'manager']],
                ['href' => 'daily_route.php', 'label' => 'Daily Route', 'description' => 'See the daily route plan by day, month, or list.', 'roles' => ['administrator', 'manager']],
                ['href' => 'drivers.php', 'label' => 'Driver Management', 'description' => 'Maintain driver records and their recurring routes.', 'roles' => ['administrator', 'manager']],
                ['href' => 'standing_routes.php', 'label' => 'Standing Routes', 'description' => 'Maintain the recurring customer-to-driver route plan.', 'roles' => ['administrator', 'manager']],
                ['href' => 'route_manager.php', 'label' => 'Route Manager', 'description' => 'Manage and review assigned route stops.', 'roles' => ['administrator', 'manager']],
                ['href' => 'map.php', 'label' => 'Customer Map', 'description' => 'Map customer locations and delivery zones.', 'roles' => ['administrator', 'manager']],
            ],
        ],
        [
            'key' => 'catalog',
            'label' => 'Products & Recipes',
            'description' => 'Keep the products and formulas used by production accurate.',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'products.php', 'label' => 'Products', 'description' => 'Manage the products that can be ordered and produced.', 'roles' => ['administrator', 'manager']],
                ['href' => 'dough_types.php', 'label' => 'Dough Types & Lines', 'description' => 'Organize dough types and product lines.', 'roles' => ['administrator', 'manager']],
                ['href' => 'formulas.php', 'label' => 'Formulas', 'description' => 'Maintain dough formulas and recipe ratios.', 'roles' => ['administrator', 'manager']],
                ['href' => 'ingredients.php', 'label' => 'Ingredients', 'description' => 'Maintain the raw ingredient catalogue.', 'roles' => ['administrator', 'manager']],
            ],
        ],
        [
            'key' => 'insights',
            'label' => 'Insights',
            'description' => 'Read-only views that help plan and troubleshoot operations.',
            'roles' => ['administrator', 'manager'],
            'items' => [
                ['href' => 'customer_overview.php', 'label' => 'Customer Overview', 'description' => 'Summarize customers and delivery work by zone.', 'roles' => ['administrator', 'manager']],
                ['href' => 'customer_routes.php', 'label' => 'Customer Routes', 'description' => 'Look up a customer\'s route and delivery information.', 'roles' => ['administrator', 'manager']],
                ['href' => 'product_distribution.php', 'label' => 'Product Distribution', 'description' => 'Explore expected product quantities across customers and days.', 'roles' => ['administrator', 'manager']],
                ['href' => 'module_guide.php', 'label' => 'Module Guide', 'description' => 'A role and module reference for the current workspace.', 'roles' => ['administrator', 'manager']],
            ],
        ],
        [
            'key' => 'administration',
            'label' => 'Administration',
            'description' => 'Administrator-only access, identity, and retained legacy tools.',
            'roles' => ['administrator'],
            'items' => [
                ['href' => 'closeout_radar.php', 'label' => 'Closeout Radar', 'description' => 'What will bite us today or on the next bake, with a link to fix each item.', 'roles' => ['administrator']],
                ['href' => 'users.php', 'label' => 'User Management', 'description' => 'Manage staff identities, roles, and sign-in codes.', 'roles' => ['administrator']],
                ['href' => 'historical_navigation.php', 'label' => 'Historical Navigation', 'description' => 'The prior full menu and retained legacy entry points.', 'roles' => ['administrator']],
            ],
        ],
    ];
}

function bakery_navigation_item_available(array $item, $role) {
    return in_array((string)$role, $item['roles'] ?? [], true);
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
            $groups[] = $group;
        }
    }
    return $groups;
}

function bakery_navigation_role_label($role) {
    $labels = [
        'administrator' => 'Administrator',
        'manager' => 'Manager',
        'baker' => 'Baker',
        'driver' => 'Driver',
    ];
    return $labels[$role] ?? 'Staff';
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
            ['orders.php', 'Orders'],
        ],
        'Production' => [
            ['production.php', 'Production'],
            ['production_center.php', 'Production Center'],
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
            ['map.php', 'Map'],
            ['route_tester.php', 'Route Tester'],
            ['call_headquarters.php', 'Call Headquarters'],
        ],
    ];
}
