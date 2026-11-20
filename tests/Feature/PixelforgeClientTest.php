<?php

namespace Tests\Feature;

use App\Exceptions\PixelforgeApiException;
use App\Services\PixelforgeClient;
use Illuminate\Support\Facades\Http;
use Tests\FakesPixelforge;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    use FakesPixelforge;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
            'services.pixelforge.api_key' => 'pf_test_key',
        ]);
    }

    public function test_pricing_endpoint_does_not_require_authentication(): void
    {
        config(['services.pixelforge.api_key' => null]);
        Http::fake(['*/v1/pricing' => Http::response($this->pricingPayload())]);

        $pricing = app(PixelforgeClient::class)->pricing();

        $this->assertSame('pricing', $pricing['object']);
        $this->assertSame('5.00', $pricing['rates']['input_text_tokens']);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/pricing'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_get_generation_sends_bearer_token(): void
    {
        Http::fake(['*/v1/generations/gen_123' => Http::response($this->queuedGenerationPayload())]);

        $generation = app(PixelforgeClient::class)->getGeneration('gen_123');

        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation['id']);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations/gen_123'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
    }

    public function test_list_generations_forwards_optional_query_parameters(): void
    {
        Http::fake([
            '*/v1/generations*' => Http::response([
                'object' => 'list',
                'data' => [$this->queuedGenerationPayload()],
                'has_more' => false,
                'next_cursor' => null,
            ]),
        ]);

        $list = app(PixelforgeClient::class)->listGenerations(limit: 10, status: 'succeeded', cursor: 'cur_abc');

        $this->assertCount(1, $list['data']);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations?limit=10&status=succeeded&cursor=cur_abc'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
    }

    public function test_usage_forwards_date_range_parameters(): void
    {
        Http::fake([
            '*/v1/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-02',
                'data' => [
                    [
                        'date' => '2026-11-01',
                        'generations' => 3,
                        'input_text_tokens' => 41,
                        'input_image_tokens' => 0,
                        'output_tokens' => 3300,
                        'reasoning_tokens' => 2950,
                        'cost_usd' => '0.169080000',
                    ],
                ],
                'total' => [
                    'generations' => 3,
                    'input_text_tokens' => 41,
                    'input_image_tokens' => 0,
                    'output_tokens' => 3300,
                    'reasoning_tokens' => 2950,
                    'cost_usd' => '0.169080000',
                ],
            ]),
        ]);

        $usage = app(PixelforgeClient::class)->usage('2026-11-01', '2026-11-02');

        $this->assertSame('0.169080000', $usage['total']['cost_usd']);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-01&end_date=2026-11-02'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
    }

    public function test_missing_api_key_fails_fast_without_sending_http_request(): void
    {
        config(['services.pixelforge.api_key' => '']);
        Http::fake();

        try {
            app(PixelforgeClient::class)->getGeneration('gen_123');
            $this->fail('Expected PixelforgeApiException was not thrown.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame('invalid_api_key', $e->errorCode);
            $this->assertSame(401, $e->statusCode);
            $this->assertFalse($e->retryable);
        }

        Http::assertNothingSent();
    }

    public function test_unexpected_500_is_marked_retryable_and_uncertain(): void
    {
        Http::fake(['*/v1/generations' => Http::response(['error' => ['message' => 'Internal server error']], 500)]);

        try {
            app(PixelforgeClient::class)->createGeneration(['prompt' => 'test'], 'key-1');
            $this->fail('Expected PixelforgeApiException was not thrown.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame(500, $e->statusCode);
            $this->assertTrue($e->retryable);
            $this->assertTrue($e->uncertain);
        }
    }

    public function test_overloaded_503_is_marked_retryable_and_not_uncertain(): void
    {
        Http::fake([
            '*/v1/generations' => Http::response(
                ['error' => ['code' => 'overloaded', 'message' => 'Pixelforge is temporarily overloaded.']],
                503,
                ['Retry-After' => '2'],
            ),
        ]);

        try {
            app(PixelforgeClient::class)->createGeneration(['prompt' => 'test'], 'key-1');
            $this->fail('Expected PixelforgeApiException was not thrown.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame('overloaded', $e->errorCode);
            $this->assertSame(503, $e->statusCode);
            $this->assertSame(2, $e->retryAfter);
            $this->assertTrue($e->retryable);
            $this->assertFalse($e->uncertain);
        }
    }

    public function test_malformed_response_throws_uncertain_invalid_response(): void
    {
        Http::fake(['*/v1/generations' => Http::response(['unexpected' => true], 202)]);

        try {
            app(PixelforgeClient::class)->createGeneration(['prompt' => 'test'], 'key-1');
            $this->fail('Expected PixelforgeApiException was not thrown.');
        } catch (PixelforgeApiException $e) {
            $this->assertSame('invalid_response', $e->errorCode);
            $this->assertTrue($e->retryable);
            $this->assertTrue($e->uncertain);
        }
    }
}
