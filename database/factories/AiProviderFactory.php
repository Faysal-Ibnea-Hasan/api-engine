<?php

namespace Database\Factories;

use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProvider>
 */
class AiProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => fake()->unique()->randomElement(array_keys(AiProvider::PROVIDERS)),
            'api_key' => 'sk-test-'.fake()->bothify('????????'),
            'key_suffix' => fake()->bothify('????'),
            'key_version' => 1,
            'model' => 'test-model',
            'available_models' => [['id' => 'test-model', 'effort' => true]],
            'effort' => 'medium',
            'enabled' => true,
            'is_default' => false,
            'status' => 'ready',
        ];
    }
}
