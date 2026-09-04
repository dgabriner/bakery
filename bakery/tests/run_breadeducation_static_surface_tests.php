<?php
/**
 * Offline Bread Education static-surface regression checks.
 *
 * Usage: php tests/run_breadeducation_static_surface_tests.php
 *
 * This suite intentionally reads only tracked static files. It has no database,
 * network, credentials, or deployment dependency.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$siteRoot = $root . '/breadeducation';
$origin = 'https://bakery.sourflour.org/breadeducation';
$pass = 0;
$fail = 0;

function breadeducation_static_assert(bool $condition, string $message): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $message . PHP_EOL;
    if ($condition) {
        $pass++;
        return;
    }
    $fail++;
}

/** @return list<array<string, string>> */
function breadeducation_static_tags(string $html, string $tag): array
{
    preg_match_all('~<' . preg_quote($tag, '~') . '\\b[^>]*>~is', $html, $tagMatches);
    $tags = [];
    foreach ($tagMatches[0] as $markup) {
        preg_match_all('~([A-Za-z_:][A-Za-z0-9:._-]*)\\s*=\\s*"([^"]*)"~s', $markup, $attributeMatches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($attributeMatches as $attribute) {
            $attributes[strtolower($attribute[1])] = html_entity_decode($attribute[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $tags[] = $attributes;
    }
    return $tags;
}

/** @return list<string> */
function breadeducation_static_meta_values(string $html, string $key, string $attribute): array
{
    $values = [];
    foreach (breadeducation_static_tags($html, 'meta') as $tag) {
        if (strtolower($tag[$attribute] ?? '') === strtolower($key) && isset($tag['content'])) {
            $values[] = $tag['content'];
        }
    }
    return $values;
}

/** @return list<string> */
function breadeducation_static_link_values(string $html, string $rel): array
{
    $values = [];
    foreach (breadeducation_static_tags($html, 'link') as $tag) {
        $relations = preg_split('~\\s+~', strtolower($tag['rel'] ?? '')) ?: [];
        if (in_array(strtolower($rel), $relations, true) && isset($tag['href'])) {
            $values[] = $tag['href'];
        }
    }
    return $values;
}

function breadeducation_static_title(string $html): string
{
    if (!preg_match('~<title\\b[^>]*>(.*?)</title>~is', $html, $match)) {
        return '';
    }
    return trim(preg_replace('~\\s+~', ' ', html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}

function breadeducation_static_footer_date(string $html): string
{
    if (!preg_match('~<footer\\b[^>]*>.*?<time\\b[^>]*\\bdatetime="([^"]+)"[^>]*>~is', $html, $match)) {
        return '';
    }
    return $match[1];
}

/** @return array{0:string,1:string}|null */
function breadeducation_static_resolve_local_url(string $url, string $page, string $siteRoot): ?array
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || $url[0] === '#' || preg_match('~^(?:mailto|tel|data|javascript):~i', $url)) {
        return null;
    }

    $path = preg_split('~[?#]~', $url, 2)[0];
    $isDirectoryUrl = substr($path, -1) === '/';
    if (preg_match('~^https?://~i', $path)) {
        $parts = parse_url($path);
        if (strtolower((string)($parts['host'] ?? '')) !== 'bakery.sourflour.org') {
            return null;
        }
        $path = (string)($parts['path'] ?? '');
    }

    if (strpos($path, '/breadeducation/') === 0) {
        $relative = substr($path, strlen('/breadeducation/'));
    } elseif ($path === '/breadeducation' || $path === '/breadeducation/') {
        $relative = '';
        $isDirectoryUrl = true;
    } elseif (strpos($path, '/') === 0) {
        return null;
    } else {
        $directory = str_replace('\\', '/', dirname($page));
        $relative = ($directory === '.' ? '' : $directory . '/') . $path;
    }

    $segments = [];
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments === []) {
                return ['', ''];
            }
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    $relative = implode('/', $segments);
    if ($isDirectoryUrl || $relative === '') {
        $relative = rtrim($relative, '/') . ($relative === '' ? '' : '/') . 'index.html';
    }
    return [$relative, $siteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)];
}

/** @return array<string, mixed>|null */
function breadeducation_static_webpage_schema(string $html): ?array
{
    preg_match_all('~<script\\b[^>]*\\btype="application/ld\\+json"[^>]*>(.*?)</script>~is', $html, $scripts);
    foreach ($scripts[1] as $script) {
        $decoded = json_decode(trim($script), true);
        if (!is_array($decoded)) {
            continue;
        }
        $graph = $decoded['@graph'] ?? [];
        if (!is_array($graph)) {
            continue;
        }
        foreach ($graph as $node) {
            if (is_array($node) && ($node['@type'] ?? null) === 'WebPage') {
                return $node;
            }
        }
    }
    return null;
}

$cohort = [
    'breads/baguettes.html',
    'breads/crackers-and-discard.html',
    'breads/focaccia.html',
    'breads/pizza-at-home.html',
    'breads/pretzels.html',
    'classes/classes.html',
    'classes/corporate-workshops.html',
    'classes/private-events.html',
    'classes/visit-plan.html',
    'journal/home-oven-to-market.html',
    'journal/sf-baker.html',
    'reference/baking-glossary-printable.html',
    'reference/fresh-loaf.html',
    'reference/troubleshooting.html',
    'sell/find-our-bread.html',
    'sell/wholesale.html',
    'sourdough/fermentation.html',
    'sourdough/sf-sourdough.html',
    'sourdough/sourdough.html',
    'start/first-loaf-shopping.html',
    'start/starter-day-one.html',
    'start/your-first-dutch-oven-bake.html',
    'technique/bake.html',
    'technique/cold-retard.html',
    'technique/formula.html',
    'technique/hydration-by-feel.html',
    'technique/scoring-patterns.html',
    'technique/shaping-batards.html',
    'technique/steam-without-dutch-oven.html',
    'technique/whole-grain.html',
    'technique/yeasted.html',
];
breadeducation_static_assert(count($cohort) === 31, 'migration cohort has all 31 moved pages');

$sitemapPath = $siteRoot . '/sitemap.xml';
$sitemap = is_file($sitemapPath) ? (string)file_get_contents($sitemapPath) : '';
preg_match_all('~<url>\\s*<loc>([^<]+)</loc>\\s*<lastmod>([^<]+)</lastmod>.*?</url>~s', $sitemap, $sitemapMatches, PREG_SET_ORDER);
$lastmodByUrl = [];
foreach ($sitemapMatches as $entry) {
    $url = trim(html_entity_decode($entry[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    $lastmodByUrl[$url] = trim($entry[2]);
}
breadeducation_static_assert($sitemap !== '', 'sitemap exists');
breadeducation_static_assert(count($sitemapMatches) === count($lastmodByUrl), 'sitemap has no duplicate URLs');
breadeducation_static_assert(!isset($lastmodByUrl[$origin . '/TEMPLATE.html']), 'sitemap excludes the authoring template');

$publicFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($siteRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'html') {
        continue;
    }
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($siteRoot) + 1));
    if ($relative === 'TEMPLATE.html') {
        continue;
    }
    $publicFiles[$relative === 'index.html' ? $origin . '/' : $origin . '/' . $relative] = $relative;
}
breadeducation_static_assert(
    array_diff_key($publicFiles, $lastmodByUrl) === [] && array_diff_key($lastmodByUrl, $publicFiles) === [],
    'sitemap and published HTML inventory are one-to-one'
);

foreach ($cohort as $relative) {
    $path = $siteRoot . '/' . $relative;
    $html = is_file($path) ? (string)file_get_contents($path) : '';
    $canonical = breadeducation_static_link_values($html, 'canonical');
    $expectedUrl = $origin . '/' . $relative;
    $lastmod = $lastmodByUrl[$expectedUrl] ?? '';

    breadeducation_static_assert($html !== '', $relative . ' exists');
    breadeducation_static_assert(count($canonical) === 1 && $canonical[0] === $expectedUrl, $relative . ' has its canonical URL');
    breadeducation_static_assert($lastmod !== '', $relative . ' is covered by the sitemap');

    $ogUrl = breadeducation_static_meta_values($html, 'og:url', 'property');
    $description = breadeducation_static_meta_values($html, 'description', 'name');
    $ogDescription = breadeducation_static_meta_values($html, 'og:description', 'property');
    $ogTitle = breadeducation_static_meta_values($html, 'og:title', 'property');
    breadeducation_static_assert(count($ogUrl) === 1 && $ogUrl[0] === ($canonical[0] ?? ''), $relative . ' canonical and og:url agree');
    breadeducation_static_assert(
        count($description) === 1 && count($ogDescription) === 1 && $description[0] === $ogDescription[0],
        $relative . ' description and og:description agree'
    );
    breadeducation_static_assert(
        count($ogTitle) === 1 && breadeducation_static_title($html) === ($ogTitle[0] ?? ''),
        $relative . ' title and og:title agree'
    );

    $modified = breadeducation_static_meta_values($html, 'article:modified_time', 'property');
    breadeducation_static_assert(count($modified) === 1 && $modified[0] === $lastmod, $relative . ' article modified date matches sitemap');
    breadeducation_static_assert(breadeducation_static_footer_date($html) === $lastmod, $relative . ' footer date matches sitemap');

    $webPage = breadeducation_static_webpage_schema($html);
    breadeducation_static_assert(
        is_array($webPage) && ($webPage['url'] ?? '') === ($canonical[0] ?? '') && ($webPage['dateModified'] ?? '') === $lastmod,
        $relative . ' WebPage schema matches canonical and sitemap'
    );

    $styles = breadeducation_static_link_values($html, 'stylesheet');
    $logo = '';
    foreach (breadeducation_static_tags($html, 'img') as $image) {
        if (preg_match('~(?:^|\\s)logo-header(?:\\s|$)~', $image['class'] ?? '')) {
            $logo = $image['src'] ?? '';
            break;
        }
    }
    breadeducation_static_assert(
        count($styles) === 1 && $styles[0] === '/breadeducation/assets/education-pages.css',
        $relative . ' uses the root-relative stylesheet'
    );
    breadeducation_static_assert(
        $logo === '/breadeducation/assets/logos/sour-flour-full.png',
        $relative . ' uses the root-relative header logo'
    );

    $linkFailures = [];
    preg_match_all('~\\b(?:href|src)="([^"]+)"~i', $html, $urlMatches);
    foreach ($urlMatches[1] as $url) {
        $resolved = breadeducation_static_resolve_local_url($url, $relative, $siteRoot);
        if ($resolved === null) {
            continue;
        }
        [$resolvedRelative, $resolvedPath] = $resolved;
        if ($resolvedRelative === '' || $resolvedPath === '') {
            $linkFailures[] = 'escapes the site root: ' . $url;
            continue;
        }
        if (preg_match('~\\.md$~i', $resolvedRelative)) {
            $linkFailures[] = 'points at unshipped Markdown: ' . $url;
            continue;
        }
        if (!is_file($resolvedPath)) {
            $linkFailures[] = 'is missing: ' . $url;
        }
    }
    breadeducation_static_assert(
        $linkFailures === [],
        $relative . ' local href/src targets resolve' . ($linkFailures === [] ? '' : ': ' . implode('; ', $linkFailures))
    );
}

$partnerUrl = 'https://victoriasf.com/';
$partnerPages = [
    'index.html' => 'partner-panel',
    'classes/classes.html' => 'hosted at our Mission District home inside',
    'classes/corporate-workshops.html' => 'historic La Victoria SF',
    'sell/wholesale.html' => 'baked overnight inside',
    'sell/find-our-bread.html' => 'inside <a href="https://victoriasf.com/"',
    'pan-dulce/index.html' => 'Calibrate against the real thing at',
    'es/index.html' => 'dentro de <a href="https://victoriasf.com/"',
];
foreach ($partnerPages as $relative => $requiredText) {
    $html = (string)file_get_contents($siteRoot . '/' . $relative);
    breadeducation_static_assert(
        strpos($html, $partnerUrl) !== false && strpos($html, $requiredText) !== false,
        $relative . ' points visitors to La Victoria SF'
    );
}
$homeHtml = (string)file_get_contents($siteRoot . '/index.html');
$classesHtml = (string)file_get_contents($siteRoot . '/classes/classes.html');
breadeducation_static_assert(strpos($classesHtml, '┬╖') === false, 'classes page has no mojibake separators');
breadeducation_static_assert(strpos($classesHtml, 'Classes taught by people who bake every morning.') === false, 'classes page does not overclaim daily teachers');
breadeducation_static_assert(strpos($classesHtml, 'bake with people who bake every day') === false, 'classes metadata does not overclaim daily teachers');
breadeducation_static_assert(strpos($classesHtml, 'Classes taught at the bench, from a working bakery.') !== false, 'classes page uses grounded teaching copy');
breadeducation_static_assert(strpos($homeHtml, 'bakers who bake every morning') === false, 'homepage does not overclaim daily class teachers');
breadeducation_static_assert(strpos($homeHtml, 'Hands-on workshops taught at the bench') !== false, 'homepage uses grounded workshop copy');
breadeducation_static_assert(
    substr_count($homeHtml, $partnerUrl) >= 5,
    'learning hub offers La Victoria SF in navigation, feature, card, and footer'
);
$llms = (string)file_get_contents($root . '/domain_root/llms.txt');
breadeducation_static_assert(
    strpos($llms, '[La Victoria SF](' . $partnerUrl . ')') !== false,
    'AI-readable site map names La Victoria SF as a partner'
);

$ownerPhotoAssets = [
    'assets/images/sour-flour-workshop-table.avif',
    'assets/images/sour-flour-workshop-stretch.webp',
    'assets/images/sour-flour-country-loaves.jpg',
    'assets/images/sour-flour-loaves.jpg',
    'assets/images/sour-flour-workshop-students.webp',
    'assets/images/la-victoria-pastry-case.webp',
    'assets/images/sour-flour-samples.jpg',
    'assets/images/sour-flour-bagels.jpg',
];
foreach ($ownerPhotoAssets as $relative) {
    breadeducation_static_assert(is_file($siteRoot . '/' . $relative), $relative . ' is published with the Bread Education site');
}

$ownerPhotoPlacements = [
    'index.html' => [
        'assets/images/sour-flour-workshop-table.avif',
        'assets/images/sour-flour-loaves.jpg',
        'assets/images/sour-flour-samples.jpg',
        'assets/images/la-victoria-pastry-case.webp',
    ],
    'classes/classes.html' => [
        'assets/images/sour-flour-workshop-stretch.webp',
        'assets/images/sour-flour-workshop-students.webp',
    ],
    'breads/bagels.html' => ['assets/images/sour-flour-bagels.jpg'],
    'sell/find-our-bread.html' => ['assets/images/sour-flour-country-loaves.jpg'],
];
foreach ($ownerPhotoPlacements as $relative => $assets) {
    $html = (string)file_get_contents($siteRoot . '/' . $relative);
    foreach ($assets as $asset) {
        breadeducation_static_assert(
            strpos($html, $asset) !== false,
            $relative . ' presents ' . basename($asset)
        );
    }
}

$placeholderFiles = [];
$templateHeroFiles = [];
foreach ($publicFiles as $url => $relative) {
    $html = (string)file_get_contents($siteRoot . '/' . $relative);
    if (str_contains($html, 'TODO')) {
        $placeholderFiles[] = $relative;
    }
    if (str_contains($html, 'One promise, stated plainly.')) {
        $templateHeroFiles[] = $relative;
    }
}
breadeducation_static_assert($placeholderFiles === [], 'published HTML contains no TODO template placeholders' . ($placeholderFiles === [] ? '' : ': ' . implode(', ', $placeholderFiles)));
breadeducation_static_assert($templateHeroFiles === [], 'published HTML contains no template hero copy' . ($templateHeroFiles === [] ? '' : ': ' . implode(', ', $templateHeroFiles)));

$template = (string)file_get_contents($siteRoot . '/TEMPLATE.html');
$templateRobots = breadeducation_static_meta_values($template, 'robots', 'name');
breadeducation_static_assert(
    count($templateRobots) === 1 && strpos(strtolower($templateRobots[0]), 'noindex') !== false,
    'authoring template has a noindex directive'
);
breadeducation_static_assert(
    !preg_match('~\\b(?:href|src)="(?:assets/|\\.?\\./)~', $template),
    'authoring template has no depth-sensitive local URLs'
);
breadeducation_static_assert(
    str_contains($template, 'href="/breadeducation/assets/education-pages.css"')
        && str_contains($template, 'src="/breadeducation/assets/logos/sour-flour-full.png"'),
    'authoring template uses root-relative shared assets'
);

$uploadScript = (string)file_get_contents($root . '/scripts/push_breadeducation_sftp.ps1');
breadeducation_static_assert(
    preg_match('~[$]localOnlyFiles\\s*=\\s*@\\(\\s*\'TEMPLATE[.]html\'\\s*\\)~', $uploadScript) === 1
        && strpos($uploadScript, '$_ -notin $localOnlyFiles') !== false,
    'SFTP uploader excludes TEMPLATE.html'
);
$htaccess = (string)file_get_contents($siteRoot . '/.htaccess');
breadeducation_static_assert(
    strpos($htaccess, 'RedirectMatch 410 ^/breadeducation/TEMPLATE\\.html$') !== false,
    'Apache blocks any stale public TEMPLATE.html copy with 410 Gone'
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
