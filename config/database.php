<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            /*
             * A FINITE CONNECT TIMEOUT, and this is not tuning.
             *
             * Unset, PDO takes the driver default and a connect against a
             * SLEEPING database blocks for as long as the wake takes —
             * unbounded, and billed to whichever request happens to arrive
             * first. Telemetry caught the shape: six requests over 1000ms in
             * 24h, worst `POST /` at 1992ms over 3 hits, the rest one hit each
             * on `GET /ops/telemetry` (1522ms), `POST /admin/sync-health`
             * (1044ms) and `POST /admin/login` (1042ms). Four unrelated
             * endpoints, each slow EXACTLY ONCE and all clustered just above a
             * second, is a cold start; a genuinely slow endpoint is slow
             * repeatedly. `POST /` is guest Home — the first thing a new
             * visitor touches.
             *
             * Five seconds because it must sit ABOVE the wake, not under it.
             * Observed wakes finish inside ~2s, so 5 never cuts a real one
             * short — a timeout tighter than the wake would just convert a
             * slow page into an error page, which is no improvement for a
             * reader. What it buys is a CEILING the app chose.
             *
             * When it fires, `Connectors\Connector::createConnection()` catches
             * the PDOException and retries the connect ONCE if
             * `causedByLostConnection()`. On Linux a connect timeout reads
             * `SQLSTATE[HY000] [2002] Connection timed out`, which is on that
             * needle list, so the second attempt meets an already-waking
             * database and succeeds. Worst case is two attempts — ~10s, and
             * bounded, which is the entire point.
             *
             * That retry is NOT the one CFB-8 describes as unavailable. CFB-8
             * is `Connection::handleQueryException()`, which rethrows outright
             * at `transactions >= 1`: a connection lost MID-transaction cannot
             * be retried, because the transaction's writes went with it. This
             * one is a connect that timed out before any work was done, which
             * is always safe to repeat — a different call site, correctly left
             * alone. `handleBeginTransactionException()` reconnects only at
             * `transactions === 0`, i.e. the same first-connect case.
             *
             * NOT reproducible on macOS: BSD renders ETIMEDOUT as `Operation
             * timed out`, which is absent from that list, so the retry does not
             * engage locally even though the timeout itself does.
             *
             * `array_filter` drops a `DB_CONNECT_TIMEOUT` of 0 along with an
             * unset `MYSQL_ATTR_SSL_CA`. That is the escape hatch working, not
             * leaking: pdo_mysql reads 0 as "no timeout", which is exactly the
             * driver default the dropped key falls back to.
             */
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5),
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            /*
             * Same finite connect timeout as `mysql` above, where the
             * reasoning and the measurement live.
             */
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5),
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        /*
         * FINITE TIMEOUTS ON EVERY CONNECTION, and this is not tuning.
         *
         * Unset, phpredis falls back to PHP's `default_socket_timeout` — SIXTY
         * SECONDS — for both the connect and the read. Production hit it three
         * times in two nights: `/games/{game}` at 60,123ms, 60,143ms and
         * 60,123ms, each followed by a RedisException from
         * PhpRedisConnector::establishConnection(). Values that tight are a
         * timeout, never slowness, and the page had spent a full minute of a
         * PHP worker before failing.
         *
         * A managed cache restarts for updates and a Flex cache sleeps when
         * idle, so a connect that stalls is NORMAL and expected. The retry and
         * backoff settings below exist to ride exactly that out — but they
         * cannot help while the first attempt is still hanging, so the whole
         * budget is spent before the first retry is even reached.
         *
         * Nothing here issues a blocking Redis command: `queue.redis.block_for`
         * is null, no BLPOP/BRPOP anywhere, and Pulse's drain is xrange in a
         * loop. So a read taking longer than a few seconds is a stall rather
         * than work, and failing fast into the retry is strictly better than
         * holding a request worker for a minute.
         */
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => (float) env('REDIS_TIMEOUT', 3),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 5),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'timeout' => (float) env('REDIS_TIMEOUT', 3),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 5),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        /*
         * Pulse's ingest stream, buffered here and drained to MySQL by
         * `pulse:work` so telemetry never rides the request path.
         *
         * Its OWN database rather than sharing the cache's DB 1: `cache:clear`
         * flushes that database, and it is run deliberately (it also re-arms
         * the mail/SMS budgets and the ESPN limiter). Buffered telemetry must
         * never be collateral damage of a routine clear.
         */
        'pulse' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_PULSE_DB', '2'),
            'timeout' => (float) env('REDIS_TIMEOUT', 3),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 5),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
