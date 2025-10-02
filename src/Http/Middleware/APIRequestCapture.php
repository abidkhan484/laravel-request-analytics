<?php

declare(strict_types=1);

namespace MeShaon\RequestAnalytics\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MeShaon\RequestAnalytics\DTO\RequestDataDTO;
use MeShaon\RequestAnalytics\Jobs\ProcessData;
use MeShaon\RequestAnalytics\Concern\CaptureRequest;
use Symfony\Component\HttpFoundation\Response;

class APIRequestCapture
{
    use CaptureRequest;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (($requestData = $this->capture($request, $response, 'api')) instanceof RequestDataDTO) {
                if (config('request-analytics.queue.enabled', true)) {
                    ProcessData::dispatch($requestData);
                } else {
                    ProcessData::dispatchSync($requestData);
                }
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }
}
