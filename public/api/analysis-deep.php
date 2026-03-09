<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Auth/require_auth.php';

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);

echo json_encode([
    'ok' => false,
    'error' => 'Deep analysis has been retired and is no longer available.',
], JSON_UNESCAPED_SLASHES);
