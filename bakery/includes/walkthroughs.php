<?php
/**
 * Published walkthrough videos for the in-app gallery.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_walkthrough_items(): array
{
    return [
        [
            'id' => 'login',
            'title_key' => 'walkthroughs.item.login.title',
            'desc_key' => 'walkthroughs.item.login.desc',
        ],
        [
            'id' => 'daily-run',
            'title_key' => 'walkthroughs.item.daily_run.title',
            'desc_key' => 'walkthroughs.item.daily_run.desc',
        ],
        [
            'id' => 'admin-route-build',
            'title_key' => 'walkthroughs.item.admin_route_build.title',
            'desc_key' => 'walkthroughs.item.admin_route_build.desc',
        ],
        [
            'id' => 'admin-route-reorder',
            'title_key' => 'walkthroughs.item.admin_route_reorder.title',
            'desc_key' => 'walkthroughs.item.admin_route_reorder.desc',
        ],
        [
            'id' => 'admin-route-verify',
            'title_key' => 'walkthroughs.item.admin_route_verify.title',
            'desc_key' => 'walkthroughs.item.admin_route_verify.desc',
        ],
        [
            'id' => 'driver-assignment',
            'title_key' => 'walkthroughs.item.driver_assignment.title',
            'desc_key' => 'walkthroughs.item.driver_assignment.desc',
        ],
        [
            'id' => 'adjust-route',
            'title_key' => 'walkthroughs.item.adjust_route.title',
            'desc_key' => 'walkthroughs.item.adjust_route.desc',
        ],
    ];
}

function bakery_driver_walkthrough_items(): array
{
    return [
        [
            'id' => 'driver-login',
            'title_key' => 'walkthroughs.driver.login.title',
            'desc_key' => 'walkthroughs.driver.login.desc',
        ],
        [
            'id' => 'driver-tomorrow',
            'title_key' => 'walkthroughs.driver.tomorrow.title',
            'desc_key' => 'walkthroughs.driver.tomorrow.desc',
        ],
        [
            'id' => 'driver-complete-stop',
            'title_key' => 'walkthroughs.driver.complete.title',
            'desc_key' => 'walkthroughs.driver.complete.desc',
        ],
        [
            'id' => 'driver-skip-stop',
            'title_key' => 'walkthroughs.driver.skip.title',
            'desc_key' => 'walkthroughs.driver.skip.desc',
        ],
        [
            'id' => 'driver-adjust-route',
            'title_key' => 'walkthroughs.driver.adjust.title',
            'desc_key' => 'walkthroughs.driver.adjust.desc',
        ],
        [
            'id' => 'driver-call-hq',
            'title_key' => 'walkthroughs.driver.call_hq.title',
            'desc_key' => 'walkthroughs.driver.call_hq.desc',
        ],
    ];
}

function bakery_walkthrough_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'walkthroughs';
}

function bakery_walkthrough_filename(string $id, string $locale): string
{
    $locale = $locale === 'es' ? 'es' : 'en';
    return $id . '-' . $locale . '.mp4';
}

function bakery_walkthrough_href(string $id, string $locale): ?string
{
    $file = bakery_walkthrough_filename($id, $locale);
    $path = bakery_walkthrough_dir() . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        return null;
    }
    return bakery_asset_href('assets/walkthroughs/' . $file);
}
