<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || preg_match('~(?:^|/)(?:data(?:/|$)|[^/]+\.json$)~i', $path)) {
    http_response_code(404);
    exit;
}

return false;
