<?php
/**
 * Jasmine's Staging practice map.
 * Six seats, three logins. Codes stay out of Git.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Practice identities. The manager email lands on the Sandbox.
 * Sales and Driver use the normal homes for those roles.
 *
 * @return array{jasmine: string, sales: string, driver: string}
 */
function bakery_sandbox_practice_emails(): array
{
    return [
        'jasmine' => 'jasmine@sourflour.local',
        'sales' => 'sandbox.sales@sourflour.local',
        'driver' => 'sandbox.driver@sourflour.local',
    ];
}

function bakery_sandbox_home_for_user(?array $user): ?string
{
    if (!$user) {
        return null;
    }
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === bakery_sandbox_practice_emails()['jasmine']) {
        return 'sandbox.php';
    }
    return null;
}

/**
 * Starter seats. hrefs are existing screens.
 *
 * @return list<array{key: string, seat: string, links: list<array{href: string, label_key: string}>}>
 */
function bakery_sandbox_areas(): array
{
    return [
        [
            'key' => 'driver',
            'seat' => 'driver',
            'links' => [
                ['href' => 'driver.php', 'label_key' => 'nav.item.driver'],
            ],
        ],
        [
            'key' => 'driver_manager',
            'seat' => 'jasmine',
            'links' => [
                ['href' => 'driver_assignment.php', 'label_key' => 'nav.item.driver_assignment'],
                ['href' => 'drivers.php', 'label_key' => 'nav.item.drivers'],
            ],
        ],
        [
            'key' => 'customer_orders',
            'seat' => 'jasmine',
            'links' => [
                ['href' => 'daily_orders.php', 'label_key' => 'nav.item.daily_orders'],
            ],
        ],
        [
            'key' => 'production',
            'seat' => 'jasmine',
            'links' => [
                ['href' => 'production_center.php', 'label_key' => 'nav.item.production_center'],
                ['href' => 'production_manager.php', 'label_key' => 'nav.item.production_manager'],
            ],
        ],
        [
            'key' => 'sales',
            'seat' => 'sales',
            'links' => [
                ['href' => 'sales_home.php', 'label_key' => 'page.sales_home'],
            ],
        ],
        [
            'key' => 'sales_manager',
            'seat' => 'jasmine',
            'links' => [
                ['href' => 'sales_home.php', 'label_key' => 'page.sales_home'],
                ['href' => 'leads.php', 'label_key' => 'nav.item.leads'],
            ],
        ],
    ];
}
