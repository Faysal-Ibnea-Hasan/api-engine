<?php

namespace Tests\Feature\Analysis;

use App\Domain\Analysis\ClassIndex;
use App\Domain\Analysis\Shape\Examples;
use App\Domain\Analysis\Shape\Interpreter;
use App\Domain\Analysis\Shape\Pipeline;
use App\Domain\Analysis\Shape\Shape;
use App\Domain\Sources\SnapshotReader;
use App\Models\SourceSnapshot;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

/**
 * Laravel's own JSON rendering of framework exceptions (APP_DEBUG off) must match what the
 * analyzer writes for apps that don't render them themselves.
 */
class FrameworkRenderingParityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{Throwable, array<string, mixed>}> */
    public static function exceptions(): array
    {
        return [
            'unauthenticated' => [new AuthenticationException, ['k' => 'exception', 'class' => 'Illuminate\Auth\AuthenticationException', 'status' => 401, 'message' => Shape::literal('Unauthenticated.')]],
            'throttled' => [new ThrottleRequestsException('Too Many Attempts.'), ['k' => 'exception', 'class' => 'Illuminate\Http\Exceptions\ThrottleRequestsException', 'status' => 429, 'message' => Shape::literal('Too Many Attempts.')]],
            'maintenance' => [new HttpException(503, 'Service Unavailable'), ['k' => 'exception', 'class' => 'Symfony\Component\HttpKernel\Exception\HttpException', 'status' => 503, 'message' => Shape::literal('Service Unavailable')]],
            'server error' => [new \RuntimeException('secret detail'), ['k' => 'exception', 'class' => 'Exception', 'status' => null, 'message' => Shape::string()]],
        ];
    }

    #[DataProvider('exceptions')]
    public function test_default_rendering_matches_the_installed_framework(Throwable $exception, array $shape): void
    {
        config(['app.debug' => false]);
        $request = Request::create('/api/anything', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $real = app(ExceptionHandler::class)->render($request, $exception);

        $snapshot = SourceSnapshot::factory()->create();
        $reader = app(SnapshotReader::class);
        $pipeline = new Pipeline(new Interpreter($index = new ClassIndex($reader, $snapshot, '.'), $reader, $snapshot, '.'), $index, $reader, $snapshot, '.');
        $static = $pipeline->defaultRender($shape);

        $this->assertSame($real->getStatusCode(), Shape::known($static['status'])[1]);
        $example = Examples::of($static['body'])['example'];
        $this->assertSame(array_keys($real->getData(true)), array_keys($example));
        foreach (Examples::of($static['body'])['notes'] as $note) {
            if ($note['source'] === 'exact') {
                $this->assertSame(data_get($real->getData(true), ltrim(substr($note['path'], 1), '.')), data_get($example, ltrim(substr($note['path'], 1), '.')), $note['path']);
            }
        }
    }

    public function test_model_not_found_is_a_404_with_a_message(): void
    {
        config(['app.debug' => false]);
        $real = app(ExceptionHandler::class)->render(Request::create('/api/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']), (new ModelNotFoundException)->setModel('App\Models\User', [5]));

        $this->assertSame(404, $real->getStatusCode());
        $this->assertSame(['message'], array_keys($real->getData(true)));
    }
}
