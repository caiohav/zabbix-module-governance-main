<?php

namespace Modules\Governance;

/** Pure temporal calculations. Intervals are half-open [from,to), at one-second resolution.
 * Each interval is [from, to, up fraction, down fraction, unknown fraction]. */
final class AvailabilityEngine {
    public static function matches(float $value, array $rule): bool {
        switch ($rule['op']) {
            case 'eq': return $value == $rule['a'];
            case 'ne': return $value != $rule['a'];
            case 'gt': return $value > $rule['a'];
            case 'ge': return $value >= $rule['a'];
            case 'lt': return $value < $rule['a'];
            case 'le': return $value <= $rule['a'];
            case 'range': return $value >= $rule['a'] && $value <= $rule['b'];
        }
        return false;
    }

    public static function state($value, array $check): int {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            return -1;
        }
        $up = self::matches((float) $value, $check['up']);
        if ($check['down'] === null) {
            return $up ? 1 : 0;
        }
        $down = self::matches((float) $value, $check['down']);
        // Neither or both predicates matching must not silently invent a known state.
        return $up === $down ? -1 : ($up ? 1 : 0);
    }

    public static function unknown(int $from, int $to): array {
        return $to > $from ? [[$from, $to, 0.0, 0.0, 1.0]] : [];
    }

    public static function samples(array $samples, array $check, int $age, int $from, int $to): array {
        usort($samples, static function($a, $b) {
            return [(int) $a['clock'], (int) ($a['ns'] ?? 0)] <=> [(int) $b['clock'], (int) ($b['ns'] ?? 0)];
        });
        $result = [];
        $cursor = $from;
        $last = null;
        foreach ($samples as $sample) {
            $clock = (int) $sample['clock'];
            if ($clock < $from) {
                $last = $sample;
                continue;
            }
            if ($clock >= $to) {
                break;
            }
            self::fill($result, $last, $check, $age, $cursor, $clock);
            $cursor = $clock;
            $last = $sample;
        }
        self::fill($result, $last, $check, $age, $cursor, $to);
        return $result;
    }

    private static function fill(array &$result, ?array $sample, array $check, int $age, int $from, int $to): void {
        if ($to <= $from) { return; }
        $expiry = $sample ? min($to, max($from, (int) $sample['clock'] + $age)) : $from;
        $state = $sample ? self::state($sample['value'], $check) : -1;
        self::append($result, [$from, $expiry, $state === 1 ? 1.0 : 0.0,
            $state === 0 ? 1.0 : 0.0, $state === -1 ? 1.0 : 0.0]);
        self::append($result, [$expiry, $to, 0.0, 0.0, 1.0]);
    }

    public static function append(array &$result, array $interval): void {
        if ($interval[1] <= $interval[0]) { return; }
        $last = count($result) - 1;
        if ($last >= 0 && $result[$last][1] === $interval[0]
                && abs($result[$last][2] - $interval[2]) < 1e-10
                && abs($result[$last][3] - $interval[3]) < 1e-10
                && abs($result[$last][4] - $interval[4]) < 1e-10) {
            $result[$last][1] = $interval[1];
        }
        else { $result[] = $interval; }
    }

    /** Sweep interval boundaries, avoiding double counting overlaps. Weights apply only to mean. */
    public static function combine(array $series, string $mode, int $from, int $to, array $weights = []): array {
        if (!$series || $to <= $from) { return self::unknown($from, $to); }
        $events = [];
        $total = 0.0;
        foreach ($series as $index => $intervals) {
            $weight = $mode === 'mean' ? ($weights[$index] ?? 1.0) : 1.0;
            $total += $weight;
            foreach ($intervals ?: self::unknown($from, $to) as $interval) {
                if ($interval[1] <= $interval[0]) { continue; }
                foreach ([$interval[0] => 1, $interval[1] => -1] as $clock => $sign) {
                    if (!isset($events[$clock])) { $events[$clock] = [0.0, 0.0, 0.0]; }
                    for ($s = 0; $s < 3; $s++) { $events[$clock][$s] += $sign * $weight * $interval[$s + 2]; }
                }
            }
        }
        if ($total <= 0) { return self::unknown($from, $to); }
        ksort($events, SORT_NUMERIC);
        $current = [0.0, 0.0, 0.0];
        $cursor = $from;
        $result = [];
        foreach ($events as $clock => $delta) {
            $end = min($to, (int) $clock);
            if ($end > $cursor) {
                if ($mode === 'any_down') {
                    $down = $current[1] > 1e-8;
                    $unknown = !$down && $current[2] > 1e-8;
                    $fractions = [$down || $unknown ? 0.0 : 1.0, $down ? 1.0 : 0.0, $unknown ? 1.0 : 0.0];
                }
                else {
                    $fractions = array_map(static function($n) use ($total) { return max(0.0, min(1.0, $n / $total)); }, $current);
                }
                self::append($result, [$cursor, $end, $fractions[0], $fractions[1], $fractions[2]]);
                $cursor = $end;
            }
            for ($s = 0; $s < 3; $s++) { $current[$s] += $delta[$s]; }
        }
        return $result;
    }

    public static function summary(array $series, int $from, int $to): array {
        $durations = [0.0, 0.0, 0.0];
        foreach ($series as $interval) {
            $seconds = max(0, min($to, $interval[1]) - max($from, $interval[0]));
            for ($s = 0; $s < 3; $s++) { $durations[$s] += $seconds * $interval[$s + 2]; }
        }
        $total = $to - $from;
        $known = $durations[0] + $durations[1];
        return ['up' => $durations[0], 'down' => $durations[1], 'unknown' => $durations[2],
            'score' => $total > 0 && $durations[2] < 1e-6 ? 100 * $durations[0] / $total : null,
            'observed' => $known > 0 ? 100 * $durations[0] / $known : null,
            'coverage' => $total > 0 ? 100 * $known / $total : 0,
            'lower' => $total > 0 ? 100 * $durations[0] / $total : null,
            'upper' => $total > 0 ? 100 * ($durations[0] + $durations[2]) / $total : null];
    }
}
