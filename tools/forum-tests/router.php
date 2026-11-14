<?php
/** PHP development-server router for local tests only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$public = realpath($_SERVER['DOCUMENT_ROOT']);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($public.rawurldecode($path));
if ($file && str_starts_with($file,$public.DIRECTORY_SEPARATOR) && is_file($file) && preg_match('~^/forum-assets/[a-zA-Z0-9._-]+\.(?:css|js)$~D',$path)) return false;
require $public.'/index.php';
