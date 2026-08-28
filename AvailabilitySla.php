<?php

namespace Modules\Governance;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Pure adapter for the Zabbix 6.0 SLA API. The caller owns API access and checkpoints.
 *
 * Native SLA scores describe scheduled time, not a reconstructed host timeline. Never
 * turn a monthly percentage into intervals, a daily chart, or an interruption count.
 */
final class AvailabilitySla {
    const MONTHLY = 2;
    const MAX_TIMESTAMP = 2147483647;
    const MAX_CALENDAR_ROWS = 10000;
    private const NO_SLI = 1;

    /**
     * $slaResponse and $serviceResponse are the respective get() result collections.
     * Required SLA output: slaid, name, period, slo, effective_date, timezone, status;
     * selectSchedule: period_from, period_to; selectExcludedDowntimes: name and bounds.
     * Required service output: serviceid, name, created_at. IDs are never array offsets.
     *
     * For timezone=system the caller must pass CTimezoneHelper::getSystemTimezone(),
     * not the user's/report's timezone or PHP's potentially user-adjusted default.
     */
    public static function prepare(array $technology, array $report, $slaResponse, $serviceResponse,
            ?string $systemTimezone = null): array {
        $prepared = ['ready' => false, 'request' => null, 'eligible_for_aggregation' => false,
            'metadata' => ['source' => 'sla', 'coverage_kind' => 'scheduled', 'state' => 'unavailable'],
            'warnings' => [], 'summary' => self::unknownSummary(null)];
        try {
            if (($technology['source'] ?? '') !== 'sla') {
                throw new RuntimeException('Native SLA source was not selected / A fonte SLA nativo não foi selecionada.');
            }
            $slaid = self::id($technology['slaid'] ?? null);
            $serviceid = self::id($technology['serviceid'] ?? null);
            $prepared['metadata']['slaid'] = $slaid;
            $prepared['metadata']['serviceid'] = $serviceid;

            $month = $report['month'] ?? null;
            if (!is_string($month) || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month)) {
                throw new RuntimeException('Invalid SLA report month / Mês do relatório SLA inválido.');
            }
            $reportTimezone = self::timezone($report['timezone'] ?? null);
            $reportStart = new DateTimeImmutable($month . '-01 00:00:00', $reportTimezone);
            $reportFrom = self::integer($report['from'] ?? null, 0, self::MAX_TIMESTAMP);
            $reportTo = self::integer($report['to'] ?? null, 0, self::MAX_TIMESTAMP);
            $generatedAt = self::integer($report['generated_at'] ?? null, 0, PHP_INT_MAX);
            if ($reportFrom !== $reportStart->getTimestamp() || $reportTo <= $reportFrom) {
                throw new RuntimeException('Invalid SLA report boundaries / Limites do relatório SLA inválidos.');
            }
            $reportEnd = $reportStart->modify('+1 month')->getTimestamp();
            if ($reportTo !== $reportEnd || $generatedAt < $reportEnd || !empty($report['partial'])) {
                throw new RuntimeException('Native SLA requires a closed month; the API cannot freeze an in-progress month at the report cutoff / O SLA nativo exige um mês encerrado; a API não fixa um mês em andamento no corte do relatório.');
            }

            $sla = self::record($slaResponse, 'slaid', $slaid,
                'SLA not found or not accessible / SLA não encontrado ou sem acesso.');
            $prepared['metadata']['sla_name'] = self::text($sla['name'] ?? null);
            $prepared['metadata']['period'] = self::integer($sla['period'] ?? null, 0, 4);
            $prepared['metadata']['status'] = self::integer($sla['status'] ?? null, 0, 1);
            $prepared['metadata']['slo'] = self::number($sla['slo'] ?? null, 0, 100);
            $prepared['metadata']['effective_date'] = self::integer($sla['effective_date'] ?? null, 0, self::MAX_TIMESTAMP);
            if ($prepared['metadata']['status'] !== 1) {
                throw new RuntimeException('Selected SLA is disabled / O SLA selecionado está desabilitado.');
            }
            if ($prepared['metadata']['period'] !== self::MONTHLY) {
                throw new RuntimeException('Select an SLA with a Monthly reporting period / Selecione um SLA com período de relatório Mensal.');
            }

            $configuredTimezone = self::text($sla['timezone'] ?? null);
            $timezone = self::timezone($configuredTimezone === 'system' ? $systemTimezone : $configuredTimezone);
            $start = new DateTimeImmutable($month . '-01 00:00:00', $timezone);
            $from = $start->getTimestamp();
            $to = $start->modify('+1 month')->getTimestamp();
            if ($from < 0 || $to > self::MAX_TIMESTAMP || $to > $generatedAt) {
                throw new RuntimeException('Native SLA month has not ended in its timezone or exceeds the supported range / O mês do SLA nativo ainda não encerrou em seu fuso ou excede o intervalo suportado.');
            }
            $prepared['metadata'] += ['timezone_configured' => $configuredTimezone,
                'timezone' => $timezone->getName(), 'period_from' => $from, 'period_to' => $to,
                'period_seconds' => $to - $from];
            if ($prepared['metadata']['effective_date'] > $from) {
                throw new RuntimeException('SLA effective date is after the start of the selected month; a full-month indicator is unavailable / A vigência do SLA começa após o início do mês selecionado; o indicador mensal completo está indisponível.');
            }

            $service = self::record($serviceResponse, 'serviceid', $serviceid,
                'Service not found, not associated with the SLA, or not accessible / Serviço não encontrado, não associado ao SLA ou sem acesso.');
            $prepared['metadata']['service_name'] = self::text($service['name'] ?? null);
            $prepared['metadata']['service_created_at'] = self::integer($service['created_at'] ?? null, 0, self::MAX_TIMESTAMP);
            if ($prepared['metadata']['service_created_at'] > $from) {
                throw new RuntimeException('Service was created after the start of the selected month; its partial SLI cannot represent the full month / O serviço foi criado após o início do mês selecionado; seu SLI parcial não representa o mês completo.');
            }

            if (!isset($sla['schedule'], $sla['excluded_downtimes'])
                    || !is_array($sla['schedule']) || !is_array($sla['excluded_downtimes'])) {
                throw new RuntimeException('SLA schedule or exclusions were not returned / O calendário ou as exclusões do SLA não foram retornados.');
            }
            $schedule = self::schedule($sla['schedule']);
            $excluded = self::exclusions($sla['excluded_downtimes'], $from, $to);
            $scheduled = self::scheduledPeriods($from, $to, $timezone, $schedule);
            $scheduledSeconds = self::duration($scheduled);
            $overlapSeconds = $scheduledSeconds - self::duration(self::union($scheduled));
            $excludedSeconds = self::intersectionDuration($scheduled, self::union($excluded));
            $basisSeconds = $scheduledSeconds - $excludedSeconds;
            $prepared['metadata'] += ['schedule' => $schedule, 'schedule_kind' => $schedule ? 'custom' : '24x7',
                'excluded_downtimes' => $excluded, 'scheduled_seconds' => $scheduledSeconds,
                'excluded_seconds' => $excludedSeconds, 'basis_seconds' => $basisSeconds,
                'calendar_overlap_seconds' => $overlapSeconds,
                'expected_seconds' => $basisSeconds,
                'calendar_key' => self::calendarKey($from, $to, $schedule, $excluded, $timezone->getName())];
            $prepared['summary'] = self::unknownSummary($basisSeconds);
            $prepared['eligible_for_aggregation'] = $from === $reportFrom && $to === $reportTo && $basisSeconds > 0;
            if ($from !== $reportFrom || $to !== $reportTo) {
                $prepared['warnings'][] = 'Native SLA uses different absolute month boundaries (' . $timezone->getName()
                    . '); its individual SLI is shown but cannot enter the department indicator. Align the report timezone / '
                    . 'O SLA nativo usa limites absolutos de mês diferentes (' . $timezone->getName()
                    . '); o SLI individual é exibido, mas não pode compor o indicador do departamento. Alinhe o fuso do relatório.';
            }
            if ($basisSeconds === 0) {
                $prepared['warnings'][] = 'No scheduled time remains after SLA exclusions / Não há tempo programado após as exclusões do SLA.';
            }
            if ($overlapSeconds > 0) {
                $prepared['warnings'][] = 'Native schedule slots overlap in elapsed time around a timezone change; durations follow the native SLA calculation / Faixas do calendário nativo se sobrepõem no tempo decorrido em uma mudança de fuso; as durações seguem o cálculo nativo do SLA.';
            }
            $prepared['request'] = ['slaid' => $slaid, 'serviceids' => [$serviceid],
                'period_from' => $from, 'period_to' => $to - 1, 'periods' => 1];
            $prepared['metadata']['state'] = 'ready';
            $prepared['ready'] = true;
        }
        catch (RuntimeException $e) {
            $prepared['warnings'][] = $e->getMessage();
        }
        return $prepared;
    }

    /**
     * Interpret only the service ID and exact native period requested by prepare().
     * Request period_to is inclusive; the returned period_to is exclusive in Zabbix.
     * Unavailable evidence is not an uptime measurement. Malformed/inconsistent API
     * responses additionally carry processing_error and state=invalid_response; the
     * caller must fail processing rather than publish them as a completed report.
     */
    public static function interpret(array $prepared, $response): array {
        $result = ['summary' => $prepared['summary'], 'metadata' => $prepared['metadata'],
            'eligible_for_aggregation' => false, 'warnings' => $prepared['warnings']];
        if (!$prepared['ready']) { return $result; }
        $result['metadata']['state'] = 'unavailable';
        try {
            if (!is_array($response) || !isset($response['periods'], $response['serviceids'], $response['sli'])
                    || !is_array($response['periods']) || !is_array($response['serviceids'])
                    || !is_array($response['sli']) || count($response['periods']) > 100) {
                throw new RuntimeException('Invalid or unavailable SLA API response / Resposta da API de SLA inválida ou indisponível.');
            }
            $serviceIndex = null;
            $serviceIds = [];
            foreach ($response['serviceids'] as $index => $id) {
                $id = self::id($id);
                if (isset($serviceIds[$id])) {
                    throw new RuntimeException('Duplicate service ID in SLA response / ID de serviço duplicado na resposta do SLA.');
                }
                $serviceIds[$id] = true;
                if ($id === $prepared['metadata']['serviceid']) { $serviceIndex = $index; }
            }
            $periodIndex = null;
            $periods = [];
            foreach ($response['periods'] as $index => $period) {
                if (!is_array($period)) {
                    throw new RuntimeException('Invalid SLA response period / Período inválido na resposta do SLA.');
                }
                $from = self::integer($period['period_from'] ?? null, 0, self::MAX_TIMESTAMP);
                $to = self::integer($period['period_to'] ?? null, 0, self::MAX_TIMESTAMP);
                if ($from >= $to || isset($periods[$from . ':' . $to])) {
                    throw new RuntimeException('Invalid or duplicate SLA response period / Período inválido ou duplicado na resposta do SLA.');
                }
                $periods[$from . ':' . $to] = true;
                if ($from === $prepared['metadata']['period_from'] && $to === $prepared['metadata']['period_to']) {
                    $periodIndex = $index;
                }
            }
            if ($serviceIndex === null) {
                throw new RuntimeException('Selected service is absent from the SLA result; check its SLA association and access / O serviço selecionado não consta no resultado do SLA; confira a associação ao SLA e o acesso.', self::NO_SLI);
            }
            if ($periodIndex === null) {
                throw new RuntimeException('SLA API did not return the exact requested month; no substitute period is used / A API de SLA não retornou o mês exato solicitado; nenhum período substituto é utilizado.', self::NO_SLI);
            }
            $row = $response['sli'][$periodIndex] ?? null;
            $cell = is_array($row) ? ($row[$serviceIndex] ?? null) : null;
            if (!is_array($cell)) {
                throw new RuntimeException('Selected SLA result cell is missing / A célula do resultado SLA selecionado está ausente.');
            }
            $up = self::integer($cell['uptime'] ?? null, 0, self::MAX_TIMESTAMP);
            $down = self::integer($cell['downtime'] ?? null, 0, self::MAX_TIMESTAMP);
            $sli = self::number($cell['sli'] ?? null, -1, 100);
            $errorBudget = self::integer($cell['error_budget'] ?? null, -self::MAX_TIMESTAMP, self::MAX_TIMESTAMP);
            if (!isset($cell['excluded_downtimes']) || !is_array($cell['excluded_downtimes'])) {
                throw new RuntimeException('SLA response exclusions are missing / As exclusões da resposta SLA estão ausentes.');
            }
            $reportedExcluded = self::exclusions($cell['excluded_downtimes'],
                $prepared['metadata']['period_from'], $prepared['metadata']['period_to'], true);
            $total = $up + $down;
            $basis = $prepared['metadata']['basis_seconds'];
            $result['metadata'] += ['native_sli' => $sli, 'error_budget' => $errorBudget,
                'reported_excluded_downtimes' => $reportedExcluded];
            if ($sli === -1.0 && $total === 0) {
                throw new RuntimeException('Native SLA has no valid SLI for this month; no availability percentage is assumed / O SLA nativo não possui SLI válido neste mês; nenhum percentual de disponibilidade é presumido.', self::NO_SLI);
            }
            if ($total !== $basis) {
                throw new RuntimeException('SLA measured duration does not match its full monthly schedule; check calendar changes and service creation / A duração medida do SLA não corresponde ao calendário mensal completo; confira alterações no calendário e a criação do serviço.');
            }
            if ($total === 0 || $sli < 0) {
                throw new RuntimeException('Native SLI is inconsistent with its uptime and downtime / O SLI nativo é inconsistente com os tempos disponível e indisponível.');
            }
            // Retain the exact durations; display rounding must never become input to weighting.
            $score = 100 * ($up > $down ? 1 - $down / $total : $up / $total);
            $score = max(0.0, min(100.0, $score));
            if ($down > 0) { $score = min(99.99999999999999, $score); }
            if (abs($sli - $score) > 0.000001) {
                throw new RuntimeException('Native SLI is inconsistent with its uptime and downtime / O SLI nativo é inconsistente com os tempos disponível e indisponível.');
            }
            $result['summary'] = ['up' => (float) $up, 'down' => (float) $down, 'unknown' => 0.0,
                'score' => $score, 'observed' => $score, 'coverage' => 100.0, 'lower' => $score, 'upper' => $score];
            $result['metadata']['state'] = 'complete';
            $result['eligible_for_aggregation'] = $prepared['eligible_for_aggregation'];
        }
        catch (RuntimeException $e) {
            $result['warnings'][] = $e->getMessage();
            if ($e->getCode() !== self::NO_SLI) {
                $result['metadata']['state'] = 'invalid_response';
                $result['processing_error'] = $e->getMessage();
            }
        }
        return $result;
    }

    /**
     * Compatibility identity, not a cache key. Item-based 24x7 sources use
     * calendarKey($report['from'], $report['to']) with the defaults below.
     * Names/order/overlaps do not change a calendar; actual dates and custom timezone do.
     */
    public static function calendarKey(int $from, int $to, array $schedule = [], array $excluded = [],
            ?string $timezone = null): string {
        if ($from < 0 || $to <= $from || $to > self::MAX_TIMESTAMP) {
            throw new RuntimeException('Invalid aggregation calendar period / Período do calendário de agregação inválido.');
        }
        $schedule = self::schedule($schedule);
        $excluded = self::union(self::exclusions($excluded, $from, $to));
        $timezone = $schedule ? self::timezone($timezone)->getName() : null;
        return hash('sha256', json_encode([$from, $to, $schedule, $excluded, $timezone], JSON_UNESCAPED_SLASHES));
    }

    private static function unknownSummary(?int $seconds): array {
        return ['up' => $seconds === null ? null : 0.0, 'down' => $seconds === null ? null : 0.0,
            'unknown' => $seconds === null ? null : (float) $seconds, 'score' => null,
            'observed' => null, 'coverage' => 0.0, 'lower' => $seconds > 0 ? 0.0 : null,
            'upper' => $seconds > 0 ? 100.0 : null];
    }

    private static function record($response, string $field, string $id, string $missing): array {
        if (!is_array($response)) {
            throw new RuntimeException('Invalid or unavailable SLA/service metadata response / Resposta de metadados SLA/serviço inválida ou indisponível.');
        }
        $found = null;
        foreach ($response as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('Malformed SLA/service record / Registro SLA/serviço malformado.');
            }
            if (self::id($record[$field] ?? null) === $id) {
                if ($found !== null) {
                    throw new RuntimeException('Duplicate SLA/service record / Registro SLA/serviço duplicado.');
                }
                $found = $record;
            }
        }
        if ($found === null) { throw new RuntimeException($missing); }
        return $found;
    }

    private static function id($value): string {
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('Invalid SLA/service ID / ID de SLA/serviço inválido.');
        }
        $id = ltrim((string) $value, '0');
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)
                || strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0) {
            throw new RuntimeException('Invalid SLA/service ID / ID de SLA/serviço inválido.');
        }
        return $id;
    }

    private static function integer($value, int $min, int $max): int {
        if (is_int($value)) { $valid = $value >= $min && $value <= $max; }
        elseif (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value)) {
            $valid = filter_var($value, FILTER_VALIDATE_INT) !== false;
            if ($valid) { $value = (int) $value; $valid = $value >= $min && $value <= $max; }
        }
        else { $valid = false; }
        if (!$valid) { throw new RuntimeException('Invalid numeric SLA field / Campo numérico do SLA inválido.'); }
        return $value;
    }

    private static function number($value, float $min, float $max): float {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
                || !is_numeric($value) || is_string($value) && trim($value) !== $value) {
            throw new RuntimeException('Invalid numeric SLA field / Campo numérico do SLA inválido.');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < $min || $number > $max) {
            throw new RuntimeException('Invalid numeric SLA field / Campo numérico do SLA inválido.');
        }
        return $number;
    }

    private static function text($value): string {
        if (!is_string($value) || strlen($value) > 4096) {
            throw new RuntimeException('Invalid textual SLA field / Campo textual do SLA inválido.');
        }
        return $value;
    }

    private static function timezone($value): DateTimeZone {
        if (!is_string($value) || $value === '' || $value === 'system') {
            throw new RuntimeException('SLA timezone could not be resolved; system timezone must be supplied explicitly / Não foi possível resolver o fuso do SLA; o fuso do sistema deve ser fornecido explicitamente.');
        }
        try { return new DateTimeZone($value); }
        catch (\Exception $e) {
            throw new RuntimeException('Invalid SLA timezone / Fuso do SLA inválido.');
        }
    }

    private static function schedule(array $rows): array {
        if (count($rows) > self::MAX_CALENDAR_ROWS) {
            throw new RuntimeException('SLA calendar exceeds the safe processing limit / O calendário SLA excede o limite seguro de processamento.');
        }
        $periods = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new RuntimeException('Invalid SLA schedule / Calendário do SLA inválido.'); }
            $from = self::integer($row['period_from'] ?? null, 0, 604800);
            $to = self::integer($row['period_to'] ?? null, 0, 604800);
            if ($from >= $to) { throw new RuntimeException('Invalid SLA schedule bounds / Limites inválidos no calendário SLA.'); }
            $periods[] = ['period_from' => $from, 'period_to' => $to];
        }
        $periods = self::union($periods);
        return count($periods) === 1 && $periods[0]['period_from'] === 0 && $periods[0]['period_to'] === 604800
            ? [] : $periods;
    }

    /** Validate all rows, then retain only exclusions intersecting the requested month. */
    private static function exclusions(array $rows, int $from, int $to, bool $strictBounds = false): array {
        if (count($rows) > self::MAX_CALENDAR_ROWS) {
            throw new RuntimeException('SLA exclusions exceed the safe processing limit / As exclusões SLA excedem o limite seguro de processamento.');
        }
        $periods = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new RuntimeException('Invalid SLA exclusion / Exclusão do SLA inválida.'); }
            $start = self::integer($row['period_from'] ?? null, 0, self::MAX_TIMESTAMP);
            $end = self::integer($row['period_to'] ?? null, 0, self::MAX_TIMESTAMP);
            $name = array_key_exists('name', $row) ? self::text($row['name']) : '';
            if ($start >= $end || $strictBounds && ($start < $from || $end > $to)) {
                throw new RuntimeException('Invalid SLA exclusion bounds / Limites inválidos na exclusão SLA.');
            }
            if ($start < $to && $end > $from) {
                $periods[] = ['name' => $name, 'period_from' => max($from, $start), 'period_to' => min($to, $end)];
            }
        }
        usort($periods, static function(array $a, array $b): int {
            return [$a['period_from'], $a['period_to'], $a['name']] <=> [$b['period_from'], $b['period_to'], $b['name']];
        });
        return $periods;
    }

    private static function union(array $periods): array {
        usort($periods, static function(array $a, array $b): int {
            return [$a['period_from'], $a['period_to']] <=> [$b['period_from'], $b['period_to']];
        });
        $merged = [];
        foreach ($periods as $period) {
            $last = count($merged) - 1;
            if ($last >= 0 && $period['period_from'] <= $merged[$last]['period_to']) {
                $merged[$last]['period_to'] = max($merged[$last]['period_to'], $period['period_to']);
            }
            else { $merged[] = ['period_from' => $period['period_from'], 'period_to' => $period['period_to']]; }
        }
        return $merged;
    }

    /** Calendar boundaries use local civil time, including DST, as Zabbix 6.0 does. */
    private static function scheduledPeriods(int $from, int $to, DateTimeZone $timezone, array $schedule): array {
        if (!$schedule) { return [['period_from' => $from, 'period_to' => $to]]; }
        $first = (new DateTimeImmutable('@' . $from))->setTimezone($timezone);
        $sunday = $first->modify('-' . $first->format('w') . ' days')->setTime(0, 0);
        $periods = [];
        for ($week = 0; $week < 7; $week++) {
            $base = $sunday->modify('+' . $week . ' weeks');
            if ($base->getTimestamp() >= $to) { break; }
            foreach ($schedule as $row) {
                $start = self::weeklyBoundary($base, $row['period_from']);
                $end = self::weeklyBoundary($base, $row['period_to']);
                if ($start < $to && $end > $from && $end > $start) {
                    $start = max($from, $start); $end = min($to, $end);
                    $last = count($periods) - 1;
                    if ($last >= 0 && $periods[$last]['period_to'] === $start) {
                        $periods[$last]['period_to'] = $end;
                    }
                    else { $periods[] = ['period_from' => $start, 'period_to' => $end]; }
                }
            }
        }
        // CSla joins adjacent slots, but counts overlapping elapsed slots separately.
        // Distinct civil slots can overlap after PHP normalizes a nonexistent DST time.
        return $periods;
    }

    private static function weeklyBoundary(DateTimeImmutable $sunday, int $seconds): int {
        return $sunday->modify('+' . intdiv($seconds, 86400) . ' days')
            ->setTime(intdiv($seconds, 3600) % 24, intdiv($seconds, 60) % 60, $seconds % 60)->getTimestamp();
    }

    private static function duration(array $periods): int {
        $seconds = 0;
        foreach ($periods as $period) { $seconds += $period['period_to'] - $period['period_from']; }
        return $seconds;
    }

    private static function intersectionDuration(array $scheduled, array $excluded): int {
        if (!$excluded) { return 0; }
        $prefix = [0];
        foreach ($excluded as $index => $period) {
            $prefix[] = $prefix[$index] + $period['period_to'] - $period['period_from'];
        }
        $seconds = 0;
        foreach ($scheduled as $period) {
            $seconds += self::excludedBefore($excluded, $prefix, $period['period_to'])
                - self::excludedBefore($excluded, $prefix, $period['period_from']);
        }
        return $seconds;
    }

    /** Prefix/binary search also preserves native per-slot exclusions when DST slots overlap. */
    private static function excludedBefore(array $excluded, array $prefix, int $clock): int {
        $low = 0; $high = count($excluded);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($excluded[$middle]['period_to'] <= $clock) { $low = $middle + 1; }
            else { $high = $middle; }
        }
        return $prefix[$low] + (isset($excluded[$low]) ? max(0, $clock - $excluded[$low]['period_from']) : 0);
    }
}
