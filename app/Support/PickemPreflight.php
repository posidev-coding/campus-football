<?php

namespace App\Support;

use App\Enums\ContestMode;
use App\Models\Game;
use App\Models\Group;
use App\Models\Slate;
use App\Models\Week;
use App\Services\CfbCalendar;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is Pick'em ready to be shown to people who are not admins?
 *
 * The `pickem` Pennant flag is one line, and flipping it is the cheap part.
 * The expensive part is everything that has to already be TRUE underneath
 * it: a real week resolved from the calendar, public rooms with published
 * slates for that week, enough lined games to build next week's, and the
 * three scheduled sweeps actually registered. A flag flipped over any one of
 * those missing lands a new user on an empty room, which is the one first
 * impression that cannot be taken back.
 *
 * Shaped like {@see CoverageReport} on purpose — same row keys, so the same
 * terminal renderer prints both and a future admin page can show them side
 * by side.
 *
 * Nothing here writes, and nothing here flips the flag. It answers a
 * question; the decision stays a person's.
 */
class PickemPreflight
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Below this many lined games, next week's slate cannot be built. */
    public const LINED_GAMES_NEEDED = 15;

    public function __construct(private CfbCalendar $calendar) {}

    /**
     * @return list<array{key: string, label: string, status: string, detail: string, remedy: string|null}>
     */
    public function checks(): array
    {
        $year = $this->calendar->currentYear();
        $weekId = $this->calendar->defaultWeekId($year);
        $week = $weekId === null ? null : Week::find($weekId);

        return [
            $this->calendarCheck($year, $week),
            $this->roomsCheck($week),
            $this->linedGamesCheck($week),
            $this->settingsCheck(),
            $this->scheduleCheck(),
            $this->flagCheck(),
            $this->storedValuesCheck(),
        ];
    }

    public function failing(): int
    {
        return collect($this->checks())->where('status', self::FAIL)->count();
    }

    /**
     * The season is never hardcoded — it is asked for, and a season exists in
     * the database months before it is played, so "no current week" is a real
     * state rather than an error.
     *
     * @return array{key: string, label: string, status: string, detail: string, remedy: string|null}
     */
    private function calendarCheck(int $year, ?Week $week): array
    {
        if ($week === null) {
            return $this->row(
                'calendar',
                'Calendar week',
                self::FAIL,
                "No default week resolves for {$year}.",
                "cfb:sync --year={$year}",
            );
        }

        $detail = "{$year} · {$week->name}.";

        // Inside a split opening week, ESPN's name ("Week 1") and the card
        // being played can disagree — say which card the clock is on.
        if (Cadence::splitBoundary($week) !== null) {
            $detail = "{$year} · {$week->name} · playing ".Cadence::displayWeekLabel($week, Cadence::currentSaturday()).'.';
        }

        return $this->row('calendar', 'Calendar week', self::OK, $detail);
    }

    /**
     * A flipped flag sends everybody who has no group to the public floor, so
     * the floor has to be stocked BEFORE the flip, not by the sweep an hour
     * after it.
     *
     * @return array{key: string, label: string, status: string, detail: string, remedy: string|null}
     */
    private function roomsCheck(?Week $week): array
    {
        if ($week === null) {
            return $this->row('rooms', 'Open public rooms', self::FAIL, 'No week to stock rooms for.', 'pickem:open-lobbies');
        }

        $saturday = Cadence::floorSaturday($week);

        if ($saturday === null) {
            return $this->row('rooms', 'Open public rooms', self::FAIL, 'No Saturday to stock rooms for.', 'cfb:games --tier=current');
        }

        // The red line is the three STANDARD rooms — a specialty room of
        // the same mode must never satisfy a shelf it does not stock.
        $open = Group::query()
            ->where('kind', Group::KIND_LOBBY)
            ->where('week_id', $week->id)
            ->whereNull('flavor')
            ->whereNull('filled_at')
            ->pluck('id');

        $stocked = Slate::query()
            ->whereIn('status', [Slate::PUBLISHED, Slate::PRELIM])
            ->where('saturday', $saturday->format('Y-m-d'))
            ->whereHas('contest', fn ($query) => $query->whereIn('group_id', $open))
            ->with('contest:id,group_id,mode')
            ->get()
            ->pluck('contest.mode')
            ->unique();

        /*
         * A mode the Saturday cannot seat is EXEMPT, not missing — the
         * opening card holds eight usable games, and failing the fifteen-
         * game shelves all week would teach the reader to ignore red.
         */
        $exempt = collect(ContestMode::cases())
            ->reject(fn (ContestMode $mode) => $stocked->contains($mode))
            ->filter(fn (ContestMode $mode) => LobbyCatalog::resolve($mode, null, $week, $saturday) === null);

        $missing = collect(ContestMode::cases())
            ->reject(fn (ContestMode $mode) => $stocked->contains($mode))
            ->reject(fn (ContestMode $mode) => $exempt->contains($mode))
            ->map(fn (ContestMode $mode) => $mode->label());

        $exemptNote = $exempt->isEmpty()
            ? ''
            : ' '.$exempt->map(fn (ContestMode $mode) => $mode->label())->implode(', ').': not enough games this Saturday.';

        if ($missing->isNotEmpty()) {
            return $this->row(
                'rooms',
                'Open public rooms',
                self::FAIL,
                'No open room with a published slate for: '.$missing->implode(', ').'.'.$exemptNote,
                'pickem:open-lobbies',
            );
        }

        return $this->row(
            'rooms',
            'Open public rooms',
            self::OK,
            $stocked->count().' of '.(count(ContestMode::cases()) - $exempt->count()).' possible modes stocked.'.$exemptNote,
        );
    }

    /**
     * A game with no posted line can never publish (the half-point law), so
     * the real question is not "are there games" but "are there enough LINED
     * ones in the Saturday window".
     *
     * @return array{key: string, label: string, status: string, detail: string, remedy: string|null}
     */
    private function linedGamesCheck(?Week $week): array
    {
        if ($week === null) {
            return $this->row('lines', 'Lined games', self::FAIL, 'No week to count games in.', 'cfb:games --tier=current');
        }

        // The Saturday half is a PHP check — the ET time-of-day boundary
        // shifts under DST and cannot be asked in SQL.
        $lined = Game::query()
            ->where('week_id', $week->id)
            ->whereHas('odds')
            ->get()
            ->filter(fn (Game $game) => $game->inSlateWindow())
            ->count();

        if ($lined < self::LINED_GAMES_NEEDED) {
            return $this->row(
                'lines',
                'Lined games',
                self::FAIL,
                "{$lined} lined games in the Saturday window; ".self::LINED_GAMES_NEEDED.' needed for a full slate.',
                'cfb:games --tier=current',
            );
        }

        return $this->row('lines', 'Lined games', self::OK, "{$lined} in the Saturday window.");
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string, remedy: string|null}
     */
    private function settingsCheck(): array
    {
        if (! Schema::hasTable('pickem_settings')) {
            return $this->row('settings', 'League clock', self::FAIL, 'pickem_settings is missing.', 'migrate');
        }

        return $this->row(
            'settings',
            'League clock',
            self::OK,
            'Deadline '.Cadence::deadlineLabel().', official '.Cadence::officialLabel().'.',
        );
    }

    /**
     * The three sweeps that keep a live league honest between deploys. A flag
     * flipped without them looks fine for a day and then quietly stops
     * publishing slates on the Tuesday nobody was watching.
     *
     * @return array{key: string, label: string, status: string, detail: string, remedy: string|null}
     */
    private function scheduleCheck(): array
    {
        $wanted = ['pickem:publish-boards', 'pickem:settle', 'pickem:open-lobbies'];

        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? '')
            ->implode(' ');

        $missing = collect($wanted)->reject(fn (string $command) => str_contains($scheduled, $command));

        if ($missing->isNotEmpty()) {
            return $this->row(
                'schedule',
                'Scheduled sweeps',
                self::FAIL,
                'Not scheduled: '.$missing->implode(', ').'.',
                'schedule:list',
            );
        }

        return $this->row('schedule', 'Scheduled sweeps', self::OK, count($wanted).' registered.');
    }

    /**
     * Reported, never changed. WARN rather than FAIL when the flag is still
     * admin-only, because that is the correct state right up until the moment
     * somebody decides otherwise — and this command is not that somebody.
     *
     * @return array{key: string, label: string, status: string, detail: string, remedy: string|null}
     */
    private function flagCheck(): array
    {
        /*
         * Read from CONFIG, never resolved through Pennant. Resolving would
         * persist a `features` row as a side effect of a read-only report,
         * and resolving against a throwaway store does not work at all —
         * definitions are registered per store, so the closure this cares
         * about simply is not there. The config IS the switch; asking it is
         * both honest and free.
         *
         * WARN rather than FAIL while it is closed, because closed is the
         * correct state right up until somebody decides otherwise, and this
         * command is not that somebody.
         */
        return config('cfb.pickem_open') === true
            ? $this->row('flag', 'The pickem flag', self::OK, 'OPEN — every signed-in user is inside it.')
            : $this->row('flag', 'The pickem flag', self::WARN, 'Admin-only. Set PICKEM_OPEN=true when these checks are green.');
    }

    /**
     * The landmine under the whole flip.
     *
     * Pennant's database driver PERSISTS every resolved value, so the closure
     * runs once per user and the answer is then read from a row. Flipping
     * `PICKEM_OPEN` therefore reaches nobody who has already loaded a page —
     * they keep the false that was stored for them, forever, and the launch
     * silently does nothing for exactly the people who were already here.
     *
     * `pennant:purge pickem` drops those rows so the closure runs again. This
     * row exists to say so BEFORE the flip rather than during the confused
     * hour after it.
     *
     * @return array{key: string, label: string, status: string, detail: string, remedy: string|null}
     */
    private function storedValuesCheck(): array
    {
        $table = config('pennant.stores.database.table', 'features');

        if (! Schema::hasTable($table)) {
            return $this->row('stored', 'Stored flag values', self::OK, 'Nothing persisted.');
        }

        $stored = DB::table($table)->where('name', 'pickem')->count();

        if ($stored === 0) {
            return $this->row('stored', 'Stored flag values', self::OK, 'None — a flip takes effect immediately.');
        }

        return $this->row(
            'stored',
            'Stored flag values',
            self::WARN,
            "{$stored} resolved and persisted; they keep their old answer until purged.",
            'pennant:purge pickem',
        );
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string, remedy: string|null}
     */
    private function row(string $key, string $label, string $status, string $detail, ?string $remedy = null): array
    {
        return compact('key', 'label', 'status', 'detail', 'remedy');
    }
}
