<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Metric;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Monitoring reads live production traffic, so the redactor is the single thing standing between
 * a shopper's card number and a telemetry table. These are the cases that actually leaked during
 * development, kept as tests so they cannot leak again.
 */
class RedactorTest extends TestCase
{
    private Redactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = Redactor::make();
    }

    public function test_a_bearer_token_is_removed_and_not_merely_relabelled(): void
    {
        // The first version of this masked the word "Bearer" and left the token in the clear.
        $clean = $this->redactor->text('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abcdefghijklmnop');

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $clean);
        $this->assertStringContainsString(Redactor::MASK, $clean);
    }

    public function test_a_quoted_json_secret_is_masked(): void
    {
        $clean = $this->redactor->text('{"access_token":"sk_live_abcdef123456","amount":25}');

        $this->assertStringNotContainsString('sk_live_abcdef123456', $clean);
        // The non-secret sibling survives, or the log line becomes useless.
        $this->assertStringContainsString('"amount":25', $clean);
    }

    public function test_a_header_spelling_of_a_secret_key_is_recognised(): void
    {
        // api_key on the list, X-Api-Key on the wire.
        $this->assertStringNotContainsString('abc123def456ghi', $this->redactor->text('X-Api-Key: abc123def456ghi'));
    }

    public function test_secrets_are_masked_at_every_depth_of_a_payload(): void
    {
        $clean = $this->redactor->array([
            'email' => 'shopper@example.com',
            'password' => 'hunter2',
            'password_confirmation' => 'hunter2',
            'meta' => ['nested' => ['refresh_token' => 'rt_live_x', 'quantity' => 3]],
        ]);

        $this->assertSame('shopper@example.com', $clean['email']);
        $this->assertSame(Redactor::MASK, $clean['password']);
        $this->assertSame(Redactor::MASK, $clean['password_confirmation']);
        $this->assertSame(Redactor::MASK, $clean['meta']['nested']['refresh_token']);
        $this->assertSame(3, $clean['meta']['nested']['quantity']);
    }

    public function test_a_card_number_keeps_only_its_last_four_and_the_sentence_around_it(): void
    {
        $clean = $this->redactor->text('charge on 4111 1111 1111 1111 declined');

        $this->assertStringNotContainsString('4111 1111 1111 1111', $clean);
        $this->assertStringContainsString('****1111', $clean);
        // An early version ate the following space and glued the sentence together.
        $this->assertStringContainsString(' declined', $clean);
    }

    public function test_ordinary_numbers_are_not_mistaken_for_cards(): void
    {
        $this->assertSame('order 12345 total 99 qty 3', $this->redactor->text('order 12345 total 99 qty 3'));
        $this->assertSame('phone 0912345678 order 5', $this->redactor->text('phone 0912345678 order 5'));
    }

    public function test_a_message_with_no_secrets_is_left_exactly_as_it_was(): void
    {
        $message = 'SQLSTATE[42S02]: Base table or view not found: products_backup';

        $this->assertSame($message, $this->redactor->text($message));
    }

    public function test_authorization_and_cookie_headers_are_dropped_entirely(): void
    {
        $headers = $this->redactor->headers([
            'Authorization' => 'Bearer abc',
            'Cookie' => 'session=1',
            'User-Agent' => 'Mozilla/5.0',
            'X-App-Version' => '5.3.0',
        ]);

        $this->assertArrayNotHasKey('authorization', $headers);
        $this->assertArrayNotHasKey('cookie', $headers);
        $this->assertSame('Mozilla/5.0', $headers['user-agent']);
        $this->assertSame('5.3.0', $headers['x-app-version']);
    }

    public function test_a_url_keeps_its_shape_but_not_its_secrets(): void
    {
        $clean = $this->redactor->url('https://api.gateway.test/pay?order=55&api_key=sk_live_9999&amount=10');

        $this->assertStringContainsString('https://api.gateway.test/pay', $clean);
        $this->assertStringContainsString('order=55', $clean);
        $this->assertStringNotContainsString('sk_live_9999', $clean);
    }

    public function test_sql_is_normalised_into_a_fingerprint_without_customer_data(): void
    {
        $fingerprint = $this->redactor->sql("select * from products where name = 'Panadol Extra' and id in (1,2,3) limit 10");

        $this->assertStringNotContainsString('Panadol', $fingerprint);
        $this->assertSame('select * from products where name = ? and id IN (?) limit ?', $fingerprint);
    }

    public function test_the_same_query_with_different_values_produces_one_fingerprint(): void
    {
        // This is what makes "this query ran 40,000 times" answerable.
        $this->assertSame(
            $this->redactor->sql("select * from orders where customer_id = 12 and status = 'pending'"),
            $this->redactor->sql("select * from orders where customer_id = 9987 and status = 'delivered'"),
        );
    }

    public function test_an_ip_is_masked_to_its_network_by_default(): void
    {
        config()->set('monitoring.privacy.mask_ip', true);

        $this->assertSame('192.168.11.0', $this->redactor->ip('192.168.11.55'));
        $this->assertSame('2001:db8:85a3:1::', $this->redactor->ip('2001:db8:85a3:1:2:3:4:5'));
        $this->assertNull($this->redactor->ip(null));
    }

    public function test_a_compressed_ipv6_address_is_actually_masked(): void
    {
        // The mask used to split the TEXT on ':' and keep the first four groups, which is only ever
        // right for a fully expanded address. 2001:db8::1 became "2001:db8::1::" — not an address,
        // and still carrying the interface identifier the mask exists to remove.
        config()->set('monitoring.privacy.mask_ip', true);

        $this->assertSame('2001:db8::', $this->redactor->ip('2001:db8::1'));
        $this->assertSame('::', $this->redactor->ip('::1'));
        $this->assertSame('fe80::', $this->redactor->ip('fe80::abcd'));
    }

    public function test_one_address_written_two_ways_masks_to_one_value(): void
    {
        // The reason this is a counting bug and not only a formatting one: these are the same
        // address, and anything that groups by the masked value used to see two.
        config()->set('monitoring.privacy.mask_ip', true);

        $this->assertSame(
            $this->redactor->ip('2001:0db8:0000:0000:0000:0000:0000:0001'),
            $this->redactor->ip('2001:db8::1'),
        );
    }

    public function test_something_that_is_not_an_address_is_not_recorded_as_one(): void
    {
        config()->set('monitoring.privacy.mask_ip', true);

        $this->assertNull($this->redactor->ip('nonsense'));
        $this->assertNull($this->redactor->ip('192.168.11.55.9'));
    }

    public function test_ip_masking_can_be_switched_off_deliberately(): void
    {
        config()->set('monitoring.privacy.mask_ip', false);

        $this->assertSame('192.168.11.55', $this->redactor->ip('192.168.11.55'));
    }
    public function test_a_failed_probe_never_prints_the_query_it_failed_on(): void
    {
        // Laravel appends the statement WITH ITS BOUND VALUES after " (Connection: ", so the raw
        // message of a QueryException carries whatever the customer typed. Sixty-two places in
        // monitoring report a failure, and every one of them goes through this.
        $note = Metric::describeFailure(new QueryException(
            'mysql',
            'select * from `users` where `email` = ? and `api_token` = ?',
            ['shopper@example.com', 'sk_live_7f3a9b'],
            new \RuntimeException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x'"),
        ));

        $this->assertStringNotContainsString('shopper@example.com', $note);
        $this->assertStringNotContainsString('sk_live_7f3a9b', $note);
        $this->assertStringContainsString('QueryException', $note);
        $this->assertStringContainsString('Unknown column', $note, 'the half an operator can act on must survive');
    }

    public function test_a_failure_note_is_bounded_so_a_card_stays_a_card(): void
    {
        $note = Metric::describeFailure(new \RuntimeException(str_repeat('long ', 400)));

        $this->assertLessThan(220, mb_strlen($note));
    }

    public function test_a_secret_in_any_exception_message_is_masked(): void
    {
        $note = Metric::describeFailure(new \RuntimeException('Refused: Authorization: Bearer sk_live_7f3a9b0c2d'));

        $this->assertStringNotContainsString('sk_live_7f3a9b0c2d', $note);
    }
}
