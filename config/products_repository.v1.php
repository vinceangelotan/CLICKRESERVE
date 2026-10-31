<?php

function products_repository_path()
{
    return __DIR__ . '/products.json';
}

function load_products_repository()
{
    $path = products_repository_path();

    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }

    // Some editors/write paths may prepend UTF-8 BOM; strip it so json_decode works reliably.
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

function save_products_repository($products)
{
    $path = products_repository_path();
    $encoded = json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function default_product_brands()
{
    return [
        'Canon',
        'Fuji',
        'Nikon',
        'Sony'
    ];
}

function sanitize_product_brand_label($brand)
{
    $label = preg_replace('/\s+/', ' ', trim((string) $brand));

    return trim((string) $label);
}

function product_brand_slug($brand)
{
    $normalized = strtolower(sanitize_product_brand_label($brand));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $normalized);
    $slug = trim((string) $slug, '-');

    return $slug !== '' ? $slug : 'brand';
}

function normalize_product_brands_collection($brands, $fallbackToDefaults = true)
{
    $normalized = [];
    $seenSlugs = [];

    foreach ((array) $brands as $brand) {
        $label = sanitize_product_brand_label($brand);

        if ($label === '') {
            continue;
        }

        $slug = product_brand_slug($label);

        if (isset($seenSlugs[$slug])) {
            continue;
        }

        $seenSlugs[$slug] = true;
        $normalized[] = $label;
    }

    if ($normalized || !$fallbackToDefaults) {
        return array_values($normalized);
    }

    return default_product_brands();
}

function product_brands_repository_path()
{
    return __DIR__ . '/brands.json';
}

function load_product_brands_repository()
{
    $path = product_brands_repository_path();
    $fallbackBrands = default_product_brands();

    foreach (load_products_repository() as $product) {
        if (!is_array($product)) {
            continue;
        }

        $fallbackBrands[] = (string) ($product['brand'] ?? '');
    }

    $fallback = normalize_product_brands_collection($fallbackBrands, true);

    if (!is_file($path)) {
        return $fallback;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return $fallback;
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return $fallback;
    }

    $normalized = normalize_product_brands_collection($decoded, false);

    return $normalized ? $normalized : $fallback;
}

function save_product_brands_repository($brands)
{
    $path = product_brands_repository_path();
    $normalized = normalize_product_brands_collection($brands, false);

    if (!$normalized) {
        return false;
    }

    $encoded = json_encode(array_values($normalized), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function product_brand_lookup_map($brands)
{
    $lookup = [];

    foreach (normalize_product_brands_collection($brands, false) as $label) {
        $lowerLabel = strtolower($label);
        $slug = product_brand_slug($label);

        if (!isset($lookup[$lowerLabel])) {
            $lookup[$lowerLabel] = $label;
        }

        if (!isset($lookup[$slug])) {
            $lookup[$slug] = $label;
        }
    }

    return $lookup;
}

function product_brand_value_map($brands = null)
{
    $brandOptions = is_array($brands)
        ? normalize_product_brands_collection($brands, true)
        : load_product_brands_repository();

    $valueMap = [];

    foreach ($brandOptions as $label) {
        $slug = product_brand_slug($label);

        if (!isset($valueMap[$slug])) {
            $valueMap[$slug] = $label;
        }
    }

    return $valueMap;
}

function resolve_product_brand_label($value, $brands = null)
{
    $cleaned = sanitize_product_brand_label($value);

    if ($cleaned === '') {
        return '';
    }

    $brandOptions = is_array($brands) ? $brands : load_product_brands_repository();
    $lookup = product_brand_lookup_map($brandOptions);
    $lowerValue = strtolower($cleaned);

    if (isset($lookup[$lowerValue])) {
        return $lookup[$lowerValue];
    }

    $slugValue = product_brand_slug($cleaned);

    if (isset($lookup[$slugValue])) {
        return $lookup[$slugValue];
    }

    return '';
}

function default_product_brand()
{
    $brands = load_product_brands_repository();

    return isset($brands[0]) ? (string) $brands[0] : 'Canon';
}

function normalize_product_brand($brand)
{
    $resolved = resolve_product_brand_label($brand);

    if ($resolved !== '') {
        return $resolved;
    }

    $cleaned = sanitize_product_brand_label($brand);

    if ($cleaned !== '') {
        return $cleaned;
    }

    return default_product_brand();
}

function ensure_product_brand_exists($brand)
{
    $normalizedBrand = normalize_product_brand($brand);
    $cleaned = sanitize_product_brand_label($normalizedBrand);

    if ($cleaned === '') {
        return false;
    }

    $brands = load_product_brands_repository();

    if (resolve_product_brand_label($cleaned, $brands) !== '') {
        return true;
    }

    $brands[] = $cleaned;

    return save_product_brands_repository($brands);
}

function default_product_skill_levels()
{
    return [
        'Beginner',
        'Professional'
    ];
}

function product_skill_level_options()
{
    return default_product_skill_levels();
}

function default_product_skill_level()
{
    $levels = default_product_skill_levels();

    return isset($levels[0]) ? (string) $levels[0] : 'Beginner';
}

function normalize_product_skill_levels($skillLevels, $fallbackToDefault = true)
{
    $source = [];

    if (is_array($skillLevels)) {
        $source = $skillLevels;
    } else {
        $raw = trim((string) $skillLevels);

        if ($raw !== '' && preg_match('/[,|]/', $raw)) {
            $splitLevels = preg_split('/\s*[,|]\s*/', $raw);
            $source = is_array($splitLevels) ? $splitLevels : [$raw];
        } else {
            $source = [$skillLevels];
        }
    }

    $normalized = [];
    $seen = [];

    foreach ($source as $entry) {
        $cleaned = preg_replace('/\s+/', ' ', trim((string) $entry));

        if ($cleaned === '') {
            continue;
        }

        foreach (default_product_skill_levels() as $option) {
            if (strcasecmp($cleaned, $option) !== 0) {
                continue;
            }

            $optionKey = strtolower((string) $option);

            if (isset($seen[$optionKey])) {
                break;
            }

            $seen[$optionKey] = true;
            $normalized[] = (string) $option;
            break;
        }
    }

    if ($normalized || !$fallbackToDefault) {
        return array_values($normalized);
    }

    return [default_product_skill_level()];
}

function normalize_product_skill_level($skillLevel)
{
    $normalized = normalize_product_skill_levels($skillLevel, true);

    return isset($normalized[0]) ? (string) $normalized[0] : default_product_skill_level();
}

function filter_products_by_skill_level($products, $skillLevel)
{
    if (!is_array($products)) {
        return [];
    }

    $targetSkillLevel = normalize_product_skill_level($skillLevel);
    $filtered = [];

    foreach ($products as $productKey => $product) {
        if (!is_array($product)) {
            continue;
        }

        $productSkillLevels = normalize_product_skill_levels(
            $product['skillLevels'] ?? ($product['skillLevel'] ?? default_product_skill_level())
        );

        if (!in_array($targetSkillLevel, $productSkillLevels, true)) {
            continue;
        }

        $filtered[$productKey] = $product;
    }

    return $filtered;
}

function default_product_categories()
{
    return [
        'Photography',
        'Videography'
    ];
}

function sanitize_product_category_label($category)
{
    $label = preg_replace('/\s+/', ' ', trim((string) $category));

    return trim((string) $label);
}

function product_category_slug($category)
{
    $normalized = strtolower(sanitize_product_category_label($category));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $normalized);
    $slug = trim((string) $slug, '-');

    return $slug !== '' ? $slug : 'category';
}

function normalize_product_categories_collection($categories, $fallbackToDefaults = true)
{
    $normalized = [];
    $seenSlugs = [];

    foreach ((array) $categories as $category) {
        $label = sanitize_product_category_label($category);

        if ($label === '') {
            continue;
        }

        $slug = product_category_slug($label);

        if (isset($seenSlugs[$slug])) {
            continue;
        }

        $seenSlugs[$slug] = true;
        $normalized[] = $label;
    }

    if ($normalized || !$fallbackToDefaults) {
        return array_values($normalized);
    }

    return default_product_categories();
}

function product_categories_repository_path()
{
    return __DIR__ . '/categories.json';
}

function load_product_categories_repository()
{
    $path = product_categories_repository_path();
    $fallbackCategories = default_product_categories();

    foreach (load_products_repository() as $product) {
        if (!is_array($product)) {
            continue;
        }

        $fallbackCategories[] = (string) ($product['category'] ?? '');
    }

    $fallback = normalize_product_categories_collection($fallbackCategories, true);

    if (!is_file($path)) {
        return $fallback;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return $fallback;
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return $fallback;
    }

    $normalized = normalize_product_categories_collection($decoded, false);

    return $normalized ? $normalized : $fallback;
}

function save_product_categories_repository($categories)
{
    $path = product_categories_repository_path();
    $normalized = normalize_product_categories_collection($categories, false);

    if (!$normalized) {
        return false;
    }

    $encoded = json_encode(array_values($normalized), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function product_category_lookup_map($categories)
{
    $lookup = [];

    foreach (normalize_product_categories_collection($categories, false) as $label) {
        $lowerLabel = strtolower($label);
        $slug = product_category_slug($label);

        if (!isset($lookup[$lowerLabel])) {
            $lookup[$lowerLabel] = $label;
        }

        if (!isset($lookup[$slug])) {
            $lookup[$slug] = $label;
        }
    }

    return $lookup;
}

function product_category_value_map($categories = null)
{
    $categoryOptions = is_array($categories)
        ? normalize_product_categories_collection($categories, true)
        : load_product_categories_repository();

    $valueMap = [];

    foreach ($categoryOptions as $label) {
        $slug = product_category_slug($label);

        if (!isset($valueMap[$slug])) {
            $valueMap[$slug] = $label;
        }
    }

    return $valueMap;
}

function resolve_product_category_label($value, $categories = null)
{
    $cleaned = sanitize_product_category_label($value);

    if ($cleaned === '') {
        return '';
    }

    $categoryOptions = is_array($categories) ? $categories : load_product_categories_repository();
    $lookup = product_category_lookup_map($categoryOptions);
    $lowerValue = strtolower($cleaned);

    if (isset($lookup[$lowerValue])) {
        return $lookup[$lowerValue];
    }

    $slugValue = product_category_slug($cleaned);

    if (isset($lookup[$slugValue])) {
        return $lookup[$slugValue];
    }

    return '';
}

function default_product_category()
{
    $categories = load_product_categories_repository();

    return isset($categories[0]) ? (string) $categories[0] : 'Photography';
}

function normalize_product_category($category)
{
    $resolved = resolve_product_category_label($category);

    if ($resolved !== '') {
        return $resolved;
    }

    $cleaned = sanitize_product_category_label($category);

    if ($cleaned !== '') {
        return $cleaned;
    }

    return default_product_category();
}

function ensure_product_category_exists($category)
{
    $normalizedCategory = normalize_product_category($category);
    $cleaned = sanitize_product_category_label($normalizedCategory);

    if ($cleaned === '') {
        return false;
    }

    $categories = load_product_categories_repository();

    if (resolve_product_category_label($cleaned, $categories) !== '') {
        return true;
    }

    $categories[] = $cleaned;

    return save_product_categories_repository($categories);
}

function product_display_name($product)
{
    $brand = normalize_product_brand(isset($product['brand']) ? $product['brand'] : default_product_brand());
    $name = trim((string) (isset($product['name']) ? $product['name'] : ''));

    return trim($brand . ' ' . $name);
}

function product_slug($brand, $name)
{
    $raw = strtolower(trim($brand . ' ' . $name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $raw);
    $slug = trim((string) $slug, '-');

    return $slug !== '' ? $slug : 'product';
}

function unique_product_name($products, $brand, $baseName)
{
    $brandLabel = normalize_product_brand($brand);
    $trimmedBase = trim((string) $baseName);
    $existing = [];

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $existing[strtolower(product_display_name($product))] = true;
    }

    $copyCandidate = $trimmedBase . ' (Copy)';
    if (!isset($existing[strtolower($brandLabel . ' ' . $copyCandidate)])) {
        return $copyCandidate;
    }

    for ($i = 1; $i < 1000; $i++) {
        $candidate = $trimmedBase . ' (' . $i . ')';

        if (!isset($existing[strtolower($brandLabel . ' ' . $candidate)])) {
            return $candidate;
        }
    }

    return $trimmedBase . ' (' . time() . ')';
}

function unique_product_key($products, $brand, $name)
{
    $base = product_slug($brand, $name);

    if (!isset($products[$base])) {
        return $base;
    }

    for ($i = 1; $i < 1000; $i++) {
        $candidate = $base . '-' . $i;

        if (!isset($products[$candidate])) {
            return $candidate;
        }
    }

    return $base . '-' . time();
}

function sanitize_product_filename($value)
{
    $cleaned = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', (string) $value);
    $cleaned = preg_replace('/\s+/', ' ', (string) $cleaned);

    return trim((string) $cleaned);
}

function copy_product_image_for_duplicate($sourceRelativePath, $brand, $name, $projectRoot)
{
    $relativeSource = ltrim((string) $sourceRelativePath, '/');
    if ($relativeSource === '') {
        throw new RuntimeException('Source image path is missing.');
    }

    $sourceAbsolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($relativeSource));

    if (!is_file($sourceAbsolutePath)) {
        throw new RuntimeException('Source image file not found: ' . $relativeSource);
    }

    $extension = strtolower((string) pathinfo($sourceAbsolutePath, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'png';
    }

    $targetDirRelative = 'assets/cameras';
    $targetDirAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetDirRelative);

    if (!is_dir($targetDirAbsolute) && !mkdir($targetDirAbsolute, 0777, true) && !is_dir($targetDirAbsolute)) {
        throw new RuntimeException('Unable to access target image directory.');
    }

    $baseFilename = sanitize_product_filename(normalize_product_brand($brand) . ' ' . $name);
    if ($baseFilename === '') {
        $baseFilename = 'Product Copy';
    }

    $counter = 0;
    do {
        $suffix = $counter === 0 ? '' : ' ' . $counter;
        $rawName = $baseFilename . $suffix . '.' . $extension;
        $targetRelativePath = $targetDirRelative . '/' . rawurlencode($rawName);
        $targetAbsolutePath = $targetDirAbsolute . DIRECTORY_SEPARATOR . $rawName;
        $counter++;
    } while (file_exists($targetAbsolutePath) && $counter < 1000);

    if (!copy($sourceAbsolutePath, $targetAbsolutePath)) {
        throw new RuntimeException('Unable to copy image asset.');
    }

    return $targetRelativePath;
}

function duplicate_product_record($products, $sourceKey, $projectRoot)
{
    if (!isset($products[$sourceKey]) || !is_array($products[$sourceKey])) {
        throw new RuntimeException('Product to duplicate was not found.');
    }

    $source = $products[$sourceKey];
    $brand = normalize_product_brand(isset($source['brand']) ? $source['brand'] : default_product_brand());
    $sourceName = trim((string) (isset($source['name']) ? $source['name'] : 'Product'));

    if ($sourceName === '') {
        $sourceName = 'Product';
    }

    $newName = unique_product_name($products, $brand, $sourceName);
    $newKey = unique_product_key($products, $brand, $newName);

    $duplicate = $source;
    $duplicate['brand'] = $brand;
    $duplicate['name'] = $newName;
    $duplicateSkillLevels = normalize_product_skill_levels(
        $source['skillLevels'] ?? ($source['skillLevel'] ?? default_product_skill_level())
    );
    $duplicate['skillLevels'] = $duplicateSkillLevels;
    $duplicate['skillLevel'] = isset($duplicateSkillLevels[0])
        ? (string) $duplicateSkillLevels[0]
        : default_product_skill_level();
    $duplicate['category'] = normalize_product_category($source['category'] ?? default_product_category());
    unset($duplicate['availability']);
    unset($duplicate['featuredDate']);
    $duplicate['cameraImage'] = copy_product_image_for_duplicate(
        isset($source['cameraImage']) ? $source['cameraImage'] : '',
        $brand,
        $newName,
        $projectRoot
    );

    ensure_product_category_exists($duplicate['category']);

    $products[$newKey] = $duplicate;

    return [
        'products' => $products,
        'newKey' => $newKey,
        'newProduct' => $duplicate
    ];
}

function create_product_record($products, $projectRoot)
{
    $brand = default_product_brand();
    $baseName = 'New Product';
    $newName = unique_product_name($products, $brand, $baseName);
    $newKey = unique_product_key($products, $brand, $newName);

    $imagePath = '';
    $sourceImagePath = '';

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $candidate = trim((string) ($product['cameraImage'] ?? ''));
        if ($candidate === '') {
            continue;
        }

        $absoluteCandidate = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode(ltrim($candidate, '/')));
        if (is_file($absoluteCandidate)) {
            $sourceImagePath = $candidate;
            break;
        }
    }

    if ($sourceImagePath !== '') {
        try {
            $imagePath = copy_product_image_for_duplicate($sourceImagePath, $brand, $newName, $projectRoot);
        } catch (Throwable $error) {
            $imagePath = $sourceImagePath;
        }
    }

    $newProduct = [
        'brand' => $brand,
        'name' => $newName,
        'skillLevels' => [default_product_skill_level()],
        'skillLevel' => default_product_skill_level(),
        'category' => default_product_category(),
        'price' => '0.00',
        'discountPercent' => 0,
        'spec1' => 'New camera specification 1',
        'spec2' => 'New camera specification 2',
        'tagline' => 'Add a short product tagline.',
        'cameraImage' => $imagePath,
        'informationImages' => [],
        'captureSlides' => [],
        'specs' => [
            'Brand' => [$brand],
            'Imaging and Performance' => ['Add imaging and performance details.'],
            'Video' => ['Add video details.'],
            'Physical Specifications' => ['Add physical specifications.']
        ],
        'recommendations' => []
    ];

    ensure_product_category_exists($newProduct['category']);

    $products[$newKey] = $newProduct;

    return [
        'products' => $products,
        'newKey' => $newKey,
        'newProduct' => $newProduct
    ];
}

function normalize_lines_array($value)
{
    if (is_array($value)) {
        $lines = $value;
    } else {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
    }

    $result = [];

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);

        if ($trimmed !== '') {
            $result[] = $trimmed;
        }
    }

    return array_values($result);
}

function product_full_name($brand, $name)
{
    return trim(normalize_product_brand($brand) . ' ' . trim((string) $name));
}

function has_duplicate_product_display_name($products, $brand, $name, $exceptKey)
{
    $target = strtolower(product_full_name($brand, $name));

    foreach ($products as $key => $product) {
        if ($key === $exceptKey || !is_array($product)) {
            continue;
        }

        if (strtolower(product_display_name($product)) === $target) {
            return true;
        }
    }

    return false;
}

function save_product_image_from_data_url($dataUrl, $brand, $name, $projectRoot)
{
    $value = (string) $dataUrl;

    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/i', $value, $matches)) {
        throw new RuntimeException('Invalid image payload.');
    }

    $extension = strtolower($matches[1]);
    if ($extension === 'jpg') {
        $extension = 'jpeg';
    }

    $binary = base64_decode($matches[2], true);
    if ($binary === false) {
        throw new RuntimeException('Invalid base64 image payload.');
    }

    $targetDirRelative = 'assets/cameras';
    $targetDirAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetDirRelative);

    if (!is_dir($targetDirAbsolute) && !mkdir($targetDirAbsolute, 0777, true) && !is_dir($targetDirAbsolute)) {
        throw new RuntimeException('Unable to access target image directory.');
    }

    $baseFilename = sanitize_product_filename(normalize_product_brand($brand) . ' ' . $name);
    if ($baseFilename === '') {
        $baseFilename = 'Product';
    }

    $counter = 0;
    do {
        $suffix = $counter === 0 ? '' : ' ' . $counter;
        $rawName = $baseFilename . $suffix . '.' . $extension;
        $targetRelativePath = $targetDirRelative . '/' . rawurlencode($rawName);
        $targetAbsolutePath = $targetDirAbsolute . DIRECTORY_SEPARATOR . $rawName;
        $counter++;
    } while (file_exists($targetAbsolutePath) && $counter < 1000);

    if (file_put_contents($targetAbsolutePath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save edited image asset.');
    }

    return $targetRelativePath;
}

function archived_products_repository_path()
{
    return __DIR__ . '/archives/products_archived.json';
}

function load_archived_products_repository()
{
    $path = archived_products_repository_path();

    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? array_values($decoded) : [];
}

function save_archived_products_repository($archivedItems)
{
    $path = archived_products_repository_path();
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        return false;
    }

    $encoded = json_encode(array_values((array) $archivedItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function archive_product_image_for_entry($sourceRelativePath, $products, $sourceKey, $archiveKey, $projectRoot)
{
    $relativeSource = ltrim((string) $sourceRelativePath, '/');
    if ($relativeSource === '') {
        return '';
    }

    $sourceAbsolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($relativeSource));
    if (!is_file($sourceAbsolutePath)) {
        return $relativeSource;
    }

    $extension = strtolower((string) pathinfo($sourceAbsolutePath, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'png';
    }

    $targetDirRelative = 'assets/cameras/_archived';
    $targetDirAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetDirRelative);

    if (!is_dir($targetDirAbsolute) && !mkdir($targetDirAbsolute, 0777, true) && !is_dir($targetDirAbsolute)) {
        throw new RuntimeException('Unable to create archive image directory.');
    }

    $baseFilename = sanitize_product_filename($archiveKey);
    if ($baseFilename === '') {
        $baseFilename = 'archived-product';
    }

    $counter = 0;
    do {
        $suffix = $counter === 0 ? '' : ' ' . $counter;
        $rawName = $baseFilename . $suffix . '.' . $extension;
        $targetRelativePath = $targetDirRelative . '/' . rawurlencode($rawName);
        $targetAbsolutePath = $targetDirAbsolute . DIRECTORY_SEPARATOR . $rawName;
        $counter++;
    } while (file_exists($targetAbsolutePath) && $counter < 1000);

    $isSharedImage = false;
    foreach ($products as $key => $product) {
        if ($key === $sourceKey || !is_array($product)) {
            continue;
        }

        $candidate = ltrim((string) ($product['cameraImage'] ?? ''), '/');
        if ($candidate !== '' && strtolower($candidate) === strtolower($relativeSource)) {
            $isSharedImage = true;
            break;
        }
    }

    if ($isSharedImage) {
        if (!copy($sourceAbsolutePath, $targetAbsolutePath)) {
            throw new RuntimeException('Unable to copy archived image asset.');
        }
    } else {
        if (!@rename($sourceAbsolutePath, $targetAbsolutePath)) {
            if (!copy($sourceAbsolutePath, $targetAbsolutePath)) {
                throw new RuntimeException('Unable to move archived image asset.');
            }

            @unlink($sourceAbsolutePath);
        }
    }

    return $targetRelativePath;
}

function archive_product_record($products, $sourceKey, $projectRoot, $archivedProducts = null)
{
    if (!isset($products[$sourceKey]) || !is_array($products[$sourceKey])) {
        throw new RuntimeException('Product to archive was not found.');
    }

    $source = $products[$sourceKey];
    $displayName = product_display_name($source);
    $archiveKey = sanitize_product_filename($displayName . ' ' . date('Ymd-His'));

    if ($archiveKey === '') {
        $archiveKey = 'archived-product-' . date('Ymd-His');
    }

    $archivedProductsList = is_array($archivedProducts)
        ? array_values($archivedProducts)
        : load_archived_products_repository();
    $archivedImagePath = archive_product_image_for_entry(
        isset($source['cameraImage']) ? $source['cameraImage'] : '',
        $products,
        $sourceKey,
        $archiveKey,
        $projectRoot
    );

    if ($archivedImagePath !== '') {
        $source['cameraImage'] = $archivedImagePath;
    }

    $archivedProductsList[] = [
        'archiveKey' => $archiveKey,
        'archivedAt' => gmdate('c'),
        'originalKey' => (string) $sourceKey,
        'product' => $source
    ];

    unset($products[$sourceKey]);

    return [
        'products' => $products,
        'archivedProducts' => $archivedProductsList,
        'archivedEntry' => end($archivedProductsList)
    ];
}

function restore_product_image_from_archive($archivedRelativePath, $brand, $name, $projectRoot)
{
    $relativeSource = ltrim((string) $archivedRelativePath, '/');
    if ($relativeSource === '') {
        return '';
    }

    $sourceAbsolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($relativeSource));
    if (!is_file($sourceAbsolutePath)) {
        return $relativeSource;
    }

    $targetDirRelative = 'assets/cameras';
    $targetDirAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetDirRelative);

    if (!is_dir($targetDirAbsolute) && !mkdir($targetDirAbsolute, 0777, true) && !is_dir($targetDirAbsolute)) {
        throw new RuntimeException('Unable to create camera image directory.');
    }

    $extension = strtolower((string) pathinfo($sourceAbsolutePath, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'png';
    }

    $baseFilename = sanitize_product_filename(normalize_product_brand($brand) . ' ' . $name);
    if ($baseFilename === '') {
        $baseFilename = 'Restored Product';
    }

    $counter = 0;
    do {
        $suffix = $counter === 0 ? '' : ' ' . $counter;
        $rawName = $baseFilename . $suffix . '.' . $extension;
        $targetRelativePath = $targetDirRelative . '/' . rawurlencode($rawName);
        $targetAbsolutePath = $targetDirAbsolute . DIRECTORY_SEPARATOR . $rawName;
        $counter++;
    } while (file_exists($targetAbsolutePath) && $counter < 1000);

    if (!@rename($sourceAbsolutePath, $targetAbsolutePath)) {
        if (!copy($sourceAbsolutePath, $targetAbsolutePath)) {
            throw new RuntimeException('Unable to restore archived image asset.');
        }

        @unlink($sourceAbsolutePath);
    }

    return $targetRelativePath;
}

function restore_archived_product_record($products, $archivedProducts, $archiveKey, $projectRoot)
{
    $matchIndex = null;

    foreach ($archivedProducts as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $candidateKey = trim((string) ($entry['archiveKey'] ?? ''));
        if ($candidateKey !== '' && $candidateKey === $archiveKey) {
            $matchIndex = $index;
            break;
        }
    }

    if ($matchIndex === null) {
        throw new RuntimeException('Archived product was not found.');
    }

    $entry = $archivedProducts[$matchIndex];
    $product = is_array($entry['product'] ?? null) ? $entry['product'] : null;

    if (!is_array($product)) {
        throw new RuntimeException('Archived product payload is invalid.');
    }

    $brand = normalize_product_brand($product['brand'] ?? default_product_brand());
    $name = trim((string) ($product['name'] ?? 'Product'));
    if ($name === '') {
        $name = 'Product';
    }

    $originalKey = trim((string) ($entry['originalKey'] ?? ''));
    $restoreKey = ($originalKey !== '' && !isset($products[$originalKey]))
        ? $originalKey
        : unique_product_key($products, $brand, $name);

    if (!empty($product['cameraImage'])) {
        $product['cameraImage'] = restore_product_image_from_archive(
            (string) $product['cameraImage'],
            $brand,
            $name,
            $projectRoot
        );
    }

    $restoredSkillLevels = normalize_product_skill_levels(
        $product['skillLevels'] ?? ($product['skillLevel'] ?? default_product_skill_level())
    );
    $product['skillLevels'] = $restoredSkillLevels;
    $product['skillLevel'] = isset($restoredSkillLevels[0])
        ? (string) $restoredSkillLevels[0]
        : default_product_skill_level();
    $product['category'] = normalize_product_category($product['category'] ?? default_product_category());
    ensure_product_category_exists($product['category']);

    $products[$restoreKey] = $product;
    array_splice($archivedProducts, $matchIndex, 1);

    return [
        'products' => $products,
        'archivedProducts' => array_values($archivedProducts),
        'restoredKey' => $restoreKey,
        'restoredProduct' => $product,
        'restoredEntry' => $entry
    ];
}

function archived_how_it_works_repository_path()
{
    return __DIR__ . '/archives/how_it_works_archived.json';
}

function load_archived_how_it_works_repository()
{
    $path = archived_how_it_works_repository_path();

    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? array_values($decoded) : [];
}

function save_archived_how_it_works_repository($archivedItems)
{
    $path = archived_how_it_works_repository_path();
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        return false;
    }

    $encoded = json_encode(array_values((array) $archivedItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function archive_how_it_works_image($slot, $projectRoot)
{
    $slotNumber = (int) $slot;
    if ($slotNumber < 1 || $slotNumber > 4) {
        throw new RuntimeException('Slot must be between 1 and 4.');
    }

    $sourceRelativePath = 'assets/how_it_works/' . $slotNumber . '.png';
    $sourceAbsolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceRelativePath);

    if (!is_file($sourceAbsolutePath)) {
        throw new RuntimeException('How it works image was not found.');
    }

    $archiveKey = 'how-it-works-' . $slotNumber . '-' . date('Ymd-His');
    $targetDirRelative = 'assets/how_it_works/_archived';
    $targetDirAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetDirRelative);

    if (!is_dir($targetDirAbsolute) && !mkdir($targetDirAbsolute, 0777, true) && !is_dir($targetDirAbsolute)) {
        throw new RuntimeException('Unable to create archive image directory.');
    }

    $rawName = sanitize_product_filename($archiveKey) . '.png';
    if ($rawName === '.png') {
        $rawName = 'how-it-works-' . $slotNumber . '-' . date('Ymd-His') . '.png';
    }

    $targetAbsolutePath = $targetDirAbsolute . DIRECTORY_SEPARATOR . $rawName;
    $targetRelativePath = $targetDirRelative . '/' . rawurlencode($rawName);

    if (!@rename($sourceAbsolutePath, $targetAbsolutePath)) {
        if (!copy($sourceAbsolutePath, $targetAbsolutePath)) {
            throw new RuntimeException('Unable to archive how it works image.');
        }

        @unlink($sourceAbsolutePath);
    }

    $archivedItems = load_archived_how_it_works_repository();
    $archivedEntry = [
        'archiveKey' => $archiveKey,
        'archivedAt' => gmdate('c'),
        'slot' => $slotNumber,
        'imagePath' => $targetRelativePath
    ];
    $archivedItems[] = $archivedEntry;

    if (!save_archived_how_it_works_repository($archivedItems)) {
        throw new RuntimeException('Unable to save how it works archive data.');
    }

    return [
        'archivedEntry' => $archivedEntry,
        'archivedItems' => $archivedItems
    ];
}

function restore_archived_how_it_works_image($archiveKey, $projectRoot)
{
    $targetArchiveKey = trim((string) $archiveKey);
    if ($targetArchiveKey === '') {
        throw new RuntimeException('Archive key is required.');
    }

    $archivedItems = load_archived_how_it_works_repository();
    $matchIndex = null;

    foreach ($archivedItems as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (trim((string) ($entry['archiveKey'] ?? '')) === $targetArchiveKey) {
            $matchIndex = $index;
            break;
        }
    }

    if ($matchIndex === null) {
        throw new RuntimeException('Archived how it works image was not found.');
    }

    $entry = $archivedItems[$matchIndex];
    $slotNumber = (int) ($entry['slot'] ?? 0);
    if ($slotNumber < 1 || $slotNumber > 4) {
        throw new RuntimeException('Archived slot is invalid.');
    }

    $sourceRelativePath = ltrim((string) ($entry['imagePath'] ?? ''), '/');
    if ($sourceRelativePath === '') {
        throw new RuntimeException('Archived image path is missing.');
    }

    $sourceAbsolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($sourceRelativePath));
    if (!is_file($sourceAbsolutePath)) {
        throw new RuntimeException('Archived image file is missing.');
    }

    $targetRelativePath = 'assets/how_it_works/' . $slotNumber . '.png';
    $targetAbsolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetRelativePath);

    if (!@rename($sourceAbsolutePath, $targetAbsolutePath)) {
        if (!copy($sourceAbsolutePath, $targetAbsolutePath)) {
            throw new RuntimeException('Unable to restore how it works image.');
        }

        @unlink($sourceAbsolutePath);
    }

    array_splice($archivedItems, $matchIndex, 1);
    if (!save_archived_how_it_works_repository($archivedItems)) {
        throw new RuntimeException('Unable to save how it works archive data.');
    }

    return [
        'restoredEntry' => $entry,
        'slot' => $slotNumber,
        'targetPath' => $targetRelativePath,
        'archivedItems' => array_values($archivedItems)
    ];
}

function archived_promo_banners_repository_path()
{
    return __DIR__ . '/archives/promo_banners_archived.json';
}

function load_archived_promo_banners_repository()
{
    $path = archived_promo_banners_repository_path();

    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? array_values($decoded) : [];
}

function save_archived_promo_banners_repository($archivedItems)
{
    $path = archived_promo_banners_repository_path();
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        return false;
    }

    $encoded = json_encode(array_values((array) $archivedItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function archive_promo_banner_image($slot, $projectRoot)
{
    $slotNumber = (int) $slot;
    if ($slotNumber < 1) {
        throw new RuntimeException('Slot must be 1 or greater.');
    }

    $slotFilename = str_pad((string) $slotNumber, 4, '0', STR_PAD_LEFT) . '.png';
    $sourceRelativePath = 'assets/promo_images/' . $slotFilename;
    $sourceAbsolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceRelativePath);

    if (!is_file($sourceAbsolutePath)) {
        throw new RuntimeException('Promo banner image was not found.');
    }

    $archiveKey = 'promo-banner-' . $slotNumber . '-' . date('Ymd-His');
    $targetDirRelative = 'assets/promo_images/_archived';
    $targetDirAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetDirRelative);

    if (!is_dir($targetDirAbsolute) && !mkdir($targetDirAbsolute, 0777, true) && !is_dir($targetDirAbsolute)) {
        throw new RuntimeException('Unable to create archive image directory.');
    }

    $rawName = sanitize_product_filename($archiveKey) . '.png';
    if ($rawName === '.png') {
        $rawName = 'promo-banner-' . $slotNumber . '-' . date('Ymd-His') . '.png';
    }

    $targetAbsolutePath = $targetDirAbsolute . DIRECTORY_SEPARATOR . $rawName;
    $targetRelativePath = $targetDirRelative . '/' . rawurlencode($rawName);

    if (!@rename($sourceAbsolutePath, $targetAbsolutePath)) {
        if (!copy($sourceAbsolutePath, $targetAbsolutePath)) {
            throw new RuntimeException('Unable to archive promo banner image.');
        }

        @unlink($sourceAbsolutePath);
    }

    $archivedItems = load_archived_promo_banners_repository();
    $archivedEntry = [
        'archiveKey' => $archiveKey,
        'archivedAt' => gmdate('c'),
        'slot' => $slotNumber,
        'imagePath' => $targetRelativePath
    ];
    $archivedItems[] = $archivedEntry;

    if (!save_archived_promo_banners_repository($archivedItems)) {
        throw new RuntimeException('Unable to save promo banner archive data.');
    }

    return [
        'archivedEntry' => $archivedEntry,
        'archivedItems' => $archivedItems
    ];
}

function restore_archived_promo_banner_image($archiveKey, $projectRoot)
{
    $targetArchiveKey = trim((string) $archiveKey);
    if ($targetArchiveKey === '') {
        throw new RuntimeException('Archive key is required.');
    }

    $archivedItems = load_archived_promo_banners_repository();
    $matchIndex = null;

    foreach ($archivedItems as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (trim((string) ($entry['archiveKey'] ?? '')) === $targetArchiveKey) {
            $matchIndex = $index;
            break;
        }
    }

    if ($matchIndex === null) {
        throw new RuntimeException('Archived promo banner image was not found.');
    }

    $entry = $archivedItems[$matchIndex];
    $slotNumber = (int) ($entry['slot'] ?? 0);
    if ($slotNumber < 1) {
        throw new RuntimeException('Archived promo slot is invalid.');
    }

    $sourceRelativePath = ltrim((string) ($entry['imagePath'] ?? ''), '/');
    if ($sourceRelativePath === '') {
        throw new RuntimeException('Archived promo image path is missing.');
    }

    $sourceAbsolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($sourceRelativePath));
    if (!is_file($sourceAbsolutePath)) {
        throw new RuntimeException('Archived promo image file is missing.');
    }

    $slotFilename = str_pad((string) $slotNumber, 4, '0', STR_PAD_LEFT) . '.png';
    $targetRelativePath = 'assets/promo_images/' . $slotFilename;
    $targetAbsolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetRelativePath);

    if (!@rename($sourceAbsolutePath, $targetAbsolutePath)) {
        if (!copy($sourceAbsolutePath, $targetAbsolutePath)) {
            throw new RuntimeException('Unable to restore promo banner image.');
        }

        @unlink($sourceAbsolutePath);
    }

    array_splice($archivedItems, $matchIndex, 1);
    if (!save_archived_promo_banners_repository($archivedItems)) {
        throw new RuntimeException('Unable to save promo banner archive data.');
    }

    return [
        'restoredEntry' => $entry,
        'slot' => $slotNumber,
        'targetPath' => $targetRelativePath,
        'archivedItems' => array_values($archivedItems)
    ];
}

function save_promo_banner_image_from_data_url($slot, $dataUrl, $projectRoot)
{
    $slotNumber = (int) $slot;
    if ($slotNumber < 1) {
        throw new RuntimeException('Slot must be 1 or greater.');
    }

    $value = (string) $dataUrl;
    if (!preg_match('/^data:image\/png;base64,(.+)$/i', $value, $matches)) {
        throw new RuntimeException('Invalid image payload.');
    }

    $binary = base64_decode($matches[1], true);
    if ($binary === false) {
        throw new RuntimeException('Invalid base64 image payload.');
    }

    $targetDirRelative = 'assets/promo_images';
    $targetDirAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetDirRelative);

    if (!is_dir($targetDirAbsolute) && !mkdir($targetDirAbsolute, 0777, true) && !is_dir($targetDirAbsolute)) {
        throw new RuntimeException('Target promo image directory is missing.');
    }

    $slotFilename = str_pad((string) $slotNumber, 4, '0', STR_PAD_LEFT) . '.png';
    $targetRelativePath = $targetDirRelative . '/' . $slotFilename;
    $targetAbsolutePath = $targetDirAbsolute . DIRECTORY_SEPARATOR . $slotFilename;

    if (file_put_contents($targetAbsolutePath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save promo banner image asset.');
    }

    return $targetRelativePath;
}
