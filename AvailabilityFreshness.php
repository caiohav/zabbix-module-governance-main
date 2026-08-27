<?php

namespace Modules\Governance;

/** Resolve a separate, auditable validity policy for every selected item; never expand macros. */
final class AvailabilityFreshness {
    const MAX_AGE = 86400;

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
            'warnings' => $warnings];
    }

    private static function automatic(array $item): array {
        $result = ['max_age' => null, 'freshness_mode' => 'auto', 'freshness_source' => 'unresolved',
            'interval_seconds' => self::duration($item['delay'] ?? null), 'heartbeat_seconds' => null, 'warnings' => []];
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
            // Zabbix 6.0 preprocessing: 19 = discard unchanged; 20 = discard unchanged with heartbeat.
            if ((int) $step['type'] === 19) {
                return self::unresolved($result,
                    'Discard unchanged has no heartbeat; set manual validity / Descartar inalterado não tem heartbeat; defina a validade manual.');
            }
            if ((int) $step['type'] === 20) {
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
                'Update interval is missing, a macro or a custom schedule; set manual validity / Intervalo de coleta ausente, macro ou agendamento personalizado; defina a validade manual.');
        }

        // Heartbeat fires at a collected value, not on a separate timer. Two polling intervals cover
        // the next collection plus one late collection; regular items keep the existing 3-poll grace.
        $interval = $result['interval_seconds'];
        $age = max(3 * $interval, ($result['heartbeat_seconds'] ?? 0) + 2 * $interval);
        if ($age > self::MAX_AGE) {
            return self::unresolved($result,
                'Automatic validity exceeds 86400 s; review collection or set a manual policy / Validade automática excede 86400 s; revise a coleta ou defina uma política manual.');
        }
        $result['max_age'] = $age;
        $result['freshness_source'] = $heartbeatCount ? 'heartbeat' : 'interval';
        return $result;
    }

    private static function unresolved(array $result, string $warning): array {
        $result['max_age'] = null;
        $result['freshness_source'] = 'unresolved';
        $result['warnings'][] = $warning;
        return $result;
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
