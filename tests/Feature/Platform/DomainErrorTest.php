<?php

namespace Tests\Feature\Platform;

use App\Domain\Shared\DomainError;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DomainErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/domain-error', function () {
            throw new class('Snapshot was rejected.') extends DomainError
            {
                public function errorCode(): string
                {
                    return 'snapshot.rejected';
                }
            };
        });
    }

    public function test_json_clients_get_a_typed_error_with_the_correlation_id()
    {
        $response = $this->getJson('/_test/domain-error')->assertStatus(422);

        $response->assertExactJson(['error' => [
            'code' => 'snapshot.rejected',
            'message' => 'Snapshot was rejected.',
            'correlation_id' => $response->headers->get('X-Correlation-ID'),
        ]]);
    }

    public function test_browser_requests_go_back_with_an_error_toast()
    {
        $this->from('/dashboard')->get('/_test/domain-error')
            ->assertRedirect('/dashboard');
    }
}
