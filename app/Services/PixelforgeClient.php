<?php

namespace App\Services;

use App\Exceptions\PixelforgeApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    public function pricing(): array
    {
        $data = $this->request(fn () => Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->get('/pricing'));

        if (! isset($data['rates'], $data['tokens']) || ! is_array($data['rates']) || ! is_array($data['tokens'])) {
            throw PixelforgeApiException::invalidResponse();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    public function createGeneration(array $parameters, string $idempotencyKey): array
    {
        $data = $this->request(fn () => $this->authenticatedRequest(timeout: 30)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $parameters));

        if (! isset($data['id']) || ! is_string($data['id'])) {
            throw PixelforgeApiException::invalidResponse();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    public function getGeneration(string $providerGenerationId): array
    {
        $data = $this->request(fn () => $this->authenticatedRequest(timeout: 15)
            ->get('/generations/'.rawurlencode($providerGenerationId)));

        if (! isset($data['id']) || ! is_string($data['id'])) {
            throw PixelforgeApiException::invalidResponse();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    public function listGenerations(?int $limit = null, ?string $status = null, ?string $cursor = null): array
    {
        $query = array_filter([
            'limit' => $limit,
            'status' => $status,
            'cursor' => $cursor,
        ], fn (mixed $value): bool => $value !== null);

        $data = $this->request(fn () => $this->authenticatedRequest(timeout: 15)
            ->get('/generations', $query));

        if (! isset($data['data']) || ! is_array($data['data'])) {
            throw PixelforgeApiException::invalidResponse();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    public function usage(?string $startDate = null, ?string $endDate = null): array
    {
        $query = array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], fn (mixed $value): bool => $value !== null);

        $data = $this->request(fn () => $this->authenticatedRequest(timeout: 15)
            ->get('/usage', $query));

        if (! isset($data['data'], $data['total']) || ! is_array($data['data']) || ! is_array($data['total'])) {
            throw PixelforgeApiException::invalidResponse();
        }

        return $data;
    }

    /**
     * @throws PixelforgeApiException
     */
    private function authenticatedRequest(int $timeout): PendingRequest
    {
        $apiKey = trim((string) config('services.pixelforge.api_key'));

        if ($apiKey === '') {
            throw PixelforgeApiException::missingApiKey();
        }

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($apiKey)
            ->timeout($timeout)
            ->connectTimeout(5);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeApiException
     */
    private function request(callable $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw PixelforgeApiException::connectionFailure($e);
        }

        if ($response->failed()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw PixelforgeApiException::invalidResponse();
        }

        return $data;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.pixelforge.base_url'), '/');
    }
}
