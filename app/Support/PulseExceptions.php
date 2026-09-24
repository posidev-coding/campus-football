<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Laravel\Pulse\Recorders\Exceptions;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Throwable;

/**
 * Pulse's exception recorder, taught where a Livewire exception came from.
 *
 * Pulse files an exception under the first frame outside vendor/. A Livewire
 * update is handled entirely inside Livewire's own endpoint, below the `web`
 * pipeline, so walking outward the first application frame is always
 * RecordPageView::handle()'s `$next()`, a middleware that only passes the
 * request along. Every Livewire exception in the app grouped onto that one
 * line. Twenty CannotUpdateLockedPropertyException hits in a day read as a
 * fault in the page-view sensor and named no component at all (CFB-86).
 *
 * So when the stock answer lands on a middleware, and the request is a
 * Livewire update, the location becomes the component the payload names:
 * `livewire:help-sheet [path]`. A locked-property refusal names the property
 * and the component that was sent an update for it. Anything the stock
 * resolver already places in real application code is left alone.
 *
 * Registered in config/pulse.php in place of Recorders\Exceptions.
 */
class PulseExceptions extends Exceptions
{
    /**
     * The parent's record(), reading `location` under THIS class's config key.
     *
     * The parent reads it through `self::class`, which is always the parent,
     * while its sampling and ignore traits read through `static::class`. A
     * subclass under its own key would otherwise lose its location silently
     * and record every exception with none.
     */
    public function record(Throwable $e): void
    {
        $timestamp = CarbonImmutable::now()->getTimestamp();

        $this->pulse->lazy(function () use ($timestamp, $e) {
            $class = $this->resolveClass($e);

            if (! $this->shouldSample() || $this->shouldIgnore($class)) {
                return;
            }

            $location = $this->config->get('pulse.recorders.'.static::class.'.location')
                ? $this->resolveLocation($e)
                : null;

            $this->pulse->record(
                type: 'exception',
                key: json_encode([$class, $location], flags: JSON_THROW_ON_ERROR),
                timestamp: $timestamp,
                value: $timestamp,
            )->max()->count();
        });
    }

    protected function resolveLocation(Throwable $e): string
    {
        $location = parent::resolveLocation($e);

        if (! str_starts_with($location, 'app/Http/Middleware/')) {
            return $location;
        }

        return self::livewireLocation(app('request'), $e) ?? $location;
    }

    /**
     * The component a Livewire update was for, read off the request payload.
     *
     * Each posted component carries its snapshot, whose memo names it, and the
     * updates it was sent. A locked-property refusal is pinned to the one
     * component whose updates hold that property. Null when this was not a
     * Livewire update, so the caller keeps the location it had.
     */
    public static function livewireLocation(Request $request, Throwable $e): ?string
    {
        // Livewire names its endpoint `default-livewire.update`: match by
        // containment, the way RecordPageView does.
        if (! str_contains((string) $request->route()?->getName(), 'livewire.update')) {
            return null;
        }

        $components = $request->input('components');

        if (! is_array($components)) {
            return null;
        }

        $property = $e instanceof CannotUpdateLockedPropertyException ? (string) $e->property : null;
        $names = [];

        foreach ($components as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            if ($property !== null && ! self::updates($component, $property)) {
                continue;
            }

            $names[] = $name;
        }

        if ($names === []) {
            return null;
        }

        return 'livewire:'.implode(',', array_unique($names)).($property === null ? '' : " [{$property}]");
    }

    /** Was this component sent an update for the property, or a key under it? */
    private static function updates(mixed $component, string $property): bool
    {
        foreach (array_keys((array) ($component['updates'] ?? [])) as $key) {
            if ($key === $property || str_starts_with((string) $key, $property.'.')) {
                return true;
            }
        }

        return false;
    }
}
