<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attention: the one thing the app has never measured about itself.
 *
 * `ux_events` counts nine named moments in aggregate and deliberately holds
 * nobody's identity; Pulse watches the machine seven days deep; `picks`,
 * `group_members` and the rest are truth tables that know WHAT somebody did
 * and never that they read a screen. Between them there is no answer to "did
 * the people who registered in week two come back in week four", and no
 * answer to "does anybody open Rankings" — adoption of a feature can be
 * joined, attention to a screen could not be asked at all.
 *
 * So this is the missing sensor and the two tables derived from it, in the
 * shape `ux_events` already argued for: NOTHING WRITES MYSQL ON THE REQUEST
 * PATH. A page view goes onto a Redis stream and a scheduled drain lands it
 * here (phase 2 and phase 3 of docs/plans/analytics.md); this migration only
 * builds the destination.
 *
 * The identity split is the whole design. `activity_events` carries a user
 * id and lives THIRTY DAYS — long enough to read an error against the traffic
 * that produced it, since `client_errors` keeps the same window, and long
 * enough to re-derive every rollup after a rollup bug without a backfill.
 * `page_views_daily` and `user_days` are what live on, and they are counts.
 * The ceiling is the point: the identity-bearing table has a hard one that
 * nobody has to remember, and a deleted account's clickstream cascades away
 * with it on the same day the account goes.
 *
 * What is NOT here: a row for anything a truth table already holds. A second
 * row for a pick is a second counter that can disagree with `picks`, and a
 * stream entry can be trimmed under load where a truth row cannot — the rule
 * `App\Enums\UxSignal` states at length, applied one layer up. The raw table
 * holds page views plus the handful of moments with no other home.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            /*
             * The Redis stream entry id, unique — which is what makes the
             * drain idempotent. It reads a batch, inserts, then XDELs; a
             * crash between those two leaves the entries on the stream, and
             * the next drain re-reads them. `insertOrIgnore` on this column
             * is the difference between "at least once" and "exactly once"
             * without any XACK bookkeeping for a single consumer.
             */
            $table->string('stream_id', 24)->charset('ascii');
            // An App\Enums\ActivityKind value. A string for the reason
            // `ux_events.signal` is one: a rename stays a data migration.
            $table->string('kind', 24);
            /*
             * Exactly one of `user_id` / `visitor` is non-null, enforced by
             * the sensor. Cascade rather than nullOnDelete: a deleted
             * account's clickstream is the account, and orphaning it would
             * leave rows that are neither a person's nor a guest's.
             */
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            /*
             * Guests only: the first 32 hex of sha256(session id), the
             * one-way shape `RecordUxEvent::handleOnce` already uses. It
             * exists to count PEOPLE inside a cell, and it dies with the
             * session — it is not a durable identifier and must never become
             * one.
             */
            $table->char('visitor', 32)->charset('ascii')->nullable();
            /*
             * 0 guest, 1 member, 2 staff. Recorded at request time because
             * the drain runs minutes later and cannot ask; without it every
             * dashboard counts the founder's own browsing as traffic, which
             * at pilot scale is most of it.
             */
            $table->unsignedTinyInteger('audience');
            // The route NAME (`pickem.group`), never a path: bounded
            // cardinality, no ids, and no query string for a signed link or
            // an invite code to ride in on.
            $table->string('route', 48);
            // One allowlisted second dimension — the clubhouse's `view` today.
            // Never an arbitrary query parameter.
            $table->string('facet', 16)->nullable();
            /*
             * Actions only, never a page view. A morph alias from the
             * enforced map plus an id, with NO foreign key on purpose: the
             * parents are mixed, and `teams.id` is mediumint, which
             * `foreignId()` cannot constrain anyway.
             */
            $table->string('subject_type', 16)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->dateTime('occurred_at');
            /*
             * The league day and hour, derived ONCE by the drain from
             * `occurred_at` and never edited afterward. They are an INDEX,
             * not the truth (`.ai/rules/data-model.md`) — and the hour is
             * here because the weekday x hour heat cannot ask for it in SQL:
             * CONVERT_TZ does not know about DST the way the app does.
             */
            $table->date('day');
            $table->unsignedTinyInteger('hour');
            // The raw width from the client cookie, the shape
            // `client_errors.viewport` uses. NULL is "not reported" and is
            // bucketed as its own category at rollup, never as a guess.
            $table->unsignedSmallInteger('viewport')->nullable();
            // Nullable, and deliberately not `default(false)`: false is a
            // claim that the reader was in a browser, and we do not know that
            // until the cookie says so.
            $table->boolean('standalone')->nullable();
            // From the X-Livewire-Navigate header, so `views - navigate_views`
            // is cold loads. Never null: the header is either there or it is
            // not, and both are facts.
            $table->boolean('via_navigate');
            // Release::version(), or null when there is no stamp — so a
            // regression can be read against the deploy that shipped it.
            $table->string('release', 32)->nullable();

            $table->unique('stream_id');
            // The page-view rollup and the time-of-week heat.
            $table->index(['day', 'route']);
            // The user-day rollup.
            $table->index(['day', 'user_id']);
            // One member's recent activity, on the admin User resource. Also
            // the index MySQL reuses for the user_id foreign key.
            $table->index(['user_id', 'occurred_at']);
            // Prunable, and the 24-hour join that reads a route's error rate
            // against its own traffic.
            $table->index('occurred_at');
        });

        /*
         * Attention, aggregated, kept indefinitely. A few hundred rows a day.
         */
        Schema::create('page_views_daily', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('route', 48);
            // An empty string, NOT null, because MySQL treats nulls as
            // distinct inside a unique key — a nullable facet would let the
            // upsert write a second row for every faceted cell it already had.
            $table->string('facet', 16)->default('');
            $table->unsignedTinyInteger('audience');
            // App\Enums\ViewportBucket. `Unknown` is a real category — "not
            // reported" — and never a fabricated width.
            $table->unsignedTinyInteger('viewport_bucket');
            // 0 unknown, 1 browser, 2 standalone. Same reasoning: three
            // states, because "we were not told" is not "in a browser".
            $table->unsignedTinyInteger('installed');
            $table->unsignedInteger('views');
            /*
             * count(distinct coalesce(user_id, visitor)) INSIDE this cell.
             * NON-ADDITIVE across cells, and said here because summing this
             * column over a window is the mistake it invites: one person on
             * two viewports is two rows and one visitor.
             */
            $table->unsignedInteger('visitors');
            // Hops. `views - navigate_views` is cold loads.
            $table->unsignedInteger('navigate_views');
            $table->timestamps();

            // The upsert key, so re-rolling a day corrects it rather than
            // doubling it — `ux_events` (day, signal), one dimension wider.
            $table->unique(['day', 'route', 'facet', 'audience', 'viewport_bucket', 'installed'], 'page_views_daily_cell_unique');
            // Route popularity over a window: the route leads, because that
            // is what the filter names.
            $table->index(['route', 'day']);
        });

        /*
         * Presence: one row per person per league day, kept indefinitely. A
         * row exists ONLY when the person did something — absence is the
         * datum, and writing a zero row for a quiet day would turn "did not
         * open the app" into a fact we invented.
         */
        Schema::create('user_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedSmallInteger('views');
            // Non-page-view kinds that day.
            $table->unsignedSmallInteger('actions');
            // App\Enums\ActivityArea bitmask. Route to area is read from
            // Navigation::areas() at rollup time rather than kept as a second
            // map that can disagree with the nav.
            $table->unsignedTinyInteger('areas');
            /*
             * App\Enums\ActivityFeature bitmask. Most of these bits come from
             * the TRUTH tables, not from the clickstream — which is what
             * makes this table honest for somebody who picked from a
             * notification deep link and never rendered a second screen.
             */
            $table->unsignedSmallInteger('features');
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            // The mode of the day's views. Unknown when nothing was reported.
            $table->unsignedTinyInteger('viewport_bucket');
            $table->timestamps();

            $table->unique(['user_id', 'day']);
            // Actives for a day, a week and 28 days — the whole audience side.
            $table->index('day');
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * Written by the DRAIN only, in one batched update per run, so it
             * moves at most once per drain cadence per person and never on
             * the request path. It is for the admin User resource; it never
             * enters the ops snapshot. Nullable and never backfilled: before
             * the sensor shipped there is no answer, and now() would be a
             * fabricated one.
             */
            $table->dateTime('last_seen_at')->nullable()->after('picks_tour_completed_at');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });

        Schema::dropIfExists('user_days');
        Schema::dropIfExists('page_views_daily');
        Schema::dropIfExists('activity_events');
    }
};
