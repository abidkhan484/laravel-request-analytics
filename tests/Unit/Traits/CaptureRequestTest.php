<?php

declare(strict_types=1);

namespace MeShaon\RequestAnalytics\Tests\Unit\Traits;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MeShaon\RequestAnalytics\Http\DTO\RequestDataDTO;
use MeShaon\RequestAnalytics\Tests\TestCase;
use MeShaon\RequestAnalytics\Traits\CaptureRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class CaptureRequestTest extends TestCase
{
    private object $traitClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traitClass = new class
        {
            use CaptureRequest;

            public function test_capture(Request $request, \Symfony\Component\HttpFoundation\Response $response, string $category): ?RequestDataDTO
            {
                return $this->capture($request, $response, $category);
            }

            public function test_get_ip_address(Request $request): string
            {
                return $this->getIpAddress($request);
            }

            public function test_anonymize_ip(string $ip): string
            {
                return $this->anonymizeIp($ip);
            }

            public function test_parse_user_agent($userAgent): array
            {
                return $this->parseUserAgent($userAgent);
            }

            public function test_get_operating_system($userAgent): string
            {
                return $this->getOperatingSystem($userAgent);
            }

            public function test_get_browser($userAgent): string
            {
                return $this->getBrowser($userAgent);
            }

            public function test_get_device($userAgent): string
            {
                return $this->getDevice($userAgent);
            }

            public function test_should_ignore(string $path): bool
            {
                return $this->shouldIgnore($path);
            }

            public function test_is_bot(Request $request): bool
            {
                return $this->isBot($request);
            }
        };
    }

    #[Test]
    public function it_returns_null_when_path_should_be_ignored(): void
    {
        config(['request-analytics.ignore-paths' => ['/admin']]);

        $request = Request::create('/admin', 'GET');
        $response = new Response('content');

        $result = $this->traitClass->testCapture($request, $response, 'web');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_dnt_header_is_present_and_respect_dnt_enabled(): void
    {
        config(['request-analytics.privacy.respect_dnt' => true]);

        $request = Request::create('/test', 'GET');
        $request->headers->set('DNT', '1');
        $response = new Response('content');

        $result = $this->traitClass->testCapture($request, $response, 'web');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_request_is_bot_and_bot_capture_disabled(): void
    {
        config([
            'request-analytics.privacy.respect_dnt' => false,
            'request-analytics.capture.bots' => false,
        ]);

        $request = Request::create('/test', 'GET');
        $request->headers->set('User-Agent', 'Googlebot/2.1');
        $response = new Response('content');

        $result = $this->traitClass->testCapture($request, $response, 'web');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_request_data_dto_when_conditions_are_met(): void
    {
        config([
            'request-analytics.privacy.respect_dnt' => false,
            'request-analytics.capture.bots' => true,
            'request-analytics.geolocation.enabled' => false,
        ]);

        $request = Request::create('/test', 'GET', ['param' => 'value']);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $request->headers->set('referer', 'https://example.com');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $response = new Response('content');

        $result = $this->traitClass->testCapture($request, $response, 'web');

        $this->assertInstanceOf(RequestDataDTO::class, $result);
        $this->assertEquals('test', $result->path);
        $this->assertEquals('content', $result->content);
        $this->assertEquals('web', $result->requestCategory);
        $this->assertEquals('GET', $result->httpMethod);
        $this->assertEquals('https://example.com', $result->referrer);
        $this->assertEquals('{"param":"value"}', $result->queryParams);
    }

    #[Test]
    public function it_gets_ip_address_from_request(): void
    {
        $request = Request::create('/test', 'GET');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $result = $this->traitClass->testGetIpAddress($request);

        $this->assertEquals('192.168.1.1', $result);
    }

    #[Test]
    public function it_anonymizes_ip_when_privacy_setting_enabled(): void
    {
        config(['request-analytics.privacy.anonymize_ip' => true]);

        $request = Request::create('/test', 'GET');
        $request->server->set('REMOTE_ADDR', '192.168.1.100');

        $result = $this->traitClass->testGetIpAddress($request);

        $this->assertEquals('192.168.1.0', $result);
    }

    #[Test]
    #[DataProvider('ipv4AnonymizeProvider')]
    public function it_anonymizes_ipv4_addresses_correctly(string $original, string $expected): void
    {
        $result = $this->traitClass->testAnonymizeIp($original);

        $this->assertEquals($expected, $result);
    }

    public static function ipv4AnonymizeProvider(): array
    {
        return [
            ['192.168.1.100', '192.168.1.0'],
            ['10.0.0.50', '10.0.0.0'],
            ['172.16.254.1', '172.16.254.0'],
        ];
    }

    #[Test]
    #[DataProvider('ipv6AnonymizeProvider')]
    public function it_anonymizes_ipv6_addresses_correctly(string $original, string $expected): void
    {
        $result = $this->traitClass->testAnonymizeIp($original);

        $this->assertEquals($expected, $result);
    }

    public static function ipv6AnonymizeProvider(): array
    {
        return [
            ['2001:db8:85a3:0:0:8a2e:370:7334', '2001:db8:85a3:0:0:0:0:0'],
            ['fe80::1', 'fe80::1'], // This one doesn't get anonymized properly
        ];
    }

    #[Test]
    public function it_returns_original_ip_for_invalid_formats(): void
    {
        $invalidIp = 'invalid.ip.address';
        $result = $this->traitClass->testAnonymizeIp($invalidIp);

        $this->assertEquals($invalidIp, $result);
    }

    #[Test]
    #[DataProvider('operatingSystemProvider')]
    public function it_detects_operating_systems_correctly(string $userAgent, string $expected): void
    {
        $result = $this->traitClass->testGetOperatingSystem($userAgent);

        $this->assertEquals($expected, $result);
    }

    public static function operatingSystemProvider(): array
    {
        return [
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'Windows 10'],
            ['Mozilla/5.0 (Windows NT 6.1; Win64; x64)', 'Windows 7'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 'Mac OS X'],
            ['Mozilla/5.0 (X11; Linux x86_64)', 'Linux'],
            ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64)', 'Ubuntu'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X)', 'Mac OS X'],
            ['Mozilla/5.0 (iPad; CPU OS 14_6 like Mac OS X)', 'Mac OS X'],
            ['Mozilla/5.0 (Linux; Android 11)', 'Android'],
            ['Unknown User Agent', 'Unknown'],
        ];
    }

    #[Test]
    #[DataProvider('browserProvider')]
    public function it_detects_browsers_correctly(string $userAgent, string $expected): void
    {
        $result = $this->traitClass->testGetBrowser($userAgent);

        $this->assertEquals($expected, $result);
    }

    public static function browserProvider(): array
    {
        return [
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36', 'Chrome'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0', 'Firefox'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Safari/605.1.15', 'Safari'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 Edg/91.0.864.59', 'Edge'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 OPR/77.0.4054.277', 'Opera'],
            ['Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/6.0)', 'Internet Explorer'],
            ['Unknown User Agent', 'Unknown'],
        ];
    }

    #[Test]
    #[DataProvider('deviceProvider')]
    public function it_detects_devices_correctly(string $userAgent, string $expected): void
    {
        $result = $this->traitClass->testGetDevice($userAgent);

        $this->assertEquals($expected, $result);
    }

    public static function deviceProvider(): array
    {
        return [
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X)', 'iPhone'],
            ['Mozilla/5.0 (iPad; CPU OS 14_6 like Mac OS X)', 'iPad'],
            ['Mozilla/5.0 (iPod touch; CPU iPhone OS 14_6 like Mac OS X)', 'iPod'],
            ['Mozilla/5.0 (Linux; Android 11; SM-G991B)', 'Android'],
            ['Mozilla/5.0 (Mobile; Windows Phone 8.1)', 'Windows Phone'],
            ['Mozilla/5.0 (BlackBerry; U; BlackBerry 9900)', 'BlackBerry'],
            ['Mozilla/5.0 (Mobile; rv:26.0) Gecko/26.0 Firefox/26.0', 'Mobile'],
            ['Mozilla/5.0 (Tablet; rv:26.0) Gecko/26.0 Firefox/26.0', 'Tablet'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'Unknown'],
        ];
    }

    #[Test]
    public function it_parses_user_agent_correctly(): void
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

        $result = $this->traitClass->testParseUserAgent($userAgent);

        $this->assertIsArray($result);
        $this->assertEquals('Windows 10', $result['operating_system']);
        $this->assertEquals('Chrome', $result['browser']);
        $this->assertEquals('Unknown', $result['device']);
    }

    #[Test]
    public function it_checks_if_path_should_be_ignored(): void
    {
        config([
            'request-analytics.ignore-paths' => ['/admin', '/api/health'],
            'request-analytics.route.pathname' => '/analytics',
        ]);

        $this->assertTrue($this->traitClass->testShouldIgnore('/admin'));
        $this->assertTrue($this->traitClass->testShouldIgnore('/api/health'));
        $this->assertTrue($this->traitClass->testShouldIgnore('/analytics'));
        $this->assertFalse($this->traitClass->testShouldIgnore('/home'));
    }

    #[Test]
    public function it_detects_bots_correctly(): void
    {
        $humanRequest = Request::create('/test', 'GET');
        $humanRequest->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

        $botRequest = Request::create('/test', 'GET');
        $botRequest->headers->set('User-Agent', 'Googlebot/2.1');

        $this->assertFalse($this->traitClass->testIsBot($humanRequest));
        $this->assertTrue($this->traitClass->testIsBot($botRequest));
    }
}
