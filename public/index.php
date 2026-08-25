<?php

declare(strict_types=1);

use formflow\AppFactory;
use formflow\AppRouter;
use formflow\HttpsDetector;
use formflow\HttpSecurity;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$factory = new AppFactory($root);

if (is_file($root . '/.env')) {
    (new Dotenv())->usePutenv()->load($root . '/.env');
}

$security = $factory->security();
$trustedProxies = is_array($security['trusted_proxies'] ?? null)
    ? array_values(array_map('strval', $security['trusted_proxies']))
    : [];
$isHttps = (new HttpsDetector())->isHttps($_SERVER, $trustedProxies);

HttpSecurity::hardenSessionCookies($isHttps);
HttpSecurity::sendHeaders($isHttps);

(new AppRouter($factory, $security, $root))->dispatch();
