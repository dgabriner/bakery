<?php
/**
 * Audit and seed product catalog images.
 *
 * Run without arguments to list products and image status.
 * Add --import to download category-matched placeholder photos and register
 * one primary image for every product that does not already have one.
 */
define('ACCESS_ALLOWED', true);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/database.php';

$db = check_mysql_connection();

$sources = [
    'country' => [
        'label' => 'Country sourdough',
        'page' => 'https://sourdough.me/products/sourdough-country-loaf',
        'url' => 'https://sourdough.me/cdn/shop/files/DSC036032.jpg?v=1753790883&width=1946',
        'extension' => 'jpg',
    ],
    'batard' => [
        'label' => 'Sourdough batard',
        'page' => 'https://balthazar-bakery.myshopify.com/products/r-breads-levain-batard',
        'url' => 'https://balthazar-bakery.myshopify.com/cdn/shop/products/3E83D492-4E9A-474F-B7C8-DB2615086040.jpg?v=1595441406',
        'extension' => 'jpg',
    ],
    'baguette' => [
        'label' => 'Artisan baguette',
        'page' => 'https://latelier-du-pain.com/english/menu_hard.html',
        'url' => 'https://latelier-du-pain.com/images/pan_hard_panoa.jpg',
        'extension' => 'jpg',
    ],
    'bagel' => [
        'label' => 'Artisan bagels',
        'page' => 'https://nycbakerydirect.com/products/bagels-assorted',
        'url' => 'https://nycbakerydirect.com/cdn/shop/products/breadgal36_5001b221-1492-4df2-952b-c03258ba8381_1024x1024%402x.jpg?v=1591368394',
        'extension' => 'jpg',
    ],
    'pretzel' => [
        'label' => 'Soft pretzel',
        'page' => 'https://www.gfifoods.com/1594-proppeller-king-soft-pretzel',
        'url' => 'https://www.gfifoods.com/media/catalog/product/5/0/50z_ppp32k_20190312_1446242_aoo0vqgldqlt1hwo.jpg?bg-color=255%2C255%2C255&canvas=700%3A700&fit=bounds&height=700&optimize=high&width=700',
        'extension' => 'jpg',
    ],
    'sandwich' => [
        'label' => 'White sandwich loaf',
        'page' => 'https://vintagekitchennotes.com/white-sandwich-bread/',
        'url' => 'https://vintagekitchennotes.com/wp-content/uploads/2025/06/White-sandwich-bread-loaf.jpg',
        'extension' => 'jpg',
    ],
    'wholewheat' => [
        'label' => 'Wholemeal loaf',
        'page' => 'https://bakertom.co.uk/products/organic-100-wholemeal',
        'url' => 'https://bakertom.co.uk/cdn/shop/files/100_Wholemeal.jpg?v=1713438954&width=1946',
        'extension' => 'jpg',
    ],
    'dinner' => [
        'label' => 'Dinner rolls',
        'page' => 'https://www.thebreadgal.com/collections/dinner-rolls',
        'url' => 'https://www.thebreadgal.com/cdn/shop/products/breadgal24_1080x_ecb7cb9a-b751-41ae-895c-ddd557f5979a_1080x.jpg?v=1593745172',
        'extension' => 'jpg',
    ],
    'concha' => [
        'label' => 'Mexican conchas',
        'page' => 'https://successiblelife.com/es/receta-de-conchas-mexicanas/',
        'url' => 'https://successiblelife.com/wp-content/uploads/2025/01/CONCHA.webp',
        'extension' => 'webp',
    ],
    'pandulce' => [
        'label' => 'Mexican pan dulce assortment',
        'page' => 'https://borderzine.com/2022/10/how-the-pan-dulce-supply-chain-shortage-made-me-appreciate-the-art-of-making-sweet-bread/',
        'url' => 'https://borderzine.com/wp-content/uploads/2022/10/Pan_Dulce_wikicommons-1170x881.jpeg',
        'extension' => 'jpg',
    ],
    'cinnamon' => [
        'label' => 'Cinnamon rolls',
        'page' => 'https://www.doublebatchbakery.com/product/tray-of-six-cinnamon-rolls-pre-order-/1963',
        'url' => 'https://124948194.cdn6.editmysite.com/uploads/1/2/4/9/124948194/A2XGWNS3OUE2YYQFWJPFWMH3.jpeg?optimize=medium&width=2400',
        'extension' => 'jpg',
    ],
    'potato' => [
        'label' => 'Potato bread',
        'page' => 'https://bonviveur.com/es/recetas/pan-de-patata',
        'url' => 'https://imag.bonviveur.com/pan-de-patata-listo-para-comer.jpg',
        'extension' => 'jpg',
    ],
    'ciabatta' => [
        'label' => 'Sourdough ciabatta',
        'page' => 'https://shop.sharon-bakery.com/products/a-package-of-4-soft-and-airy-sourdough-ciabbata-roles-br',
        'url' => 'https://shop.sharon-bakery.com/cdn/shop/files/IMG_20231208_153434a_1024x1024.jpg?v=1702050384',
        'extension' => 'jpg',
    ],
];

function catalog_image_source_for_product(array $product) {
    $name = strtolower((string)$product['name']);
    $line = strtolower((string)$product['line']);
    $dough = strtolower((string)$product['dough']);

    if (strpos($name, 'bagel') !== false || $dough === 'bagel') return 'bagel';
    if (strpos($name, 'pretzel') !== false || $dough === 'pretzel') return 'pretzel';
    if (strpos($name, 'ciabatta') !== false) return 'ciabatta';
    if (strpos($name, 'potato') !== false) return 'potato';
    if (strpos($name, 'whole wheat') !== false || $dough === 'whole wheat') return 'wholewheat';
    if (strpos($name, 'cinnamon') !== false || strpos($name, 'roles de canela') !== false) return 'cinnamon';
    if (strpos($name, 'concha') !== false || $dough === 'concha') return 'concha';
    if ($line === 'pan dulce') return 'pandulce';
    if (strpos($name, 'baguette') !== false) return 'baguette';
    if (strpos($name, 'dinner roll') !== false || $dough === 'dinner rolls') return 'dinner';
    if (strpos($name, 'pan de mie') !== false || strpos($name, 'white') !== false || strpos($dough, 'white') !== false) return 'sandwich';
    if (strpos($name, 'sourdough') !== false || strpos($name, 'mondo') !== false || strpos($dough, 'sourdough') !== false) return 'country';
    return 'sandwich';
}

function catalog_distinct_tags_for_product(array $product) {
    $name = strtolower((string)$product['name']);
    $dough = strtolower((string)$product['dough']);

    if (strpos($name, 'poppy') !== false) return 'poppy-seed,bagel';
    if (strpos($name, 'sesame') !== false) return 'sesame,bagel';
    if (strpos($name, 'everything') !== false) return 'everything,bagel';
    if (strpos($name, 'salt bagel') !== false) return 'salt,bagel';
    if (strpos($name, 'bagel') !== false || $dough === 'bagel') return 'bagel';
    if (strpos($name, 'pretzel') !== false || $dough === 'pretzel') return 'pretzel';
    if (strpos($name, 'ciabatta') !== false) return 'ciabatta';
    if (strpos($name, 'potato') !== false) return 'potato,bread';
    if (strpos($name, 'whole wheat') !== false || $dough === 'whole wheat') return 'whole-wheat,bread';
    if (strpos($name, 'baguette') !== false) return 'baguette';
    if (strpos($name, 'cinnamon') !== false || strpos($name, 'roles de canela') !== false) return 'cinnamon-roll';
    if (strpos($name, 'pan de mie') !== false) return 'sandwich,bread';
    if (strpos($name, 'white') !== false || strpos($dough, 'white') !== false) return 'white,bread';
    if (strpos($name, 'dinner roll') !== false || $dough === 'dinner rolls') return 'dinner-roll';
    if (strpos($name, 'sourdough') !== false || strpos($dough, 'sourdough') !== false) return 'sourdough,bread';
    if (strpos($name, 'mondo') !== false) return 'large,loaf,bread';

    $panDulceTags = [
        'liso' => 'mexican,sweet-bread',
        'cocol' => 'cocol,mexican,bread',
        'elotes' => 'mexican,corn,pastry',
        'cuerno azucar' => 'mexican,crescent,pastry',
        'nuez' => 'walnut,pastry',
        'tostado' => 'mexican,sweet-bread',
        'nopal' => 'mexican,cactus,pastry',
        'chamuco' => 'mexican,pastry',
        'barras' => 'mexican,pastry',
        'quequitos' => 'cupcake',
        'budín' => 'pudding,cake',
        'cortadillos' => 'bar,cookie',
        'colchón' => 'mexican,sweet-bread',
        'guayaba' => 'guava,pastry',
        'puerco' => 'mexican,pastry',
        'taco' => 'mexican,pastry',
        'grajea' => 'sprinkle,cookie',
        'barra (rebanada)' => 'mexican,bread,slice',
        'polvoron rosada' => 'polvoron,shortbread',
        'polvoron amarilla' => 'polvoron,shortbread',
        'chocolate chip' => 'chocolate-chip,cookie',
        'mariana' => 'mexican,pastry',
        'yo-yo' => 'sandwich-cookie',
        'pinguino' => 'chocolate,cupcake',
        'pastel' => 'cake',
        'barra de mantequia' => 'butter,cookie',
        'gusano' => 'mexican,pastry',
        'tortuga' => 'mexican,pastry',
        'quesadilla' => 'mexican,cheese,pastry',
    ];
    foreach ($panDulceTags as $needle => $tags) {
        if (strpos($name, $needle) !== false) return $tags;
    }
    if (strpos($name, 'concha') !== false || $dough === 'concha') return 'concha,mexican';
    return 'mexican,pan-dulce';
}

$productSql = "SELECT p.id, p.name, COALESCE(pl.name, '') AS line, COALESCE(dt.name, '') AS dough,
                      CASE WHEN pi.id IS NULL THEN 0 ELSE 1 END AS has_image
               FROM products p
               LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
               LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
               LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
               ORDER BY p.id";
$products = $db->query($productSql)->fetchAll(PDO::FETCH_ASSOC);

if (in_array('--distinct', $argv, true)) {
    $uploadRoot = dirname(__DIR__) . '/uploads/product_photos/catalog';
    if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0755, true) && !is_dir($uploadRoot)) {
        throw new RuntimeException('Could not create ' . $uploadRoot);
    }

    $context = stream_context_create([
        'http' => ['timeout' => 8, 'follow_location' => 1, 'user_agent' => 'Bakery distinct catalog image importer'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $usedHashes = [];
    $resume = in_array('--resume', $argv, true);
    if ($resume) {
        foreach (glob($uploadRoot . '/product*-distinct.jpg') ?: [] as $existingFile) {
            $usedHashes[hash_file('sha256', $existingFile)] = true;
        }
    }

    foreach ($products as $product) {
        $productId = (int)$product['id'];
        $tags = catalog_distinct_tags_for_product($product);
        if ($resume) {
            $current = $db->prepare('SELECT file_path FROM product_images WHERE product_id = ? AND is_primary = 1 LIMIT 1');
            $current->execute([$productId]);
            if ($current->fetchColumn() === 'catalog/product' . $productId . '-distinct.jpg') {
                echo "RESUME-SKIP\t{$productId}\t{$product['name']}\n";
                continue;
            }
        }
        $accepted = null;
        $tagCandidates = array_values(array_unique([$tags, 'pastry', 'bread']));
        foreach ($tagCandidates as $candidateTags) {
            for ($attempt = 0; $attempt < 8 && $accepted === null; $attempt++) {
                $lock = 10000 + ($productId * 17) + ($attempt * 1000);
                $encodedTags = str_replace('%2C', ',', rawurlencode($candidateTags));
                $url = 'https://loremflickr.com/900/900/' . $encodedTags . '?lock=' . $lock;
                $bytes = @file_get_contents($url, false, $context);
                if ($bytes === false) continue;
                $hash = hash('sha256', $bytes);
                if (isset($usedHashes[$hash])) continue;
                $tmp = tempnam(sys_get_temp_dir(), 'bakery-distinct-');
                file_put_contents($tmp, $bytes);
                $info = @getimagesize($tmp);
                if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                    @unlink($tmp);
                    continue;
                }
                $accepted = ['tmp' => $tmp, 'bytes' => $bytes, 'hash' => $hash, 'info' => $info, 'url' => $url];
            }
            if ($accepted !== null) break;
        }
        if ($accepted === null) {
            throw new RuntimeException("Could not find a distinct image for {$product['name']}");
        }
        $usedHashes[$accepted['hash']] = true;

        $filename = 'product' . $productId . '-distinct.jpg';
        $relativePath = 'catalog/' . $filename;
        $destination = $uploadRoot . '/' . $filename;
        file_put_contents($destination, $accepted['bytes']);
        $primary = $db->prepare('SELECT id FROM product_images WHERE product_id = ? AND is_primary = 1 ORDER BY sort_order, id LIMIT 1');
        $primary->execute([$productId]);
        $imageId = $primary->fetchColumn();

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
            if ($imageId) {
                $update = $db->prepare(
                    'UPDATE product_images SET filename = ?, file_path = ?, is_primary = 1, sort_order = 0, file_size = ?, mime_type = ? WHERE id = ?'
                );
                $update->execute([$filename, $relativePath, filesize($destination), $accepted['info']['mime'], (int)$imageId]);
            } else {
                $insert = $db->prepare(
                    'INSERT INTO product_images (product_id, filename, file_path, is_primary, sort_order, file_size, mime_type)
                     VALUES (?, ?, ?, 1, 0, ?, ?)'
                );
                $insert->execute([$productId, $filename, $relativePath, filesize($destination), $accepted['info']['mime']]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            @unlink($destination);
            @unlink($accepted['tmp']);
            throw $e;
        }
        @unlink($accepted['tmp']);
        echo "DISTINCT\t{$productId}\t{$product['name']}\t{$tags}\t{$accepted['info'][0]}x{$accepted['info'][1]}\n";
    }
    exit(0);
}

if (in_array('--import', $argv, true)) {
    $uploadRoot = dirname(__DIR__) . '/uploads/product_photos/catalog';
    if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0755, true) && !is_dir($uploadRoot)) {
        throw new RuntimeException('Could not create ' . $uploadRoot);
    }

    $context = stream_context_create([
        'http' => ['timeout' => 30, 'follow_location' => 1, 'user_agent' => 'Bakery catalog image importer'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $cache = [];

    foreach ($products as $product) {
        $productId = (int)$product['id'];
        $sourceKey = catalog_image_source_for_product($product);
        $source = $sources[$sourceKey];

        $existing = $db->prepare('SELECT id FROM product_images WHERE product_id = ? AND is_primary = 1 LIMIT 1');
        $existing->execute([$productId]);
        if ($existing->fetchColumn()) {
            echo "SKIP\t{$productId}\t{$product['name']}\talready has a primary image\n";
            continue;
        }

        if (!isset($cache[$sourceKey])) {
            $bytes = @file_get_contents($source['url'], false, $context);
            if ($bytes === false) throw new RuntimeException("Could not download {$source['url']}");
            $tmp = tempnam(sys_get_temp_dir(), 'bakery-catalog-');
            file_put_contents($tmp, $bytes);
            $info = @getimagesize($tmp);
            $mime = $info['mime'] ?? '';
            if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                @unlink($tmp);
                throw new RuntimeException("Downloaded file for {$source['label']} is not a supported image");
            }
            $cache[$sourceKey] = ['tmp' => $tmp, 'info' => $info, 'mime' => $mime];
        }

        $cached = $cache[$sourceKey];
        $filename = 'product' . $productId . '-generic.' . $source['extension'];
        $relativePath = 'catalog/' . $filename;
        $destination = $uploadRoot . '/' . $filename;
        if (!copy($cached['tmp'], $destination)) throw new RuntimeException("Could not save {$destination}");

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
            $insert = $db->prepare(
                'INSERT INTO product_images (product_id, filename, file_path, is_primary, sort_order, file_size, mime_type)
                 VALUES (?, ?, ?, 1, 0, ?, ?)'
            );
            $insert->execute([$productId, $filename, $relativePath, filesize($destination), $cached['mime']]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            @unlink($destination);
            throw $e;
        }

        echo "IMPORTED\t{$productId}\t{$product['name']}\t{$sourceKey}\t{$cached['info'][0]}x{$cached['info'][1]}\n";
    }
    foreach ($cache as $cached) @unlink($cached['tmp']);
    exit(0);
}

foreach ($products as $product) {
    echo implode("\t", [$product['id'], $product['name'], $product['line'], $product['dough'], $product['has_image']]) . PHP_EOL;
}
