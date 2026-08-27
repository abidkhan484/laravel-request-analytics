<?php

declare(strict_types=1);

namespace MeShaon\RequestAnalytics\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MeShaon\RequestAnalytics\DTO\RequestDataDTO;
use MeShaon\RequestAnalytics\Services\RequestAnalyticsService;
use MeShaon\RequestAnalytics\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class RequestAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private RequestAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RequestAnalyticsService;
    }

    #[Test]
    public function it_truncates_a_referrer_longer_than_the_default_max_length(): void
    {
        $longReferrer = 'https://example.com/?q='.str_repeat('a', 1100);

        $dto = new RequestDataDTO(
            path: 'blog/post',
            content: '<html><head><title>Title</title></head></html>',
            browserInfo: ['operating_system' => 'Linux', 'browser' => 'Chrome', 'device' => 'Desktop'],
            ipAddress: '127.0.0.1',
            referrer: $longReferrer,
            country: 'US',
            city: 'NYC',
            language: 'en-US',
            queryParams: '[]',
            httpMethod: 'GET',
            responseTime: 0.1,
            requestCategory: 'web',
            sessionId: 'session_1',
            visitorId: 'visitor_1'
        );

        $record = $this->service->store($dto);

        $this->assertSame(1000, strlen($record->referrer));
        $this->assertSame(mb_substr($longReferrer, 0, 1000), $record->referrer);
    }

    #[Test]
    public function it_truncates_a_page_title_longer_than_the_default_max_length(): void
    {
        $longTitle = str_repeat('T', 1100);

        $dto = new RequestDataDTO(
            path: 'blog/post',
            content: '<html><head><title>'.$longTitle.'</title></head></html>',
            browserInfo: ['operating_system' => 'Linux', 'browser' => 'Chrome', 'device' => 'Desktop'],
            ipAddress: '127.0.0.1',
            referrer: '',
            country: 'US',
            city: 'NYC',
            language: 'en-US',
            queryParams: '[]',
            httpMethod: 'GET',
            responseTime: 0.1,
            requestCategory: 'web',
            sessionId: 'session_2',
            visitorId: 'visitor_2'
        );

        $record = $this->service->store($dto);

        $this->assertSame(1000, strlen($record->page_title));
        $this->assertSame(mb_substr($longTitle, 0, 1000), $record->page_title);
    }

    #[Test]
    public function it_truncates_a_path_longer_than_the_default_max_length(): void
    {
        $longPath = str_repeat('a', 1100);

        $dto = new RequestDataDTO(
            path: $longPath,
            content: '<html></html>',
            browserInfo: ['operating_system' => 'Linux', 'browser' => 'Chrome', 'device' => 'Desktop'],
            ipAddress: '127.0.0.1',
            referrer: '',
            country: 'US',
            city: 'NYC',
            language: 'en-US',
            queryParams: '[]',
            httpMethod: 'GET',
            responseTime: 0.1,
            requestCategory: 'web',
            sessionId: 'session_3',
            visitorId: 'visitor_3'
        );

        $record = $this->service->store($dto);

        $this->assertSame(1000, strlen($record->path));
        $this->assertSame(mb_substr($longPath, 0, 1000), $record->path);
    }

    #[Test]
    public function it_does_not_alter_values_within_the_column_limit(): void
    {
        $dto = new RequestDataDTO(
            path: 'blog/post',
            content: '<html><head><title>Normal Title</title></head></html>',
            browserInfo: ['operating_system' => 'Linux', 'browser' => 'Chrome', 'device' => 'Desktop'],
            ipAddress: '127.0.0.1',
            referrer: 'https://example.com',
            country: 'US',
            city: 'NYC',
            language: 'en-US',
            queryParams: '[]',
            httpMethod: 'GET',
            responseTime: 0.1,
            requestCategory: 'web',
            sessionId: 'session_4',
            visitorId: 'visitor_4'
        );

        $record = $this->service->store($dto);

        $this->assertSame('blog/post', $record->path);
        $this->assertSame('Normal Title', $record->page_title);
        $this->assertSame('https://example.com', $record->referrer);
    }

    #[Test]
    public function it_truncates_multibyte_page_title_without_breaking_characters(): void
    {
        $longMultibyteTitle = str_repeat('あ', 1100);

        $dto = new RequestDataDTO(
            path: 'blog/post',
            content: '<html><head><title>'.$longMultibyteTitle.'</title></head></html>',
            browserInfo: ['operating_system' => 'Linux', 'browser' => 'Chrome', 'device' => 'Desktop'],
            ipAddress: '127.0.0.1',
            referrer: '',
            country: 'US',
            city: 'NYC',
            language: 'en-US',
            queryParams: '[]',
            httpMethod: 'GET',
            responseTime: 0.1,
            requestCategory: 'web',
            sessionId: 'session_5',
            visitorId: 'visitor_5'
        );

        $record = $this->service->store($dto);

        $this->assertSame(1000, mb_strlen($record->page_title));
        $this->assertTrue(mb_check_encoding($record->page_title, 'UTF-8'));
    }

    #[Test]
    public function it_truncates_using_the_configured_max_string_length(): void
    {
        config()->set('request-analytics.database.max_string_length', 50);

        $dto = new RequestDataDTO(
            path: 'blog/post',
            content: '<html><head><title>'.str_repeat('T', 100).'</title></head></html>',
            browserInfo: ['operating_system' => 'Linux', 'browser' => 'Chrome', 'device' => 'Desktop'],
            ipAddress: '127.0.0.1',
            referrer: str_repeat('a', 100),
            country: 'US',
            city: 'NYC',
            language: 'en-US',
            queryParams: '[]',
            httpMethod: 'GET',
            responseTime: 0.1,
            requestCategory: 'web',
            sessionId: 'session_6',
            visitorId: 'visitor_6'
        );

        $record = $this->service->store($dto);

        $this->assertSame(50, strlen($record->page_title));
        $this->assertSame(50, strlen($record->referrer));
    }

    #[Test]
    #[DataProvider('misconfiguredMaxStringLengthProvider')]
    public function it_falls_back_to_the_default_when_max_string_length_is_misconfigured(string|int $invalidValue): void
    {
        config()->set('request-analytics.database.max_string_length', $invalidValue);

        $dto = new RequestDataDTO(
            path: str_repeat('a', 1100),
            content: '<html></html>',
            browserInfo: ['operating_system' => 'Linux', 'browser' => 'Chrome', 'device' => 'Desktop'],
            ipAddress: '127.0.0.1',
            referrer: '',
            country: 'US',
            city: 'NYC',
            language: 'en-US',
            queryParams: '[]',
            httpMethod: 'GET',
            responseTime: 0.1,
            requestCategory: 'web',
            sessionId: 'session_invalid',
            visitorId: 'visitor_invalid'
        );

        $record = $this->service->store($dto);

        $this->assertSame(1000, strlen($record->path));
    }

    public static function misconfiguredMaxStringLengthProvider(): array
    {
        return [
            'empty string' => [''],
            'zero' => [0],
            'negative' => [-5],
            'non-numeric' => ['not-a-number'],
        ];
    }

    #[Test]
    public function it_accepts_max_string_length_at_the_text_ceiling(): void
    {
        config()->set('request-analytics.database.max_string_length', 16000);

        $dto = new RequestDataDTO(
            path: str_repeat('a', 16100),
            content: '<html></html>',
            browserInfo: ['operating_system' => 'Linux', 'browser' => 'Chrome', 'device' => 'Desktop'],
            ipAddress: '127.0.0.1',
            referrer: '',
            country: 'US',
            city: 'NYC',
            language: 'en-US',
            queryParams: '[]',
            httpMethod: 'GET',
            responseTime: 0.1,
            requestCategory: 'web',
            sessionId: 'session_7',
            visitorId: 'visitor_7'
        );

        $record = $this->service->store($dto);

        $this->assertSame(16000, strlen($record->path));
    }

    #[Test]
    public function it_falls_back_to_the_default_just_above_the_text_ceiling(): void
    {
        config()->set('request-analytics.database.max_string_length', 16001);

        $dto = new RequestDataDTO(
            path: str_repeat('a', 16100),
            content: '<html></html>',
            browserInfo: ['operating_system' => 'Linux', 'browser' => 'Chrome', 'device' => 'Desktop'],
            ipAddress: '127.0.0.1',
            referrer: '',
            country: 'US',
            city: 'NYC',
            language: 'en-US',
            queryParams: '[]',
            httpMethod: 'GET',
            responseTime: 0.1,
            requestCategory: 'web',
            sessionId: 'session_8',
            visitorId: 'visitor_8'
        );

        $record = $this->service->store($dto);

        $this->assertSame(1000, strlen($record->path));
    }
}
