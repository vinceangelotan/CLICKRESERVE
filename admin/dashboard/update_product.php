<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || isset($_SESSION['customer_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

require dirname(__DIR__, 2) . '/config/products_repository.php';

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$productKey = trim((string) ($payload['productKey'] ?? ''));
if ($productKey === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Product key is required']);
    exit;
}

$products = load_products_repository();
if (!isset($products[$productKey]) || !is_array($products[$productKey])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Product not found']);
    exit;
}

$rawBrand = trim((string) ($payload['brand'] ?? ''));
if ($rawBrand === '__manage_brands__') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Please select a valid brand.']);
    exit;
}

$brand = normalize_product_brand($rawBrand !== '' ? $rawBrand : default_product_brand());
$name = trim((string) ($payload['name'] ?? ''));
$spec1 = trim((string) ($payload['spec1'] ?? ''));
$spec2 = trim((string) ($payload['spec2'] ?? ''));
$tagline = trim((string) ($payload['tagline'] ?? ''));
$priceValue = (float) ($payload['price'] ?? 0);
$discount = (int) ($payload['discountPercent'] ?? 0);
$discount = max(0, min(95, $discount));
$skillLevelsValue = null;
$categoryValue = null;

if (array_key_exists('skillLevels', $payload) || array_key_exists('skillLevel', $payload)) {
    $rawSkillLevels = array_key_exists('skillLevels', $payload)
        ? $payload['skillLevels']
        : ($payload['skillLevel'] ?? default_product_skill_level());

    $skillLevelsValue = normalize_product_skill_levels($rawSkillLevels);
}

if (array_key_exists('category', $payload)) {
    $rawCategory = trim((string) ($payload['category'] ?? ''));

    if ($rawCategory === '__manage_categories__') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Please select a valid category.']);
        exit;
    }

    $categoryValue = normalize_product_category($rawCategory !== '' ? $rawCategory : default_product_category());
}

if ($name === '' || $spec1 === '' || $spec2 === '' || $priceValue < 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Please complete all required fields.']);
    exit;
}

if (has_duplicate_product_display_name($products, $brand, $name, $productKey)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Another product already uses that brand and name.']);
    exit;
}

$product = $products[$productKey];
$product['brand'] = $brand;
$product['name'] = $name;
$product['spec1'] = $spec1;
$product['spec2'] = $spec2;
$product['price'] = number_format($priceValue, 2, '.', '');
$product['discountPercent'] = $discount;

if ($skillLevelsValue !== null) {
    $product['skillLevels'] = $skillLevelsValue;
    $product['skillLevel'] = isset($skillLevelsValue[0])
        ? (string) $skillLevelsValue[0]
        : default_product_skill_level();
}

if ($categoryValue !== null) {
    $product['category'] = $categoryValue;

    if (!ensure_product_category_exists($categoryValue)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Unable to keep category list in sync.']);
        exit;
    }
}

if (array_key_exists('tagline', $payload) && $tagline !== '') {
    $product['tagline'] = $tagline;
}

if (!isset($product['tagline']) || trim((string) $product['tagline']) === '') {
    $product['tagline'] = $spec1 . ' ' . $spec2;
}

$specs = is_array($product['specs'] ?? null) ? $product['specs'] : [];
$specs['Brand'] = [$brand];

if (array_key_exists('imagingSpecs', $payload)) {
    $imagingLines = normalize_lines_array($payload['imagingSpecs']);
    $specs['Imaging and Performance'] = count($imagingLines) ? $imagingLines : ['Sensor details pending.'];
}

if (array_key_exists('videoSpecs', $payload)) {
    $videoLines = normalize_lines_array($payload['videoSpecs']);
    $specs['Video'] = count($videoLines) ? $videoLines : ['Video details pending.'];
}

if (array_key_exists('physicalSpecs', $payload)) {
    $physicalLines = normalize_lines_array($payload['physicalSpecs']);
    $specs['Physical Specifications'] = count($physicalLines) ? $physicalLines : ['Physical details pending.'];
}

$product['specs'] = $specs;

if (array_key_exists('captureSlides', $payload)) {
    $slides = normalize_lines_array($payload['captureSlides']);
    if (count($slides) < 1) {
        $slides = ['Sample capture'];
    }
    $product['captureSlides'] = array_slice($slides, 0, 5);
}

unset($product['availability']);
unset($product['featuredDate']);

$imageDataUrl = trim((string) ($payload['imageDataUrl'] ?? ''));
if (strpos($imageDataUrl, 'data:image/') === 0) {
    try {
        $projectRoot = dirname(__DIR__, 2);
        $product['cameraImage'] = save_product_image_from_data_url($imageDataUrl, $brand, $name, $projectRoot);
    } catch (Throwable $error) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
        exit;
    }
}

$products[$productKey] = $product;

if (!ensure_product_brand_exists($brand)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to keep brand list in sync.']);
    exit;
}

if (!save_products_repository($products)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to save product updates.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'productKey' => $productKey,
    'product' => $product
]);
