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
                && $result[$last][2] == $interval[2]
                && $result[$last][3] == $interval[3]
                && $result[$last][4] == $interval[4]) {
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
            if ($weight <= 0) { continue; }
            $total += $weight;
            foreach ($intervals ?: self::unknown($from, $to) as $interval) {
                if ($interval[1] <= $interval[0]) { continue; }
                foreach ([$interval[0] => 1, $interval[1] => -1] as $clock => $sign) {
                    if (!isset($events[$clock])) { $events[$clock] = [0.0, 0.0, 0.0, 0, 0, 0]; }
                    for ($s = 0; $s < 3; $s++) {
                        $events[$clock][$s] += $sign * $weight * $interval[$s + 2];
                        $events[$clock][$s + 3] += $interval[$s + 2] > 0 ? $sign : 0;
                    }
                }
            }
        }
        if ($total <= 0) { return self::unknown($from, $to); }
        ksort($events, SORT_NUMERIC);
        $current = [0.0, 0.0, 0.0];
        $active = [0, 0, 0];
        $cursor = $from;
        $result = [];
        foreach ($events as $clock => $delta) {
            $end = min($to, (int) $clock);
            if ($end > $cursor) {
                if ($mode === 'any_down' || $mode === 'any_down_observed') {
                    $down = $active[1] > 0;
                    // Observed cohorts ignore unknown hosts, not required checks.
                    // No known host must remain unknown rather than becoming UP.
                    $unknown = !$down && ($mode === 'any_down_observed' ? $active[0] <= 0 : $active[2] > 0);
                    $fractions = [$down || $unknown ? 0.0 : 1.0, $down ? 1.0 : 0.0, $unknown ? 1.0 : 0.0];
                }
                else {
                    $fractions = array_map(static function($n) use ($total) { return max(0.0, min(1.0, $n / $total)); }, $current);
                }
                self::append($result, [$cursor, $end, $fractions[0], $fractions[1], $fractions[2]]);
                $cursor = $end;
            }
            for ($s = 0; $s < 3; $s++) {
                $current[$s] += $delta[$s];
                $active[$s] += $delta[$s + 3];
                // Remove arithmetic residue only when no actual source has this state.
                // An epsilon here would erase real gaps/outages of low-weight children.
                if ($active[$s] === 0) { $current[$s] = 0.0; }
            }
        }
        return $result;
    }

    public static function summary(array $series, int $from, int $to): array {
        $durations = [0.0, 0.0, 0.0];
        $covered = 0;
        foreach ($series as $interval) {
            $seconds = max(0, min($to, $interval[1]) - max($from, $interval[0]));
            $covered += $seconds;
            for ($s = 0; $s < 3; $s++) { $durations[$s] += $seconds * $interval[$s + 2]; }
        }
        $total = $to - $from;
        if ($total > $covered) { $durations[2] += $total - $covered; }
        $known = $durations[0] + $durations[1];
        $complete = $covered === $total;
        $lower = $total > 0 ? self::percentage($durations[0], $durations[1] + $durations[2], $total, $complete) : null;
        return ['up' => $durations[0], 'down' => $durations[1], 'unknown' => $durations[2],
            'score' => $total > 0 && $complete && $durations[2] === 0.0 ? $lower : null,
            'observed' => $known > 0 ? self::percentage($durations[0], $durations[1], $known) : null,
            'coverage' => $total > 0 ? self::percentage($known, $durations[2], $total, $complete) : 0,
            'lower' => $lower,
            'upper' => $total > 0 ? self::percentage($durations[0] + $durations[2], $durations[1], $total, $complete) : null];
    }

    /** Weighted monthly totals only. This does not reconstruct daily states or outage intervals. */
    public static function weightedSummaries(array $summaries, array $weights, float $basis): array {
        if (!$summaries || count($summaries) !== count($weights) || $basis <= 0 || !is_finite($basis)) {
            throw new \InvalidArgumentException('Invalid monthly aggregation basis.');
        }
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0 || !is_finite($totalWeight)) { throw new \InvalidArgumentException('Invalid weights.'); }
        $durations = ['up' => 0.0, 'down' => 0.0, 'unknown' => 0.0];
        $complete = true;
        foreach ($summaries as $index => $summary) {
            if (!is_numeric($weights[$index]) || $weights[$index] <= 0 || !is_finite((float) $weights[$index])) {
                throw new \InvalidArgumentException('Invalid weight.');
            }
            foreach ($durations as $state => $unused) {
                if (!isset($summary[$state]) || !is_numeric($summary[$state])
                        || !is_finite((float) $summary[$state]) || $summary[$state] < 0) {
                    throw new \InvalidArgumentException('Invalid source totals.');
                }
                $durations[$state] += $summary[$state] * ($weights[$index] / $totalWeight);
            }
            // Item means accumulate fractional seconds over many intervals. Permit
            // floating-point residue relative to the month, without rounding away
            // any real unknown/down duration or accepting a different denominator.
            if (abs($summary['up'] + $summary['down'] + $summary['unknown'] - $basis) > max(0.000001, $basis * 1e-10)) {
                throw new \InvalidArgumentException('Incompatible monthly totals.');
            }
            if ($summary['score'] === null) { $complete = false; }
        }
        $known = $durations['up'] + $durations['down'];
        $lower = self::percentage($durations['up'], $durations['down'] + $durations['unknown'], $basis);
        return $durations + [
            'score' => $complete && $durations['unknown'] === 0.0 ? $lower : null,
            'observed' => $known > 0 ? self::percentage($durations['up'], $durations['down'], $known) : null,
            'coverage' => self::percentage($known, $durations['unknown'], $basis), 'lower' => $lower,
            'upper' => self::percentage($durations['up'] + $durations['unknown'], $durations['down'], $basis)];
    }

    /**
     * Mean of observed indicators, not pooled durations. Missing scores do not
     * participate in the score; every source retains its weight in coverage.
     */
    public static function weightedIndicators(array $indicators, array $weights = []): array {
        $indicators = array_values($indicators);
        $weights = $weights ? array_values($weights) : array_fill(0, count($indicators), 1.0);
        if (count($indicators) !== count($weights)) {
            throw new \InvalidArgumentException('Indicator and weight counts differ.');
        }
        $totalWeight = 0.0; $totalCorrection = 0.0;
        $participatingWeight = 0.0; $participatingCorrection = 0.0;
        $participants = 0; $complete = (bool) $indicators;
        foreach ($indicators as $index => $indicator) {
            if (!is_array($indicator) || !array_key_exists('score', $indicator)
                    || !isset($indicator['coverage']) || !is_numeric($indicator['coverage'])
                    || !is_finite((float) $indicator['coverage'])
                    || $indicator['coverage'] < 0 || $indicator['coverage'] > 100
                    || $indicator['score'] !== null && (!is_numeric($indicator['score'])
                        || !is_finite((float) $indicator['score'])
                        || $indicator['score'] < 0 || $indicator['score'] > 100)) {
                throw new \InvalidArgumentException('Invalid observed indicator.');
            }
            if (!is_numeric($weights[$index]) || !is_finite((float) $weights[$index]) || $weights[$index] <= 0) {
                throw new \InvalidArgumentException('Invalid indicator weight.');
            }
            $weights[$index] = (float) $weights[$index];
            self::addCompensated($totalWeight, $totalCorrection, $weights[$index]);
            if ($indicator['score'] !== null) {
                self::addCompensated($participatingWeight, $participatingCorrection, $weights[$index]);
                $participants++;
            }
            if ($indicator['score'] === null || (float) $indicator['coverage'] !== 100.0) { $complete = false; }
        }
        if (!is_finite($totalWeight) || !is_finite($participatingWeight)) {
            throw new \InvalidArgumentException('Indicator weight sum is not finite.');
        }
        $result = ['score' => null, 'coverage' => 0.0, 'participating_weight' => $participatingWeight,
            'total_weight' => $totalWeight, 'participants' => $participants,
            'total_sources' => count($indicators), 'complete' => $complete];
        if (!$indicators) { return $result; }

        $score = 0.0; $scoreCorrection = 0.0; $deficit = 0.0; $deficitCorrection = 0.0;
        $coverage = 0.0; $coverageCorrection = 0.0; $gap = 0.0; $gapCorrection = 0.0;
        $hasDeficit = false; $hasGap = false;
        foreach ($indicators as $index => $indicator) {
            $coverageFraction = $weights[$index] / $totalWeight;
            self::addCompensated($coverage, $coverageCorrection, $indicator['coverage'] * $coverageFraction);
            self::addCompensated($gap, $gapCorrection, (100 - $indicator['coverage']) * $coverageFraction);
            $hasGap = $hasGap || $indicator['coverage'] < 100;
            if ($indicator['score'] === null) { continue; }
            $scoreFraction = $weights[$index] / $participatingWeight;
            self::addCompensated($score, $scoreCorrection, $indicator['score'] * $scoreFraction);
            self::addCompensated($deficit, $deficitCorrection, (100 - $indicator['score']) * $scoreFraction);
            $hasDeficit = $hasDeficit || $indicator['score'] < 100;
        }
        // Accumulate the complements separately: tiny real losses near 100 must
        // survive a dominant weight. No epsilon can discard a score or data gap.
        $result['score'] = $participants > 0 ? self::percentage($score, $deficit, 100.0) : null;
        $result['coverage'] = self::percentage($coverage, $gap, 100.0);
        if ($hasDeficit) { $result['score'] = min(99.99999999999999, $result['score']); }
        if ($hasGap) { $result['coverage'] = min(99.99999999999999, $result['coverage']); }
        return $result;
    }

    private static function addCompensated(float &$sum, float &$correction, float $value): void {
        $adjusted = $value - $correction;
        $next = $sum + $adjusted;
        $correction = ($next - $sum) - $adjusted;
        $sum = $next;
    }

    private static function percentage(float $included, float $excluded, float $total, bool $complete = true): float {
        // Use the smaller component: subtraction near zero loses precision, while a
        // sum near 100% can retain rounding residue from many weighted intervals.
        $percentage = 100 * ($complete && $included > $excluded
            ? 1 - $excluded / $total : $included / $total);
        $percentage = max(0.0, min(100.0, $percentage));
        // A real nonzero excluded duration must never become a displayed 100%,
        // even when its percentage is smaller than one IEEE-754 step below 100.
        return $excluded > 0.0 ? min(99.99999999999999, $percentage) : $percentage;
    }
}
