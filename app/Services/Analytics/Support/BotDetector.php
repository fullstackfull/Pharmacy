<?php

namespace App\Services\Analytics\Support;

use Illuminate\Http\Request;

/**
 * Is this a person?
 *
 * This exists because of a measured problem, not a theoretical one: of 367 recorded sessions on
 * this store, 133 were crawlers — the visitor count on the old Analytics page was inflated by more
 * than a third, and every conversion rate computed from it was correspondingly wrong.
 *
 * Detection is deliberately conservative in one direction: a real customer must never be
 * classified as a bot, because that silently deletes them from every report. A crawler that slips
 * through inflates a number; a customer wrongly excluded loses a sale nobody can see.
 */
class BotDetector
{
    /**
     * Declared crawlers. Nearly all of them say so in the user agent — the well-behaved ones
     * because they want to be identified, and the lazy ones because they never changed the default.
     */
    private const AGENT_SIGNATURES = [
        'bot', 'crawl', 'spider', 'slurp', 'search', 'scrape', 'archiver',
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'twitterbot', 'linkedinbot',
        'pinterest', 'discordbot', 'slackbot', 'embedly', 'quora link preview',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        'curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client',
        'java/', 'okhttp', 'apache-httpclient', 'httpclient', 'libwww-perl', 'guzzlehttp',
        'postman', 'insomnia', 'axios/', 'node-fetch', 'lighthouse', 'pagespeed',
        'uptimerobot', 'pingdom', 'statuscake', 'newrelicpinger', 'datadog', 'ahrefs',
        'semrush', 'mj12bot', 'dotbot', 'petalbot', 'bytespider', 'gptbot', 'ccbot',
        'claudebot', 'perplexitybot', 'applebot', 'amazonbot', 'yandex', 'baiduspider',
    ];

    /** Our own probes, which must never appear anywhere in a customer report. */
    private const OWN_AGENTS = ['pharmacymonitoring'];

    public function isBot(Request $request): bool
    {
        $agent = strtolower(trim((string) $request->userAgent()));

        // No user agent at all is not a browser. Every real one sends something.
        if ($agent === '') {
            return true;
        }

        foreach (array_merge(self::AGENT_SIGNATURES, self::OWN_AGENTS) as $signature) {
            if (str_contains($agent, $signature)) {
                return true;
            }
        }

        // A browser that asks for a page and accepts nothing is not rendering it.
        if ($request->isMethod('GET') && trim((string) $request->headers->get('Accept')) === '') {
            return true;
        }

        return false;
    }

    /**
     * Staff, and anyone browsing from the shop's own network.
     *
     * Counting the merchant's own eleven visits a day as customer traffic is how a small shop's
     * conversion rate ends up looking like a rounding error.
     */
    public function isInternal(Request $request): bool
    {
        if (auth('admin')->check() || auth('seller')->check()) {
            return true;
        }

        $address = (string) $request->ip();

        foreach ((array) config('analytics.internal_ips', []) as $range) {
            if ($this->matches($address, trim((string) $range))) {
                return true;
            }
        }

        return false;
    }

    /** The device class this request came from — bot included, because that is a real answer. */
    public function device(Request $request): string
    {
        if ($this->isBot($request)) {
            return 'bot';
        }

        $agent = strtolower((string) $request->userAgent());

        if (str_contains($agent, 'ipad') || (str_contains($agent, 'android') && !str_contains($agent, 'mobile'))) {
            return 'tablet';
        }

        return preg_match('/mobile|iphone|ipod|android|windows phone|opera mini/', $agent) === 1 ? 'mobile' : 'desktop';
    }

    private function matches(string $address, string $range): bool
    {
        if ($range === '' || $address === '') {
            return false;
        }

        if (!str_contains($range, '/')) {
            return $address === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $addressBinary = @inet_pton($address);
        $subnetBinary = @inet_pton($subnet);

        if ($addressBinary === false || $subnetBinary === false || strlen($addressBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if (strncmp($addressBinary, $subnetBinary, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($addressBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }
}
