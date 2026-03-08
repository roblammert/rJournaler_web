<?php

declare(strict_types=1);

use App\Auth\Auth;

if (!Auth::check() || !Auth::isAdmin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
