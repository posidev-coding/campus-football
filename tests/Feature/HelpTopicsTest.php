<?php

use App\Ai\Agents\HelpQuestion;
use App\Enums\ContentRating;
use App\Models\User;
use App\Support\HelpTopics;
use App\Support\Voice;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Route;

/*
 * The vocabulary sweep. One list feeds the prompt and the schema, and every
 * key on it has to resolve to copy a person wrote, in every register, that
 * points somewhere real — because a topic the model can name and the app
 * cannot answer is a decline the reader did not earn.
 */

it('writes every topic in every register, escalating rather than repeating', function () {
    $pg = User::factory()->make(['content_rating' => ContentRating::Pg]);
    $r = User::factory()->make(['content_rating' => ContentRating::R]);

    foreach (HelpTopics::keys() as $key) {
        $lines = (new ReflectionClass(Voice::class))->getConstant('LINES')["help.{$key}"] ?? null;

        expect($lines)->not->toBeNull("help.{$key} is unwritten")
            ->and($lines)->toHaveKeys(['pg', 'pg13', 'r']);

        expect(HelpTopics::answer($key, $pg)['body'])->not->toBe(HelpTopics::answer($key, $r)['body'], "help.{$key} does not escalate");
    }

    foreach (['help.subheading', 'help.idle', 'help.none', 'help.capped'] as $chrome) {
        expect((new ReflectionClass(Voice::class))->getConstant('LINES')[$chrome])->toHaveKeys(['pg', 'pg13', 'r']);
    }
});

it('leaves no token unfilled and names no school', function () {
    foreach (HelpTopics::keys() as $key) {
        foreach (ContentRating::cases() as $rating) {
            $body = HelpTopics::answer($key, User::factory()->make(['content_rating' => $rating]))['body'];

            expect($body)->not->toMatch('/:[a-z_]+/', "help.{$key}.{$rating->value} left a token: {$body}")
                ->and(stripos($body, 'georgia'))->toBeFalse("help.{$key}.{$rating->value} names Georgia");
        }
    }
});

it('points every topic at a registered route', function () {
    foreach (HelpTopics::TOPICS as $key => $topic) {
        expect(Route::has($topic['route']))->toBeTrue("{$key} points at an unknown route {$topic['route']}");
    }

    expect(Route::has('verification.notice'))->toBeTrue();
});

it('offers examples that resolve, each to a topic on the list', function () {
    $reader = User::factory()->make();

    foreach (HelpTopics::examples() as $example) {
        expect(HelpTopics::keys())->toContain($example['topic'])
            ->and(HelpTopics::answer($example['topic'], $reader))->not->toBeNull();
    }
});

it('feeds the schema and the prompt from the same list', function () {
    $schema = (new HelpQuestion)->schema(new JsonSchemaTypeFactory);
    $vocabulary = HelpTopics::vocabulary();

    // The enum IS the key list — nothing the model can name is unanswerable
    // for want of a resolver, and nothing we answer is unnameable.
    expect($schema['topic']->toArray()['enum'] ?? null)->toBe(HelpTopics::keys());

    foreach (HelpTopics::keys() as $key) {
        expect(substr_count($vocabulary, "`{$key}`"))->toBe(1, "{$key} appears in the vocabulary other than once");
    }

    expect(HelpTopics::answer('not.a.topic', null))->toBeNull();
});
