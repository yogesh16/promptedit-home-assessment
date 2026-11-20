<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\FakesPixelforge;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
            'services.pixelforge.api_key' => 'pf_test_key',
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/reports')->assertRedirect('/login');
    }

    public function test_non_admins_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/reports')
            ->assertForbidden();
    }

    public function test_daily_and_per_user_reports_use_exact_decimal_totals(): void
    {
        [$first, $second] = $this->seedSucceededGenerations();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/reports?start=2026-11-01&end=2026-11-02')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/Reports')
                    ->where('filters.start', '2026-11-01')
                    ->where('filters.end', '2026-11-02')
                    ->where('daily.days.0.date', '2026-11-01')
                    ->where('daily.days.0.generations', 2)
                    ->where('daily.days.0.provider_cost', '0.219830000')
                    ->where('daily.days.0.customer_charge', '0.274787500')
                    ->where('daily.days.0.profit', '0.054957500')
                    ->where('daily.days.1.date', '2026-11-02')
                    ->where('daily.days.1.generations', 0)
                    ->where('daily.totals.provider_cost', '0.219830000')
                    ->where('daily.totals.customer_charge', '0.274787500')
                    ->where('daily.totals.profit', '0.054957500')
                    ->has('perUser.users', 2)
                    ->where('perUser.users.0.user_id', $second->user_id)
                    ->where('perUser.users.0.customer_charge', '0.211350000')
                    ->where('perUser.users.1.user_id', $first->user_id)
                    ->where('perUser.totals.profit', '0.054957500')
            );
    }

    public function test_report_excludes_failed_and_processing_generations(): void
    {
        [$user] = [$this->seedSucceededGenerations()[0]->user];

        Generation::factory()->for($user)->create([
            'status' => Generation::STATUS_PROCESSING,
            'provider_cost' => '5.000000000',
            'customer_charge' => '6.000000000',
            'completed_at' => null,
        ]);

        Generation::factory()->for($user)->failed()->create([
            'provider_cost' => '5.000000000',
            'customer_charge' => '6.000000000',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/reports?start=2026-11-01&end=2026-11-02')
            ->assertInertia(
                fn ($page) => $page
                    ->where('daily.totals.generations', 2)
                    ->where('daily.totals.provider_cost', '0.219830000')
                    ->where('daily.totals.customer_charge', '0.274787500')
            );
    }

    public function test_reconciliation_is_run_on_demand_and_matches_internal_records(): void
    {
        $this->seedSucceededGenerations();

        Http::fake([
            '*/v1/usage*' => Http::response($this->usagePayload()),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/reports?start=2026-11-01&end=2026-11-02&reconcile=1')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('reconciliation.matches', true)
                    ->where('reconciliation.provider_total', '0.219830000')
                    ->where('reconciliation.internal_total', '0.219830000')
                    ->where('reconciliation.total_difference', '0.000000000')
                    ->where('reconciliationError', null)
            );
    }

    public function test_reconciliation_provider_failure_is_reported_without_breaking_the_page(): void
    {
        $this->seedSucceededGenerations();

        Http::fake([
            '*/v1/usage*' => Http::response([
                'error' => ['code' => 'invalid_api_key', 'message' => 'The API key is invalid.'],
            ], 401),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/reports?start=2026-11-01&end=2026-11-02&reconcile=1')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('reconciliation', null)
                    ->where('reconciliationError', 'Could not retrieve Pixelforge usage: The API key is invalid.')
            );
    }

    public function test_reports_are_not_requested_without_reconcile_flag(): void
    {
        $this->seedSucceededGenerations();

        Http::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/reports?start=2026-11-01&end=2026-11-02')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('reconciliation', null));

        Http::assertNothingSent();
    }

    public function test_invalid_date_ranges_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/reports?start=2026-11-03&end=2026-11-01')
            ->assertSessionHasErrors('start');

        $this->actingAs($admin)
            ->get('/admin/reports?start=2026-01-01&end=2026-04-03')
            ->assertSessionHasErrors('start');
    }

    /**
     * @return array{0: Generation, 1: Generation}
     */
    private function seedSucceededGenerations(): array
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $first = Generation::factory()->succeeded()->for($firstUser)->create([
            'provider_cost' => '0.050750000',
            'customer_charge' => '0.063437500',
            'completed_at' => CarbonImmutable::parse('2026-11-01T10:00:00Z'),
        ]);

        $second = Generation::factory()->succeeded()->for($secondUser)->create([
            'provider_cost' => '0.169080000',
            'customer_charge' => '0.211350000',
            'completed_at' => CarbonImmutable::parse('2026-11-01T18:00:00Z'),
        ]);

        return [$first, $second];
    }

    /**
     * @return array<string, mixed>
     */
    private function usagePayload(): array
    {
        return [
            'object' => 'usage',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-02',
            'data' => [
                [
                    'date' => '2026-11-01',
                    'generations' => 2,
                    'input_text_tokens' => 41,
                    'input_image_tokens' => 0,
                    'output_tokens' => 3300,
                    'reasoning_tokens' => 2950,
                    'cost_usd' => '0.219830000',
                ],
                [
                    'date' => '2026-11-02',
                    'generations' => 0,
                    'input_text_tokens' => 0,
                    'input_image_tokens' => 0,
                    'output_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'cost_usd' => '0.000000000',
                ],
            ],
            'total' => [
                'generations' => 2,
                'input_text_tokens' => 41,
                'input_image_tokens' => 0,
                'output_tokens' => 3300,
                'reasoning_tokens' => 2950,
                'cost_usd' => '0.219830000',
            ],
        ];
    }
}
