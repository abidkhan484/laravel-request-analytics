<?php

namespace MeShaon\RequestAnalytics\Traits;

use Illuminate\Http\Request;
use MeShaon\RequestAnalytics\Http\DTO\RequestDataDTO;
use MeShaon\RequestAnalytics\Services\BotDetectionService;
use MeShaon\RequestAnalytics\Services\GeolocationService;
use MeShaon\RequestAnalytics\Services\VisitorTrackingService;
use Symfony\Component\HttpFoundation\Response;

trait CaptureRequest
{
    public function capture(Request $request, Response $response, string $requestCategory): ?RequestDataDTO
    {
        if ($this->shouldIgnore($request->path())) {
            return null;
        }

        // Respect Do Not Track header if enabled
        if (config('request-analytics.privacy.respect_dnt') && $request->header('DNT') === '1') {
            return null;
        }

        // Skip bot traffic unless explicitly enabled
        if ($this->isBot($request) && ! config('request-analytics.capture.bots', false)) {
            return null;
        }

        return $this->prepareRequestData($request, $response, $requestCategory);
    }

    protected function prepareRequestData(Request $request, Response $response, string $requestCategory): RequestDataDTO
    {
        $path = $request->path();
        $content = $response->getContent();
        $browserInfo = $this->parseUserAgent($request->header('User-Agent'));
        $ipAddress = $this->getIpAddress($request);
        $referrer = $request->header('referer', '');

        // Get country from geolocation or CloudFlare header
        $country = '';
        $city = '';
        if (config('request-analytics.geolocation.enabled')) {
            $geo = new GeolocationService;
            $location = $geo->lookup($ipAddress);
            $country = $location['country'] ?: $request->header('CF-IPCountry', '');
            $city = $location['city'] ?? '';
        } else {
            $country = $request->header('CF-IPCountry', '');
        }

        $language = $request->header('Accept-Language', '');
        $queryParams = json_encode($request->query());
        $httpMethod = $request->method();
        $responseTime = defined('LARAVEL_START') ? microtime(true) - LARAVEL_START : 0;

        // Get visitor and session IDs
        $visitorTracking = new VisitorTrackingService;
        $visitorId = $visitorTracking->getVisitorId($request);
        $sessionId = $visitorTracking->getSessionId($request);

        return new RequestDataDTO(
            $path,
            $content,
            $browserInfo,
            $ipAddress,
            $referrer,
            $country,
            $city,
            $language,
            $queryParams,
            $httpMethod,
            $responseTime,
            $requestCategory,
            $sessionId,
            $visitorId
        );
    }

    protected function getIpAddress(Request $request): string
    {
        $ip = $request->ip() ?? $request->server('REMOTE_ADDR');

        // Anonymize IP if privacy setting is enabled
        if (config('request-analytics.privacy.anonymize_ip')) {
            return $this->anonymizeIp($ip);
        }

        return $ip;
    }

    protected function anonymizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // For IPv4, zero out the last octet
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // For IPv6, zero out the last 80 bits
            $parts = explode(':', $ip);
            $counter = count($parts);
            for ($i = 3; $i < $counter; $i++) {
                $parts[$i] = '0';
            }

            return implode(':', $parts);
        }

        return $ip;
    }

    protected function parseUserAgent($userAgent): array
    {
        $operating_system = $this->getOperatingSystem($userAgent);
        $browser = $this->getBrowser($userAgent);
        $device = $this->getDevice($userAgent);

        return ['operating_system' => $operating_system, 'browser' => $browser, 'device' => $device];
    }

    protected function getOperatingSystem($userAgent): string
    {
        $operatingSystem = 'Unknown';
        $osRegexes = [
            '/windows nt 10/i' => 'Windows 10',
            '/windows nt 6\.3/i' => 'Windows 8.1',
            '/windows nt 6\.2/i' => 'Windows 8',
            '/windows nt 6\.1/i' => 'Windows 7',
            '/windows nt 6\.0/i' => 'Windows Vista',
            '/windows nt 5\.2/i' => 'Windows Server 2003/XP x64',
            '/windows nt 5\.1/i' => 'Windows XP',
            '/windows xp/i' => 'Windows XP',
            '/windows nt 5\.0/i' => 'Windows 2000',
            '/windows me/i' => 'Windows ME',
            '/win98/i' => 'Windows 98',
            '/win95/i' => 'Windows 95',
            '/win16/i' => 'Windows 3.11',
            '/macintosh|mac os x/i' => 'Mac OS X',
            '/mac_powerpc/i' => 'Mac OS 9',
            '/iphone/i' => 'iOS',
            '/ipod/i' => 'iOS',
            '/ipad/i' => 'iOS',
            '/android/i' => 'Android',  // Check Android before generic Linux
            '/ubuntu/i' => 'Ubuntu',  // Check Ubuntu before generic Linux
            '/linux/i' => 'Linux',
            '/blackberry/i' => 'BlackBerry',
            '/webos/i' => 'Mobile',
        ];

        foreach ($osRegexes as $regex => $os) {
            if (preg_match($regex, (string) $userAgent)) {
                $operatingSystem = $os;
                break;
            }
        }

        return $operatingSystem;
    }

    protected function getBrowser($userAgent): string
    {
        $browser = 'Unknown';
        $browserRegexes = [
            '/msie|trident/i' => 'Internet Explorer',
            '/edg/i' => 'Edge',  // Edge before Chrome since Edge contains Chrome
            '/edge/i' => 'Edge',
            '/opr|opera/i' => 'Opera',  // Opera before Chrome since Opera contains Chrome
            '/firefox/i' => 'Firefox',
            '/brave/i' => 'Brave',
            '/chrome/i' => 'Chrome',
            '/safari/i' => 'Safari',
        ];

        foreach ($browserRegexes as $regex => $br) {
            if (preg_match($regex, (string) $userAgent)) {
                $browser = $br;
                break;
            }
        }

        return $browser;
    }

    protected function getDevice($userAgent): string
    {
        $device = 'Unknown';
        $deviceRegexes = [
            '/ipad/i' => 'iPad',  // iPad before iPhone since iPad might contain iPhone
            '/ipod/i' => 'iPod',  // iPod before iPhone
            '/iphone/i' => 'iPhone',
            '/android/i' => 'Android',
            '/blackberry/i' => 'BlackBerry',
            '/windows phone/i' => 'Windows Phone',
            '/mobile/i' => 'Mobile',
            '/tablet/i' => 'Tablet',
        ];

        foreach ($deviceRegexes as $regex => $dev) {
            if (preg_match($regex, (string) $userAgent)) {
                $device = $dev;
                break;
            }
        }

        return $device;
    }

    protected function shouldIgnore(string $path): bool
    {
        $ignorePaths = array_merge(config('request-analytics.ignore-paths'), [config('request-analytics.route.pathname')]);

        foreach ($ignorePaths as $ignorePath) {
            // Handle exact matches
            if ($path === $ignorePath) {
                return true;
            }

            // Handle wildcard patterns
            if (str_contains((string) $ignorePath, '*')) {
                $pattern = str_replace('*', '.*', preg_quote((string) $ignorePath, '/'));
                if (preg_match('/^'.$pattern.'$/', $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isBot(Request $request): bool
    {
        $botDetector = new BotDetectionService;

        return $botDetector->isBot(
            $request->header('User-Agent'),
            $request->ip()
        );
    }
}
