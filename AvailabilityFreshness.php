<?php

namespace Modules\Governance;

/** Resolve a separate, auditable validity policy for every selected item; never expand macros. */
final class AvailabilityFreshness {
    const MAX_AGE = 86400;
    // Availability is evaluated as a state, not as a count of collected rows.
    // Keep a real sample as evidence for at least one hour; a newer sample always
    // replaces it immediately. This aligns frequent checks (for example ICMP)
    // with hourly heartbeat items without converting missing history into UP.
    const MIN_AUTOMATIC_AGE = 3600;
    const MAX_DELAY_LENGTH = 4096;
    const MAX_FLEXIBLE_INTERVALS = 128;

    public static function resolve(array $item, ?int $manualAge): array {
        $automatic = self::automatic($item);
        if ($manualAge === null) { return $automatic; }

        $warnings = [];
        if ($manualAge < 1 || $manualAge > self::MAX_AGE) {
            $automatic['freshness_mode'] = 'manual';
            return self::unresolved($automatic,
                'Invalid manual validity / Validade manual inválida (1–86400 s).');
        }
        if ($automatic['max_age'] !== null && $manualAge < $automatic['max_age']) {
            $warnings[] = 'Manual validity is below the automatic estimate (' . $automatic['max_age']
                . ' s); gaps may appear / A validade manual está abaixo da estimativa automática ('
                . $automatic['max_age'] . ' s); podem aparecer lacunas.';
        }
        elseif ($automatic['heartbeat_seconds'] !== null && $manualAge < $automatic['heartbeat_seconds']) {
            $warnings[] = 'Manual validity is shorter than the heartbeat (' . $automatic['heartbeat_seconds']
                . ' s); gaps may appear / A validade manual é menor que o heartbeat ('
                . $automatic['heartbeat_seconds'] . ' s); podem aparecer lacunas.';
        }
        return ['max_age' => $manualAge, 'freshness_mode' => 'manual', 'freshness_source' => 'manual',
            'interval_seconds' => $automatic['interval_seconds'], 'heartbeat_seconds' => $automatic['heartbeat_seconds'],
            'automatic_minimum_seconds' => self::MIN_AUTOMATIC_AGE,
            'warnings' => $warnings];
    }

    private static function automatic(array $item): array {
        $polling = self::polling($item['delay'] ?? null);
        $result = ['max_age' => null, 'freshness_mode' => 'auto', 'freshness_source' => 'unresolved',
            'interval_seconds' => $polling['seconds'], 'heartbeat_seconds' => null,
            'automatic_minimum_seconds' => self::MIN_AUTOMATIC_AGE, 'warnings' => []];
        if (!array_key_exists('preprocessing', $item) || !is_array($item['preprocessing'])) {
            return self::unresolved($result,
                'Preprocessing metadata unavailable; set manual validity / Pré-processamento não disponível; defina a validade manual.');
        }
        $heartbeatCount = 0;
        foreach ($item['preprocessing'] as $step) {
            if (!is_array($step) || !isset($step['type'])) {
                return self::unresolved($result,
                    'Invalid preprocessing metadata; set manual validity / Pré-processamento inválido; defina a validade manual.');
            }
            // Zabbix 6.0 fallback values: 19 = discard unchanged; 20 = discard
            // unchanged with heartbeat. Prefer the frontend constants when present.
            if ((int) $step['type'] === self::discardUnchangedType()) {
                return self::unresolved($result,
                    'Discard unchanged has no heartbeat; set manual validity / Descartar inalterado não tem heartbeat; defina a validade manual.');
            }
            if ((int) $step['type'] === self::discardHeartbeatType()) {
                $result['heartbeat_seconds'] = self::duration($step['params'] ?? null);
                if (++$heartbeatCount > 1 || $result['heartbeat_seconds'] === null) {
                    return self::unresolved($result,
                        'Heartbeat is a macro or an unsupported interval; set manual validity / Heartbeat é macro ou intervalo não suportado; defina a validade manual.');
                }
            }
        }

        // Push and dependent items do not have an independently verifiable polling cadence.
        if (!isset($item['type']) || !in_array((int) $item['type'], [0, 3, 5, 7, 9, 10, 11, 12, 13, 14, 15, 16, 19, 20, 21], true)
                || ((int) $item['type'] === 7 && preg_match('/^mqtt\.get(?:\[|$)/', $item['key_'] ?? ''))) {
            return self::unresolved($result,
                'Item has no independent polling interval; set manual validity / Item sem intervalo de coleta próprio; defina a validade manual.');
        }
        if ($result['interval_seconds'] === null) {
            return self::unresolved($result,
                'Update interval is missing, invalid, a macro, includes a disabled period or a scheduling expression; set manual validity / Intervalo de coleta ausente, inválido, com macro, período desabilitado ou expressão de agendamento; defina a validade manual.');
        }

        // Heartbeat fires at a collected value, not on a separate timer. Two polling
        // intervals cover the next collection plus one late collection. Every item
        // retains at least one hour of real evidence; frequent values still replace
        // the previous state immediately, including a transition to DOWN.
        $interval = $result['interval_seconds'];
        $age = max(self::MIN_AUTOMATIC_AGE, 3 * $interval,
            ($result['heartbeat_seconds'] ?? 0) + 2 * $interval);
        if ($age > self::MAX_AGE) {
            return self::unresolved($result,
                'Automatic validity exceeds 86400 s; review collection or set a manual policy / Validade automática excede 86400 s; revise a coleta ou defina uma política manual.');
        }
        $result['max_age'] = $age;
        $result['freshness_source'] = $polling['flexible']
            ? ($heartbeatCount ? 'heartbeat_flexible_interval' : 'flexible_interval')
            : ($heartbeatCount ? 'heartbeat' : 'interval');
        return $result;
    }

    /**
     * Numeric subset of the Zabbix 6.0 delay syntax: base;interval/day[-day],time-time.
     * Every period must be valid and every interval positive. A zero base or zero
     * flexible interval can suspend polling; neither has a safe automatic bound here.
     * Overlapping flex periods select the smallest interval in Zabbix. Taking the
     * largest positive interval (including the base) is a conservative cadence bound
     * without evaluating today's calendar or inferring policy from stored samples.
     */
    private static function polling($value): array {
        $unresolved = ['seconds' => null, 'flexible' => false];
        if ((!is_string($value) && !is_int($value))
                || strlen((string) $value) > self::MAX_DELAY_LENGTH) { return $unresolved; }
        $value = trim((string) $value);
        if (strpos($value, ';') === false) {
            return ['seconds' => self::duration($value), 'flexible' => false];
        }
        $parts = explode(';', $value);
        if (count($parts) > self::MAX_FLEXIBLE_INTERVALS + 1
                || !preg_match('/^[1-9][0-9]*[smhdw]?$/D', $parts[0])) { return $unresolved; }
        $maximum = self::duration(array_shift($parts));
        if ($maximum === null) { return $unresolved; }
        foreach ($parts as $part) {
            // A scheduling expression, macro, empty/trailing segment or extra slash
            // cannot be ignored just because the base interval was understandable.
            if (!preg_match('/^([1-9][0-9]*[smhdw]?)\/(.+)$/D', $part, $match)
                    || !self::flexiblePeriod($match[2])) { return $unresolved; }
            $seconds = self::duration($match[1]);
            if ($seconds === null) { return $unresolved; }
            $maximum = max($maximum, $seconds);
        }
        return ['seconds' => $maximum, 'flexible' => true];
    }

    /** Match the numeric CTimePeriodParser grammar and its weekday/time bounds. */
    private static function flexiblePeriod(string $period): bool {
        if (!preg_match('/^([1-7])(?:-([1-7]))?,([0-9]{1,2}):([0-9]{2})-([0-9]{1,2}):([0-9]{2})$/D',
                $period, $parts)) { return false; }
        if (($parts[2] !== '' && (int) $parts[1] > (int) $parts[2])
                || (int) $parts[4] > 59 || (int) $parts[6] > 59) { return false; }
        $from = (int) $parts[3] * 60 + (int) $parts[4];
        $to = (int) $parts[5] * 60 + (int) $parts[6];
        return $from < $to && $to <= 24 * 60;
    }

    private static function unresolved(array $result, string $warning): array {
        $result['max_age'] = null;
        $result['freshness_source'] = 'unresolved';
        $result['warnings'][] = $warning;
        return $result;
    }

    private static function discardUnchangedType(): int {
        foreach (['ZBX_PREPROC_THROTTLE_VALUE', 'ZBX_PREPROC_DISCARD_UNCHANGED'] as $name) {
            if (defined($name)) { return (int) constant($name); }
        }
        return 19;
    }

    private static function discardHeartbeatType(): int {
        foreach (['ZBX_PREPROC_THROTTLE_TIMED_VALUE', 'ZBX_PREPROC_DISCARD_UNCHANGED_HEARTBEAT'] as $name) {
            if (defined($name)) { return (int) constant($name); }
        }
        return 20;
    }

    private static function duration($value): ?int {
        if ((!is_string($value) && !is_int($value))
                || !preg_match('/^([0-9]+)([smhdw]?)$/D', trim((string) $value), $parts)) { return null; }
        $multipliers = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
        $seconds = (float) $parts[1] * $multipliers[$parts[2]];
        if ($seconds < 1 || $seconds > 2147483647) { return null; }
        return (int) $seconds;
    }
}
