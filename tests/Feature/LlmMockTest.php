<?php

namespace Tests\Feature;

use Tests\TestCase;

class LlmMockTest extends TestCase
{
    public static function modelPrefixProvider(): array
    {
        return [
            'claude' => ['claude'],
            'llama' => ['llama'],
            'llm' => ['llm'],
        ];
    }

    /** @dataProvider modelPrefixProvider */
    public function test_summarize_returns_static_output(string $model): void
    {
        $response = $this->postJson("/{$model}_summarize/invoke", [
            'input' => ['root' => 'some executive summary prompt'],
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'output',
            fn (string $output) => str_contains($output, 'MOCK SUMMARIZATION') && str_contains($output, '(infominaAI-ssm-mock)')
        );
    }

    /** @dataProvider modelPrefixProvider */
    public function test_search_returns_static_output(string $model): void
    {
        $response = $this->postJson("/{$model}_search/invoke", [
            'input' => ['query' => 'find company website and news'],
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'output',
            fn (string $output) => str_contains($output, 'MOCK SEARCH/BUSINESS INFORMATION') && str_contains($output, '(infominaAI-ssm-mock)')
        );
    }

    public function test_unrecognised_operation_returns_fallback_not_an_error(): void
    {
        $response = $this->postJson('/llm_unknown_op/invoke', ['input' => []]);

        $response->assertOk();
        $response->assertJsonPath(
            'output',
            fn (string $output) => str_contains($output, 'Unrecognised operation "llm_unknown_op"')
        );
    }

    public function test_no_auth_header_required(): void
    {
        $response = $this->postJson('/llm_summarize/invoke', ['input' => ['root' => 'x']]);

        $response->assertOk();
    }
}
