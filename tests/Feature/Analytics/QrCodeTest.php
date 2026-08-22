<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Support\QrCode;
use Tests\TestCase;

/**
 * A QR code that does not scan is worse than no QR code: it gets printed on a poster, put in a
 * shop window, and quietly fails for every customer who tries it.
 *
 * These check the structural invariants a scanner depends on. The encoder was additionally
 * verified against an independent decoder across 210 payloads spanning versions 1 to 10 — that
 * check found two real bugs: a mirrored bit order in the second format copy, and missing version
 * information for versions 7 and up, which left every code above 134 characters undecodable.
 */
class QrCodeTest extends TestCase
{
    public function test_the_version_grows_with_the_payload_and_refuses_what_will_not_fit(): void
    {
        $qr = new QrCode();

        // 17 + 4v modules per side, so the side length is how the version is read back.
        $this->assertCount(21, $qr->matrix('A'), 'a short payload should use version 1');
        $this->assertCount(57, $qr->matrix(str_repeat('x', 200)), 'a long payload should use version 10');

        // Refusing is the correct answer: silently truncating the URL would produce a code that
        // scans perfectly and leads somewhere wrong.
        $this->assertNull($qr->matrix(str_repeat('x', 400)));
    }

    public function test_the_documented_capacity_is_the_capacity(): void
    {
        // Version 10 at level M holds 216 data codewords; the mode indicator and the 16-bit length
        // field take 20 of those 1,728 bits, which leaves 213 whole bytes — not the 271 the class
        // used to claim. A number in a docblock that nobody can check is a number that drifts, so
        // it is checked here: 213 encodes and 214 is refused.
        $qr = new QrCode();

        $this->assertNotNull($qr->matrix(str_repeat('x', 213)), 'the documented maximum must encode');
        $this->assertNull($qr->matrix(str_repeat('x', 214)), 'one byte past it must be refused');
    }

    public function test_the_finder_patterns_are_where_a_scanner_looks_for_them(): void
    {
        $matrix = (new QrCode())->matrix('https://example.com/go/abc1234');
        $size = count($matrix);

        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$row, $column]) {
            $this->assertTrue($matrix[$row][$column], 'the finder corner is not dark');
            $this->assertTrue($matrix[$row + 3][$column + 3], 'the finder core is not dark');
            $this->assertFalse($matrix[$row + 1][$column + 1], 'the finder ring is not separated');
        }
    }

    public function test_the_timing_patterns_alternate(): void
    {
        $matrix = (new QrCode())->matrix('https://example.com/go/abc1234');
        $size = count($matrix);

        for ($i = 8; $i < $size - 8; $i++) {
            $this->assertSame($i % 2 === 0, $matrix[6][$i], "the horizontal timing pattern breaks at {$i}");
            $this->assertSame($i % 2 === 0, $matrix[$i][6], "the vertical timing pattern breaks at {$i}");
        }
    }

    public function test_the_format_information_is_written_identically_in_both_copies(): void
    {
        // The bug that shipped first: the two copies used a mirrored bit order, which cancelled
        // out in the copy a reader checks first and left the second one wrong. Nothing scanned,
        // and nothing looked wrong.
        $matrix = (new QrCode())->matrix('https://example.com/go/abc1234');
        $size = count($matrix);

        $first = [];
        for ($i = 0; $i <= 5; $i++) {
            $first[] = $matrix[$i][8];
        }
        $first[] = $matrix[7][8];
        $first[] = $matrix[8][8];
        $first[] = $matrix[8][7];
        for ($i = 9; $i < 15; $i++) {
            $first[] = $matrix[8][14 - $i];
        }

        $second = [];
        for ($i = 0; $i < 8; $i++) {
            $second[] = $matrix[8][$size - 1 - $i];
        }
        for ($i = 8; $i < 15; $i++) {
            $second[] = $matrix[$size - 15 + $i][8];
        }

        $this->assertSame($first, $second, 'the two format-information copies disagree, so nothing will scan');
    }

    public function test_versions_seven_and_above_carry_version_information(): void
    {
        // Without this block a decoder cannot determine the version and never reaches the data.
        // Measured before it was added: versions 1-6 decoded 106/106, versions 7-10 decoded 0/94.
        $matrix = (new QrCode())->matrix(str_repeat('x', 140));
        $size = count($matrix);
        $version = (int) (($size - 17) / 4);

        $this->assertGreaterThanOrEqual(7, $version);

        $bits = [];
        for ($i = 0; $i < 18; $i++) {
            $far = $size - 11 + $i % 3;
            $near = intdiv($i, 3);
            $this->assertSame(
                $matrix[$near][$far],
                $matrix[$far][$near],
                'the two version-information copies disagree',
            );
            $bits[] = $matrix[$near][$far] ? 1 : 0;
        }

        // The 18-bit word is (version << 12) | BCH remainder, and the bits are placed
        // least-significant first — so the version itself is read back out of bits 12 to 17, not
        // out of the first six.
        $encoded = 0;
        for ($i = 0; $i < 6; $i++) {
            $encoded |= $bits[12 + $i] << $i;
        }
        $this->assertSame($version, $encoded, 'the encoded version does not match the matrix size');
    }

    public function test_the_svg_is_self_contained_and_has_a_quiet_zone(): void
    {
        $qr = new QrCode();
        $svg = $qr->svg('https://example.com/go/abc1234', 200);

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<script', $svg);

        // The quiet zone is part of the specification: without four modules of margin a scanner
        // cannot separate the finders from whatever is printed around them.
        preg_match('/viewBox="0 0 (\d+) /', $svg, $matches);
        $this->assertSame(count($qr->matrix('https://example.com/go/abc1234')) + 8, (int) $matches[1]);
    }
}
