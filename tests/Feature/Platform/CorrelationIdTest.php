<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorrelationIdTest extends TestCase
{
    public function test_every_response_gets_a_generated_correlation_id()
    {
        $id = $this->get('/health')->headers->get('X-Correlation-ID');

        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($id, Context::get('correlation_id'));
    }

    public function test_a_valid_incoming_correlation_id_is_kept()
    {
        $id = (string) Str::uuid();

        $this->get('/health', ['X-Correlation-ID' => $id])
            ->assertHeader('X-Correlation-ID', $id);
    }

    public function test_an_invalid_incoming_correlation_id_is_replaced()
    {
        $sent = "evil\nINFO forged log line";

        $id = $this->get('/health', ['X-Correlation-ID' => $sent])->headers->get('X-Correlation-ID');

        $this->assertNotSame($sent, $id);
        $this->assertTrue(Str::isUuid($id));
    }
}
