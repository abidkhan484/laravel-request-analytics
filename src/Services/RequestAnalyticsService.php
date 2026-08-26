<?php

namespace MeShaon\RequestAnalytics\Services;

use Illuminate\Support\Facades\Auth;
use MeShaon\RequestAnalytics\DTO\RequestDataDTO;
use MeShaon\RequestAnalytics\Exceptions\RequestAnalyticsStorageException;
use MeShaon\RequestAnalytics\Models\RequestAnalytics;

class RequestAnalyticsService
{
    private const DEFAULT_MAX_STRING_LENGTH = 1000;

    // MySQL's TEXT capacity (65,535) is a byte limit; under utf8mb4, only ~16,383 chars are guaranteed to fit
    private const MAX_STRING_LENGTH_CEILING = 16000;

    public function store(RequestDataDTO $requestDataDTO)
    {
        $requestData = [
            'path' => $this->truncate($requestDataDTO->path),
            'page_title' => $this->truncate($this->extractPageTitle($requestDataDTO->content)),
            'ip_address' => $requestDataDTO->ipAddress,
            'operating_system' => $requestDataDTO->browserInfo['operating_system'],
            'browser' => $requestDataDTO->browserInfo['browser'],
            'device' => $requestDataDTO->browserInfo['device'],
            'screen' => '',
            'referrer' => $this->truncate($requestDataDTO->referrer),
            'country' => $requestDataDTO->country,
            'city' => $requestDataDTO->city,
            'language' => $requestDataDTO->language,
            'query_params' => $requestDataDTO->queryParams,
            'session_id' => $requestDataDTO->sessionId ?: session()->getId(),
            'visitor_id' => $requestDataDTO->visitorId,
            'user_id' => Auth::id(),
            'http_method' => $requestDataDTO->httpMethod,
            'request_category' => $requestDataDTO->requestCategory,
            'response_time' => round($requestDataDTO->responseTime * 1000), // Convert to milliseconds
            'visited_at' => now(),
        ];

        try {
            return RequestAnalytics::create($requestData);
        } catch (\Exception $e) {
            throw new RequestAnalyticsStorageException(
                $requestData,
                'Failed to store request analytics data: '.$e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function extractPageTitle(string $content): string
    {
        $matches = [];
        preg_match('/<title>(.*?)<\/title>/i', $content, $matches);

        return $matches[1] ?? '';
    }

    private function truncate(string $value): string
    {
        $maxLength = (int) config('request-analytics.database.max_string_length', self::DEFAULT_MAX_STRING_LENGTH);

        if ($maxLength <= 0 || $maxLength > self::MAX_STRING_LENGTH_CEILING) {
            $maxLength = self::DEFAULT_MAX_STRING_LENGTH;
        }

        return mb_substr($value, 0, $maxLength);
    }
}
