<?php

namespace Tests\Feature\Platform;

use App\Support\Features;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FeatureFlagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/flagged', fn () => 'on')->middleware('feature:test_flag');
    }

    public function test_unknown_and_unset_flags_are_off()
    {
        $this->assertFalse(Features::enabled('test_flag'));
        $this->get('/_test/flagged')->assertNotFound();
    }

    public function test_only_a_true_flag_turns_a_feature_on()
    {
        config(['features.test_flag' => 'yes']);
        $this->assertFalse(Features::enabled('test_flag'));

        config(['features.test_flag' => true]);
        $this->assertTrue(Features::enabled('test_flag'));
        $this->get('/_test/flagged')->assertOk()->assertSee('on');
    }
}
