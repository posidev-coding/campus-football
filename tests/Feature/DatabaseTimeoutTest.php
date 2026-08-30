<?php

use Illuminate\Database\LostConnectionDetector;
use Pdo\Mysql;

/*
 * A MySQL connection with no connect timeout is an unbounded page hang waiting
 * for a scale-to-zero database to wake.
 *
 * PDO falls back to the driver default when `PDO::ATTR_TIMEOUT` is unset, so
 * the connect attempt blocks for however long the wake takes and the bill
 * lands on whichever request happened to arrive first. Telemetry caught the
 * shape rather than the cause: six requests over 1000ms in 24h, worst `POST /`
 * at 1992ms over 3 hits, then `GET /ops/telemetry`, `POST /admin/sync-health`
 * and `POST /admin/login` at one hit each. Four unrelated endpoints, each slow
 * EXACTLY ONCE and all just above a second, is a cold start — a genuinely slow
 * endpoint is slow every time.
 *
 * The wake itself is inherent to scale-to-zero and is not what these assert.
 * What they assert is that the ceiling is DECLARED, that it sits above the
 * wake rather than under it, and that the retry the fix leans on is still
 * armed — a connection added later has to declare one too.
 */

/**
 * Re-evaluate config/database.php under a controlled environment.
 *
 * `Env::getRepository()` is immutable, so writing through it cannot override a
 * value and cannot be undone. The adapters read `$_ENV`, `$_SERVER` and
 * `getenv()` live on every lookup with no caching in between, so setting those
 * directly is what actually moves `env()`.
 *
 * @param  array<string, string|null>  $environment
 * @return array<string, mixed>
 */
function resolveDatabaseConnections(array $environment): array
{
    $original = [];

    foreach ($environment as $key => $value) {
        $original[$key] = $_SERVER[$key] ?? null;

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        return (require base_path('config/database.php'))['connections'];
    } finally {
        foreach ($original as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

/**
 * The connections whose options are built behind the `pdo_mysql` guard.
 *
 * @return array<string, mixed>
 */
function pdoMysqlConnections(): array
{
    return collect(config('database.connections'))
        ->only(['mysql', 'mariadb'])
        ->all();
}

it('gives every pdo_mysql connection a finite connect timeout', function () {
    expect(extension_loaded('pdo_mysql'))->toBeTrue(
        'This application runs on MySQL; without the extension the options array is empty and asserts nothing.'
    );

    $connections = pdoMysqlConnections();

    expect($connections)->not->toBeEmpty();

    $offenders = collect($connections)
        ->filter(fn (array $connection): bool => ($connection['options'][PDO::ATTR_TIMEOUT] ?? 0) <= 0)
        ->keys()
        ->all();

    expect($offenders)->toBe([]);
});

it('keeps that timeout above the wake and under a bounded ceiling', function () {
    /*
     * It has to sit ABOVE the observed wake, not under it. Wakes finish inside
     * ~2s, and a timeout tighter than the wake would cut short connects that
     * were going to succeed — turning a slow page into an error page, which is
     * not an improvement for a reader.
     *
     * The upper bound is where holding a request worker stops being
     * defensible. Laravel's connector retries the connect once, so the real
     * worst case is DOUBLE whatever is asserted here.
     */
    $timeouts = collect(pdoMysqlConnections())
        ->map(fn (array $connection): int => $connection['options'][PDO::ATTR_TIMEOUT])
        ->values();

    expect($timeouts->min())->toBeGreaterThanOrEqual(3)
        ->and($timeouts->max())->toBeLessThanOrEqual(10);
});

it('declares the timeout while the ssl ca filters out unset', function () {
    $connections = resolveDatabaseConnections([
        'DB_CONNECT_TIMEOUT' => null,
        'MYSQL_ATTR_SSL_CA' => null,
    ]);

    foreach (['mysql', 'mariadb'] as $name) {
        expect($connections[$name]['options'])
            ->toHaveKey(PDO::ATTR_TIMEOUT)
            ->not->toHaveKey(Mysql::ATTR_SSL_CA)
            ->and($connections[$name]['options'][PDO::ATTR_TIMEOUT])->toBe(5);
    }
});

it('carries the timeout alongside the ssl ca when one is configured', function () {
    $connections = resolveDatabaseConnections([
        'DB_CONNECT_TIMEOUT' => null,
        'MYSQL_ATTR_SSL_CA' => '/etc/ssl/certs/ca.pem',
    ]);

    foreach (['mysql', 'mariadb'] as $name) {
        expect($connections[$name]['options'][PDO::ATTR_TIMEOUT])->toBe(5)
            ->and($connections[$name]['options'][Mysql::ATTR_SSL_CA])->toBe('/etc/ssl/certs/ca.pem');
    }
});

it('takes the timeout from the environment as an integer', function () {
    $connections = resolveDatabaseConnections([
        'DB_CONNECT_TIMEOUT' => '8',
        'MYSQL_ATTR_SSL_CA' => null,
    ]);

    expect($connections['mysql']['options'][PDO::ATTR_TIMEOUT])->toBe(8)
        ->and($connections['mariadb']['options'][PDO::ATTR_TIMEOUT])->toBe(8);
});

it('drops the option at zero, which is the driver default it would fall back to', function () {
    /*
     * `array_filter` takes a 0 out along with an unset SSL CA. That is the
     * escape hatch working rather than leaking: pdo_mysql reads 0 as "no
     * timeout", which is exactly what an absent key gives you.
     */
    $connections = resolveDatabaseConnections([
        'DB_CONNECT_TIMEOUT' => '0',
        'MYSQL_ATTR_SSL_CA' => null,
    ]);

    expect($connections['mysql']['options'])->not->toHaveKey(PDO::ATTR_TIMEOUT);
});

it('still recognizes a connect timeout as a lost connection, so the retry arms', function () {
    /*
     * This is the half of the fix that is not config. When the ceiling fires,
     * `Connectors\Connector::createConnection()` retries the connect ONCE, but
     * only if `causedByLostConnection()` says so — and that is a list of
     * message needles inside the framework, which an upgrade can quietly
     * change. Drop the needle and the timeout stops being a bounded wait and
     * becomes a 500 for the first visitor, with nothing else failing.
     *
     * Asserted at the detector rather than against a database: a real
     * connect timeout needs a blackholed host and seconds of wall time, and
     * that is a network test, not a suite test.
     *
     * NOTE the message is Linux's. macOS renders the same errno as `Operation
     * timed out`, which is NOT on the list, so the retry does not engage
     * locally. Production is Linux, which is the case that matters.
     */
    $detector = new LostConnectionDetector;

    expect($detector->causedByLostConnection(
        new PDOException('SQLSTATE[HY000] [2002] Connection timed out')
    ))->toBeTrue();
});
