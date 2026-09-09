<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$spec = json_decode((string)file_get_contents($root . '/public/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
if (($spec['openapi'] ?? null) !== '3.0.3') {
    throw new RuntimeException('Unexpected OpenAPI version.');
}
if (($spec['info']['version'] ?? null) !== trim((string)file_get_contents($root . '/VERSION'))) {
    throw new RuntimeException('OpenAPI version does not match VERSION.');
}

$expected = [
    'GET /spaces' => ['/api/v1/spaces', 'spaces:read'],
    'GET /pages/{id}' => ['/api/v1/pages/{id}', 'pages:read'],
    'PUT /pages/{id}' => ['/api/v1/pages/{id}', 'pages:write'],
    'POST /pages' => ['/api/v1/pages', 'pages:write'],
    'GET /search' => ['/api/v1/search', 'pages:read'],
    'POST /pages/{id}/attachments' => ['/api/v1/pages/{id}/attachments', 'attachments:write'],
    'GET /attachments/{id}' => ['/api/v1/attachments/{id}', 'attachments:read'],
];

$routeSource = (string)file_get_contents($root . '/app/Bootstrap/RouteRegistrar.php');
foreach ($expected as $operation => [$route, $scope]) {
    [$method, $path] = explode(' ', $operation, 2);
    $node = $spec['paths'][$path][strtolower($method)] ?? null;
    if (!is_array($node)) {
        throw new RuntimeException('OpenAPI operation missing: ' . $operation);
    }
    if (($node['x-imwiki-scope'] ?? null) !== $scope) {
        throw new RuntimeException('Incorrect scope in OpenAPI: ' . $operation);
    }
    if (!isset($node['responses']['401'], $node['responses']['403'])) {
        throw new RuntimeException('Authentication responses missing: ' . $operation);
    }
    $routerMethod = strtolower($method);
    $needle = '$router->' . $routerMethod . "('" . $route . "'";
    if (!str_contains($routeSource, $needle)) {
        throw new RuntimeException('Router/OpenAPI drift: ' . $operation . ' -> ' . $route);
    }
}

foreach (['ApiError', 'Space', 'Page', 'PageCreate', 'PageUpdate', 'SearchItem', 'AttachmentCreated'] as $schema) {
    if (!is_array($spec['components']['schemas'][$schema] ?? null)) {
        throw new RuntimeException('Schema missing: ' . $schema);
    }
}

$tokenService = (string)file_get_contents($root . '/app/Services/ApiTokenService.php');
foreach (array_unique(array_column($expected, 1)) as $scope) {
    if (!str_contains($tokenService, "'" . $scope . "'")) {
        throw new RuntimeException('OpenAPI references unknown token scope: ' . $scope);
    }
}

$index = (string)file_get_contents($root . '/index.php');
if (!str_contains($index, 'new Application(__DIR__)') || strlen($index) > 1500) {
    throw new RuntimeException('index.php is no longer a minimal front controller.');
}

echo "OPENAPI_CONTRACT_OK\n";
