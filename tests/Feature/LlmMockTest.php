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

    public function test_recommend_tin_purchasable_includes_purchase_action(): void
    {
        $response = $this->postJson('/llm_recommend/invoke', [
            'input' => [
                'products' => [
                    ['product_id' => 'tin', 'purchased' => false, 'able_to_purchase' => true],
                ],
                'session_id' => 'sess-1',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('output.session_id', 'sess-1');
        $response->assertJsonPath(
            'output.products.0.tin.recommendation_text',
            fn (string $text) => str_contains($text, 'Click the TIN column to purchase')
        );
        $response->assertJsonPath('output.products.0.tin.actions.0.action_type', 'PURCHASE');
    }

    public function test_recommend_tin_not_purchasable_has_no_actions(): void
    {
        $response = $this->postJson('/llm_recommend/invoke', [
            'input' => [
                'products' => [
                    ['product_id' => 'tin', 'purchased' => true, 'able_to_purchase' => true],
                ],
                'session_id' => null,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'output.products.0.tin.recommendation_text',
            fn (string $text) => str_contains($text, 'unavailable or already purchased')
        );
        $response->assertJsonPath('output.products.0.tin.actions', []);
    }

    public function test_recommend_bir_purchasable_includes_purchase_action(): void
    {
        $response = $this->postJson('/llm_recommend/invoke', [
            'input' => [
                'products' => [
                    ['product_id' => 'bir', 'purchased' => 'NO', 'able_to_purchase' => 'YES'],
                ],
                'session_id' => 'sess-2',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'output.products.0.bir.recommendation_text',
            fn (string $text) => str_contains($text, 'Purchase the full report')
        );
        $response->assertJsonPath('output.products.0.bir.actions.0.action_type', 'PURCHASE');
    }

    public function test_recommend_nearby_companies_with_details_includes_map_action(): void
    {
        $details = [['name' => 'Acme Sdn Bhd'], ['name' => 'Beta Enterprise']];

        $response = $this->postJson('/llm_recommend/invoke', [
            'input' => [
                'products' => [
                    ['product_id' => 'nearby_companies', 'purchased' => false, 'able_to_purchase' => true, 'details' => $details],
                ],
                'session_id' => 'sess-3',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'output.products.0.nearby_companies.recommendation_text',
            fn (string $text) => str_contains($text, 'Found 2 business(es)')
        );
        $response->assertJsonPath('output.products.0.nearby_companies.actions.0.action_type', 'MAP_COMPANY');
        $response->assertJsonPath('output.products.0.nearby_companies.actions.0.details', $details);
    }

    public function test_recommend_nearby_companies_with_no_details_has_no_actions(): void
    {
        $response = $this->postJson('/llm_recommend/invoke', [
            'input' => [
                'products' => [
                    ['product_id' => 'nearby_companies', 'purchased' => false, 'able_to_purchase' => true, 'details' => []],
                ],
                'session_id' => null,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('output.products.0.nearby_companies.actions', []);
    }

    public function test_recommend_unknown_product_id_has_generic_text_and_no_actions(): void
    {
        $response = $this->postJson('/llm_recommend/invoke', [
            'input' => [
                'products' => [
                    ['product_id' => 'ccris', 'purchased' => false, 'able_to_purchase' => true],
                ],
                'session_id' => null,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'output.products.0.ccris.recommendation_text',
            fn (string $text) => str_contains($text, 'No specific insight generated for "ccris"')
        );
        $response->assertJsonPath('output.products.0.ccris.actions', []);
    }

    public function test_recommend_handles_multiple_products_in_one_request(): void
    {
        $response = $this->postJson('/llm_recommend/invoke', [
            'input' => [
                'products' => [
                    ['product_id' => 'tin', 'purchased' => false, 'able_to_purchase' => true],
                    ['product_id' => 'bir', 'purchased' => false, 'able_to_purchase' => true],
                ],
                'session_id' => 'sess-4',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('output.products.0.tin.actions.0.action_type', 'PURCHASE');
        $response->assertJsonPath('output.products.1.bir.actions.0.action_type', 'PURCHASE');
    }
}
