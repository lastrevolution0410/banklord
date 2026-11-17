<?php
// php -S router for local preview: static files from public/, everything else via index.php.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/' ) { readfile(__DIR__.'/../backend/public/app.html'); return true; }
$file=realpath(__DIR__.'/../backend/public'.$path);
if ($file && str_starts_with($file,realpath(__DIR__.'/../backend/public')) && is_file($file) && !str_ends_with($file,'.php')) return false;
require __DIR__.'/../backend/public/index.php';
