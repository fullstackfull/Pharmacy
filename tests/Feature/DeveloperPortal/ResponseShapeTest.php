<?php

namespace Tests\Feature\DeveloperPortal;

use App\Services\DeveloperPortal\ResponseShape;
use Tests\TestCase;

/**
 * What an endpoint answers with, described from what it answered with.
 *
 * The manifest derives everything else from the route table and the controllers. Responses are the
 * one thing it cannot reach — this API returns response()->json(...) directly nearly a thousand
 * times and has almost no Resource classes — so all 441 endpoints reported "no response schema".
 *
 * The rule that makes recording this safe is the one asserted hardest here: keys and types are
 * kept, values never are. An endpoint that answers with a token is describable precisely because
 * the token itself never enters the description.
 */
class ResponseShapeTest extends TestCase
{
    private function shape(): ResponseShape
    {
        return new ResponseShape();
    }

    public function test_it_keeps_the_keys_and_discards_every_value(): void
    {
        $shape = $this->shape()->of([
            'token' => 'eyJhbGciOiJIUzI1NiJ9.secret-payload',
            'customer' => ['id' => 41, 'email' => 'someone@example.com', 'phone' => '+963900000000'],
            'balance' => 12.5,
        ]);

        $encoded = (string) json_encode($shape);

        $this->assertStringContainsString('token', $encoded, 'the key is what documentation is for');
        $this->assertStringNotContainsString('secret-payload', $encoded);
        $this->assertStringNotContainsString('someone@example.com', $encoded);
        $this->assertStringNotContainsString('963900000000', $encoded);
        $this->assertStringNotContainsString('12.5', $encoded);

        $this->assertSame('string', $shape['properties']['token']['type']);
        $this->assertSame('integer', $shape['properties']['customer']['properties']['id']['type']);
        $this->assertSame('number', $shape['properties']['balance']['type']);
    }

    public function test_a_format_is_recognised_from_the_shape_not_the_content(): void
    {
        $shape = $this->shape()->of([
            'created_at' => '2026-08-22 09:15:00',
            'website' => 'https://shop.test/product/x',
            'email' => 'someone@example.com',
        ]);

        $this->assertSame('date-time', $shape['properties']['created_at']['format']);
        $this->assertSame('uri', $shape['properties']['website']['format']);
        $this->assertSame('email', $shape['properties']['email']['format']);
        $this->assertStringNotContainsString('shop.test', (string) json_encode($shape));
    }

    public function test_two_responses_are_merged_rather_than_the_newest_winning(): void
    {
        // One response with a field and one without describe the same endpoint. Taking only the
        // newest would make the documentation flap between deploys of nothing.
        $first = $this->shape()->of(['id' => 1, 'note' => 'a note']);
        $second = $this->shape()->of(['id' => 2]);

        $merged = $this->shape()->merge($first, $second);

        $this->assertArrayHasKey('note', $merged['properties']);
        $this->assertTrue($merged['properties']['note']['optional'], 'a field one response omitted is optional');
    }

    public function test_a_field_seen_as_two_types_says_so(): void
    {
        $merged = $this->shape()->merge(
            $this->shape()->of(['total' => 10]),
            $this->shape()->of(['total' => '10.00']),
        );

        $this->assertSame('mixed', $merged['properties']['total']['type']);
        $this->assertEqualsCanonicalizing(['integer', 'string'], $merged['properties']['total']['was']);
    }

    public function test_an_empty_list_does_not_invent_an_item_shape(): void
    {
        $shape = $this->shape()->of(['items' => []]);

        $this->assertSame('array', $shape['properties']['items']['type']);
        $this->assertArrayNotHasKey('items', $shape['properties']['items']);
        $this->assertTrue($shape['properties']['items']['observed_empty']);
    }

    public function test_a_list_is_described_from_its_items(): void
    {
        $shape = $this->shape()->of(['products' => [
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B', 'discount' => 5],
        ]]);

        $items = $shape['properties']['products']['items'];

        $this->assertSame('object', $items['type']);
        $this->assertSame('integer', $items['properties']['id']['type']);
        $this->assertTrue($items['properties']['discount']['optional']);
    }

    public function test_depth_is_bounded_so_one_response_cannot_be_a_stack_trace(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => 'bottom']]]]]]]];

        $encoded = (string) json_encode($this->shape()->of($deep));

        $this->assertStringContainsString('truncated', $encoded);
        $this->assertStringNotContainsString('bottom', $encoded);
    }
}
