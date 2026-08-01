<?php

declare(strict_types=1);

namespace formflow;

final class HttpsDetector
{
    /** @param list<string> $trustedProxies */
    public function isHttps(array $server, array $trustedProxies = []): bool
    {
        if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') {
            return true;
        }

        $remoteAddress = $server['REMOTE_ADDR'] ?? null;
        $forwardedProto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;

        if (!is_string($remoteAddress) || !is_string($forwardedProto) || $forwardedProto === '') {
            return false;
        }

        if (!(new IpMatcher())->matches($remoteAddress, $trustedProxies)) {
            return false;
        }

        $proto = strtolower(trim(explode(',', $forwardedProto)[0]));

        return $proto === 'https';
    }
}
