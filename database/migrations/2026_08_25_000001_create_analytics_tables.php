<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The analytics store.
 *
 * The existing telemetry tables answer "how much traffic and how fast" — they are about requests.
 * This is about behaviour: what a person did, in what order, on which visit, and whether it led to
 * a sale. Those are different questions and a request log cannot answer them, which is why nearly
 * every section of the old Analytics page was empty.
 *
 * Four ideas hold the shape together:
 *
 *  1. ONE EVENT TABLE. Every interesting act — a product viewed, a search run, a cart added to, a
 *     checkout step reached, a payment attempted, an order placed — is one row with the same
 *     columns. Funnels, journeys, retention and segments are then all queries over one table
 *     instead of six separate half-built features.
 *
 *  2. THE SESSION IS THE UNIT OF ATTRIBUTION. A visitor arrives from somewhere and that somewhere
 *     belongs to the visit, not to every event inside it. First touch is stored on the visitor
 *     (how they ever found the shop), session touch on the session (what brought them back today),
 *     and last touch is whatever the converting session says — three answers to three different
 *     questions, all measured, never one number pretending to be all three.
 *
 *  3. FLAGGED, NOT DELETED. Bot and staff traffic is recorded with a flag. Dropping it would make
 *     the numbers unauditable — nobody could ever check how much was filtered — and would make
 *     "we have no bot traffic" indistinguishable from "we stopped looking".
 *
 *  4. BOUNDED. Raw events are retained for months, not forever; rollups are cheap and kept for
 *     years; every dimension is capped so one bad URL cannot grow the table without limit.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('analytics.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        /*
        | Visitors: one row per browser that has ever been here, identified by a first-party
        | cookie the visitor can clear. No fingerprinting — the id is random and stored, not
        | derived from their device.
        */
        if (!$schema->hasTable('analytics_visitors')) {
            $schema->create('analytics_visitors', function (Blueprint $table) {
                $table->id();
                $table->string('visitor_id', 64)->unique();

                // Who they turned out to be, once they logged in. Set on identification and kept:
                // this is what makes it possible to say a customer's FIRST visit came from an ad
                // they clicked three weeks before they ever created an account.
                $table->string('user_type', 16)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('identified_at')->nullable();

                // First touch: how this person ever found the shop. Written once, never updated.
                $table->string('first_source', 96)->nullable();
                $table->string('first_medium', 64)->nullable();
                $table->string('first_campaign', 96)->nullable();
                $table->string('first_referrer', 191)->nullable();
                $table->string('first_landing_path', 191)->nullable();

                $table->unsignedInteger('sessions')->default(0);
                $table->unsignedInteger('events')->default(0);
                $table->unsignedInteger('orders')->default(0);
                $table->decimal('revenue', 24, 2)->default(0);

                $table->boolean('is_bot')->default(false);
                $table->boolean('is_internal')->default(false);
                $table->string('country', 2)->nullable();

                $table->timestamp('first_seen_at')->index();
                $table->timestamp('last_seen_at')->index();

                $table->index(['user_type', 'user_id'], 'analytics_visitor_user');
                $table->index(['is_bot', 'is_internal'], 'analytics_visitor_real');
            });
        }

        /*
        | Sessions: one visit. Everything a report calls "a visit" is a row here — bounce rate,
        | session length, entry and exit pages, and the attribution that brought it.
        */
        if (!$schema->hasTable('analytics_sessions')) {
            $schema->create('analytics_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('visitor_id', 64)->index();
                $table->string('channel', 8)->default('web');    // web | api | app

                $table->string('user_type', 16)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                // Session touch: what brought them THIS time.
                $table->string('source', 96)->nullable();         // google, facebook, (direct)…
                $table->string('medium', 64)->nullable();         // organic, cpc, referral, email…
                $table->string('campaign', 96)->nullable();
                $table->string('content', 96)->nullable();        // utm_content
                $table->string('term', 96)->nullable();           // utm_term
                $table->string('referrer_domain', 191)->nullable();
                $table->unsignedBigInteger('campaign_id')->nullable();  // a short link we issued
                // How the source was decided, so an attribution figure can always be explained.
                $table->string('attribution_basis', 24)->nullable(); // utm | referrer | campaign_link | direct

                $table->string('landing_path', 191)->nullable();
                $table->string('exit_path', 191)->nullable();

                $table->string('device', 16)->nullable();          // desktop | mobile | tablet | bot
                $table->string('os', 32)->nullable();
                $table->string('browser', 32)->nullable();
                $table->string('language', 12)->nullable();
                $table->string('country', 2)->nullable();
                $table->string('app_version', 24)->nullable();     // mobile app builds
                $table->string('ip_hash', 64)->nullable();         // masked then salted; not an address

                $table->unsignedInteger('pageviews')->default(0);
                $table->unsignedInteger('events')->default(0);
                $table->unsignedInteger('duration_seconds')->default(0);
                // Engaged and bounced are derived on write from measured behaviour, not guessed at
                // read time, so two screens can never disagree about the bounce rate.
                $table->boolean('is_engaged')->default(false);
                $table->boolean('is_bounce')->default(true);
                $table->boolean('is_new_visitor')->default(false);

                $table->unsignedInteger('cart_adds')->default(0);
                $table->unsignedInteger('checkouts')->default(0);
                $table->unsignedInteger('orders')->default(0);
                $table->decimal('revenue', 24, 2)->default(0);

                $table->boolean('is_bot')->default(false);
                $table->boolean('is_internal')->default(false);

                $table->timestamp('started_at')->index();
                $table->timestamp('last_activity_at')->index();

                $table->index(['visitor_id', 'started_at'], 'analytics_session_visitor');
                $table->index(['source', 'started_at'], 'analytics_session_source');
                $table->index(['campaign_id', 'started_at'], 'analytics_session_campaign');
                $table->index(['is_bot', 'is_internal', 'started_at'], 'analytics_session_real');
            });
        }

        /*
        | Events: the stream everything else is derived from.
        */
        if (!$schema->hasTable('analytics_events')) {
            $schema->create('analytics_events', function (Blueprint $table) {
                $table->id();
                $table->string('name', 48)->index();               // product_viewed, order_placed…
                $table->string('category', 24)->default('other');  // page | catalogue | cart | checkout | order | search | account | app
                $table->string('visitor_id', 64)->index();
                $table->unsignedBigInteger('session_id')->nullable()->index();
                $table->string('channel', 8)->default('web');

                $table->string('user_type', 16)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                // What the event was about: a product, a category, a shop, an order, a search term.
                $table->string('entity_type', 24)->nullable();
                $table->string('entity_id', 64)->nullable();
                $table->unsignedBigInteger('vendor_id')->nullable();   // so a vendor sees only theirs

                // Money, when the event has any. Stored on the event so revenue analytics never has
                // to re-derive it from a join that may have changed since.
                $table->decimal('value', 24, 2)->nullable();
                $table->string('currency', 8)->nullable();
                $table->unsignedInteger('quantity')->nullable();

                $table->string('path', 191)->nullable();           // normalised, never raw with ids
                $table->json('properties')->nullable();            // redacted before it gets here

                $table->boolean('is_bot')->default(false);
                $table->boolean('is_internal')->default(false);

                // Idempotency: the same act arriving twice is stored once.
                $table->string('dedupe_key', 64)->nullable();

                $table->timestamp('occurred_at')->index();

                $table->index(['name', 'occurred_at'], 'analytics_event_name_time');
                $table->index(['entity_type', 'entity_id', 'occurred_at'], 'analytics_event_entity');
                $table->index(['vendor_id', 'occurred_at'], 'analytics_event_vendor');
                $table->index(['session_id', 'occurred_at'], 'analytics_event_journey');
                $table->unique(['dedupe_key'], 'analytics_event_dedupe');
            });
        }

        /*
        | Daily rollups. Every chart older than today reads from here: one row per
        | (date, dimension, key), so a year of traffic-source history is a few thousand rows
        | instead of tens of millions of events.
        */
        if (!$schema->hasTable('analytics_daily')) {
            $schema->create('analytics_daily', function (Blueprint $table) {
                $table->id();
                $table->date('date');
                // source, medium, campaign, device, country, path, product, category, shop,
                // search_term, event, hour, weekday, new_vs_returning, app_version…
                $table->string('dimension', 32);
                $table->string('dimension_key', 191);

                $table->unsignedBigInteger('sessions')->default(0);
                // Nullable, unlike every other measure here, because the `__other__` row that
                // folds a dimension's tail cannot state them: they are COUNT(DISTINCT visitor_id)
                // per key, and adding those across keys counts one person once per key. Null
                // renders as "—", which is the truthful answer; a zero would not be.
                $table->unsignedBigInteger('visitors')->nullable()->default(0);
                $table->unsignedBigInteger('new_visitors')->nullable()->default(0);
                $table->unsignedBigInteger('pageviews')->default(0);
                $table->unsignedBigInteger('events')->default(0);
                $table->unsignedBigInteger('bounces')->default(0);
                $table->unsignedBigInteger('engaged_sessions')->default(0);
                $table->unsignedBigInteger('duration_seconds')->default(0);
                $table->unsignedBigInteger('cart_adds')->default(0);
                $table->unsignedBigInteger('checkouts')->default(0);
                $table->unsignedBigInteger('orders')->default(0);
                $table->decimal('revenue', 24, 2)->default(0);

                $table->timestamp('computed_at')->nullable();

                $table->unique(['date', 'dimension', 'dimension_key'], 'analytics_daily_unique');
                $table->index(['dimension', 'date'], 'analytics_daily_lookup');
            });
        }

        /*
        | Campaigns: the UTM builder's output, and the short links it issues.
        |
        | The destination is validated and stored at creation time. That is the whole defence
        | against an open redirect: the redirect endpoint looks up a row and sends the visitor to a
        | URL an administrator already approved, never to one supplied in the request.
        */
        if (!$schema->hasTable('analytics_campaigns')) {
            $schema->create('analytics_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('name', 191);
                $table->string('code', 24)->unique();              // the short link's path segment
                $table->text('destination_url');                   // validated against the allow-list
                $table->string('utm_source', 96);
                $table->string('utm_medium', 64);
                $table->string('utm_campaign', 96);
                $table->string('utm_content', 96)->nullable();
                $table->string('utm_term', 96)->nullable();
                $table->string('channel_hint', 32)->nullable();     // whatsapp, instagram, print…
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();

                // Kept on the row so a campaign list does not need a subquery per campaign.
                $table->unsignedBigInteger('clicks')->default(0);
                $table->unsignedBigInteger('sessions')->default(0);
                $table->unsignedBigInteger('orders')->default(0);
                $table->decimal('revenue', 24, 2)->default(0);
                $table->timestamp('last_click_at')->nullable();

                $table->timestamps();
            });
        }

        /*
        | Clicks on a short link, before any cookie exists. One row per click, pruned on the
        | retention schedule; the durable numbers live on the campaign row and in the rollups.
        */
        if (!$schema->hasTable('analytics_campaign_clicks')) {
            $schema->create('analytics_campaign_clicks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('campaign_id')->index();
                $table->string('visitor_id', 64)->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->string('device', 16)->nullable();
                $table->string('country', 2)->nullable();
                $table->string('referrer_domain', 191)->nullable();
                $table->boolean('is_bot')->default(false);
                $table->timestamp('clicked_at')->index();

                $table->index(['campaign_id', 'clicked_at'], 'analytics_click_history');
            });
        }

        /*
        | Saved reports and segments: a question somebody wants to keep asking.
        */
        if (!$schema->hasTable('analytics_saved_reports')) {
            $schema->create('analytics_saved_reports', function (Blueprint $table) {
                $table->id();
                $table->string('name', 191);
                $table->string('section', 48);                     // which screen it belongs to
                $table->json('filters')->nullable();
                $table->string('range', 16)->default('30d');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->boolean('is_shared')->default(false);
                $table->timestamps();

                $table->index(['section', 'created_by'], 'analytics_report_owner');
            });
        }

        /*
        | Collection health. Analytics that cannot tell you it stopped collecting is worse than no
        | analytics: the charts keep drawing, flat, and everybody reads it as a quiet week.
        */
        if (!$schema->hasTable('analytics_health')) {
            $schema->create('analytics_health', function (Blueprint $table) {
                $table->id();
                $table->string('signal', 64)->unique();            // events_written, rollup_ran…
                $table->unsignedBigInteger('count')->default(0);
                $table->text('detail')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ([
            'analytics_health',
            'analytics_saved_reports',
            'analytics_campaign_clicks',
            'analytics_campaigns',
            'analytics_daily',
            'analytics_events',
            'analytics_sessions',
            'analytics_visitors',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
