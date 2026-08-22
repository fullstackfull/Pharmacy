<?php

namespace App\Services\Analytics\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where did this visit come from?
 *
 * Attribution is the part of analytics most often quietly wrong, because there is no single right
 * answer and every tool picks one silently. This one answers three separate questions and keeps
 * them separate:
 *
 *   FIRST TOUCH   — how this person ever found the shop. Stored on the visitor, written once.
 *   SESSION TOUCH — what brought them back this time. Stored on the session.
 *   LAST TOUCH    — whatever the converting session says, which is the session touch of the visit
 *                   that ordered. No extra storage; it falls out of the model.
 *
 * Collapsing those into "the source" is how an email campaign gets credited with a sale that an
 * advertisement started and a search brought back — and the reason a merchant's own numbers never
 * match the ad platform's.
 *
 * Every decision records WHY, in attribution_basis, so a figure on a screen can always be
 * explained rather than trusted.
 */
class AttributionEngine
{
    /** Search engines, so organic search is not filed as an ordinary referral. */
    private const SEARCH_DOMAINS = [
        'google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo', 'duckduckgo' => 'duckduckgo',
        'yandex' => 'yandex', 'baidu' => 'baidu', 'ecosia' => 'ecosia', 'brave' => 'brave',
        'startpage' => 'startpage', 'qwant' => 'qwant',
    ];

    /** Social networks, so a share is not filed as a website linking to us. */
    private const SOCIAL_DOMAINS = [
        'facebook' => 'facebook', 'fb.com' => 'facebook', 'instagram' => 'instagram',
        'twitter' => 'twitter', 't.co' => 'twitter', 'x.com' => 'twitter',
        'linkedin' => 'linkedin', 'lnkd.in' => 'linkedin', 'pinterest' => 'pinterest',
        'tiktok' => 'tiktok', 'youtube' => 'youtube', 'youtu.be' => 'youtube',
        'reddit' => 'reddit', 'snapchat' => 'snapchat', 'telegram' => 'telegram',
        't.me' => 'telegram', 'whatsapp' => 'whatsapp', 'wa.me' => 'whatsapp',
    ];

    /** Mail clients and webmail, which arrive as referrals but are email traffic. */
    private const EMAIL_DOMAINS = ['mail.google', 'outlook', 'mail.yahoo', 'webmail', 'zoho.com/mail'];

    /**
     * Work out the session touch for a request.
     *
     * @param  array<string, mixed>|null  $campaign  the short link this visit arrived through
     * @return array<string, string|null>
     */
    public function resolve(Request $request, ?array $campaign = null): array
    {
        // 1. A short link we issued. The most trustworthy signal there is, because we created it.
        if ($campaign !== null) {
            return [
                'source' => $this->clean($campaign['utm_source'] ?? null, 96),
                'medium' => $this->clean($campaign['utm_medium'] ?? null, 64),
                'campaign' => $this->clean($campaign['utm_campaign'] ?? null, 96),
                'content' => $this->clean($campaign['utm_content'] ?? null, 96),
                'term' => $this->clean($campaign['utm_term'] ?? null, 96),
                'referrer_domain' => $this->referrerDomain($request),
                'campaign_id' => $campaign['id'] ?? null,
                'attribution_basis' => 'campaign_link',
            ];
        }

        // 2. UTM parameters on the URL. Explicit, and set by whoever built the link.
        $utmSource = $this->clean($request->query('utm_source'), 96);
        if ($utmSource !== null) {
            return [
                'source' => $utmSource,
                'medium' => $this->clean($request->query('utm_medium'), 64) ?? 'unknown',
                'campaign' => $this->clean($request->query('utm_campaign'), 96),
                'content' => $this->clean($request->query('utm_content'), 96),
                'term' => $this->clean($request->query('utm_term'), 96),
                'referrer_domain' => $this->referrerDomain($request),
                'campaign_id' => null,
                'attribution_basis' => 'utm',
            ];
        }

        // 3. Advertising click identifiers, which arrive without UTMs more often than not.
        foreach (['gclid' => 'google', 'gbraid' => 'google', 'wbraid' => 'google', 'fbclid' => 'facebook', 'msclkid' => 'bing', 'ttclid' => 'tiktok'] as $parameter => $source) {
            if ($request->query($parameter)) {
                return [
                    'source' => $source,
                    'medium' => 'cpc',
                    'campaign' => null,
                    'content' => null,
                    'term' => null,
                    'referrer_domain' => $this->referrerDomain($request),
                    'campaign_id' => null,
                    'attribution_basis' => 'click_id',
                ];
            }
        }

        // 4. The referrer, classified.
        $referrer = $this->referrerDomain($request);
        if ($referrer !== null) {
            [$source, $medium] = $this->classify($referrer);

            return [
                'source' => $source,
                'medium' => $medium,
                'campaign' => null,
                'content' => null,
                'term' => null,
                'referrer_domain' => $referrer,
                'campaign_id' => null,
                'attribution_basis' => 'referrer',
            ];
        }

        // 5. Nothing to go on. Called (direct) rather than "unknown" on purpose: it is a real
        //    category — typed, bookmarked, or a referrer the browser withheld — and pretending
        //    otherwise is how "direct" quietly becomes the biggest channel in every report.
        return [
            'source' => '(direct)',
            'medium' => '(none)',
            'campaign' => null,
            'content' => null,
            'term' => null,
            'referrer_domain' => null,
            'campaign_id' => null,
            'attribution_basis' => 'direct',
        ];
    }

    /**
     * The referring host, or null when there is none worth recording.
     *
     * A referrer pointing at this same shop is internal navigation, not a traffic source — the
     * single most common way a self-hosted analytics table ends up saying its own domain is its
     * biggest referrer.
     */
    public function referrerDomain(Request $request): ?string
    {
        $referrer = (string) $request->headers->get('referer');

        if ($referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $host));
        $ownHost = strtolower(preg_replace('/^www\./', '', (string) $request->getHost()));

        return $host === $ownHost ? null : Str::limit($host, 191, '');
    }

    /**
     * A referring domain, turned into a source and a medium.
     *
     * @return array{0: string, 1: string}
     */
    public function classify(string $domain): array
    {
        foreach (self::SEARCH_DOMAINS as $needle => $source) {
            if (str_contains($domain, $needle)) {
                return [$source, 'organic'];
            }
        }

        foreach (self::SOCIAL_DOMAINS as $needle => $source) {
            if (str_contains($domain, $needle)) {
                return [$source, 'social'];
            }
        }

        foreach (self::EMAIL_DOMAINS as $needle) {
            if (str_contains($domain, $needle)) {
                return [$domain, 'email'];
            }
        }

        return [$domain, 'referral'];
    }

    private function clean(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        // UTM values arrive from wherever somebody pasted a link, so they are treated as untrusted
        // text: stripped to a safe character set before they can become a row in a report that an
        // administrator reads.
        $value = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s._\-\+\|]/u', '', $value) ?? ''));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
