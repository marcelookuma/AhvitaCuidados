<?php
/**
 * Servidor de desenvolvimento:
 *   CUIDADOS_CONFIG=config/configuracao.php php -S localhost:8080 dev-router.php
 * Serve a API em /api/v1 e, se existir, o app web compilado (apps/web/dist).
 */
$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/public/index.php';
    return true;
}
$dist = realpath(__DIR__ . '/../apps/web/dist');
if ($dist) {
    $arq = realpath($dist . $uri);
    if ($arq && is_dir($arq) && is_file($arq . '/index.html')) $arq .= '/index.html'; // /admin/ → admin/index.html
    if ($arq && str_starts_with($arq, $dist) && is_file($arq)) {
        $tipos = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png',
            'webmanifest' => 'application/manifest+json', 'json' => 'application/json', 'html' => 'text/html', 'woff2' => 'font/woff2'];
        header('Content-Type: ' . ($tipos[pathinfo($arq, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($arq);
        return true;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($dist . '/index.html');
    return true;
}
http_response_code(404);
echo 'Rode "npm run build" em apps/web para servir o app.';
return true;
