<?php

namespace Modules\Governance;

use InvalidArgumentException;
use DateTimeZone;

final class AvailabilityConfig {
    public static function defaults(): array {
        return ['timezone' => 'America/Cuiaba', 'departments' => []];
    }

    public static function validate($input): array {
        if (!is_array($input)) {
            throw new InvalidArgumentException('Invalid configuration / Configuração inválida.');
        }
        $timezone = self::text($input['timezone'] ?? '', 80);
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Invalid time zone / Fuso horário inválido.');
        }
        $departments = self::listOf($input['departments'] ?? null, 12, 'departments / departamentos');
        $result = ['timezone' => $timezone, 'departments' => []];
        $techCount = 0;
        foreach ($departments as $department) {
            if (!is_array($department)) {
                throw new InvalidArgumentException('Invalid department / Departamento inválido.');
            }
            $name = self::text($department['name'] ?? '', 100);
            $technologies = self::listOf($department['technologies'] ?? null, 30, $name);
            if (!$technologies) {
                throw new InvalidArgumentException($name . ': add a technology / adicione uma tecnologia.');
            }
            $node = ['name' => $name, 'target' => self::number($department['target'] ?? null, 0, 100),
                'technologies' => []];
            foreach ($technologies as $technology) {
                if (!is_array($technology) || ++$techCount > 30) {
                    throw new InvalidArgumentException('Maximum 30 technologies / Máximo de 30 tecnologias.');
                }
                $mode = $technology['mode'] ?? '';
                if (!in_array($mode, ['any_down', 'mean'], true)) {
                    throw new InvalidArgumentException('Invalid aggregation / Consolidação inválida.');
                }
                $checks = self::listOf($technology['checks'] ?? null, 6, 'checks / verificações');
                if (!$checks) {
                    throw new InvalidArgumentException('At least one check is required / Informe ao menos uma verificação.');
                }
                $groups = self::text($technology['groups'] ?? '', 1000);
                if (!self::groups($groups)) {
                    throw new InvalidArgumentException('Specify groups / Informe grupos.');
                }
                $tech = ['name' => self::text($technology['name'] ?? '', 100),
                    'weight' => self::number($technology['weight'] ?? null, 0.001, 100000),
                    'target' => self::number($technology['target'] ?? null, 0, 100),
                    'groups' => $groups, 'mode' => $mode, 'checks' => []];
                // Keep legacy technology-wide policies until the user explicitly selects auto per check.
                $legacyAge = isset($technology['max_age']) ? self::seconds($technology['max_age']) : null;
                if ($legacyAge !== null) { $tech['max_age'] = $legacyAge; }
                foreach ($checks as $check) {
                    if (!is_array($check)) {
                        throw new InvalidArgumentException('Invalid check / Verificação inválida.');
                    }
                    $age = array_key_exists('max_age', $check) ? $check['max_age'] : $legacyAge;
                    $tech['checks'][] = ['key' => self::text($check['key'] ?? '', 2048),
                        'max_age' => $age === null ? null : self::seconds($age),
                        'up' => self::condition($check['up'] ?? null),
                        'down' => isset($check['down']) ? self::condition($check['down']) : null];
                }
                $node['technologies'][] = $tech;
            }
            $result['departments'][] = $node;
        }
        return $result;
    }

    private static function seconds($value): int {
        $seconds = self::number($value, 1, 86400);
        if (floor($seconds) !== $seconds) {
            throw new InvalidArgumentException('Sample validity must be whole seconds / A validade da amostra deve ser um número inteiro de segundos.');
        }
        return (int) $seconds;
    }

    private static function condition($input): array {
        if (!is_array($input) || !in_array($input['op'] ?? '', ['eq', 'ne', 'gt', 'ge', 'lt', 'le', 'range'], true)) {
            throw new InvalidArgumentException('Invalid condition / Condição inválida.');
        }
        $condition = ['op' => $input['op'], 'a' => self::number($input['a'] ?? null, -1.0e20, 1.0e20)];
        if ($condition['op'] === 'range') {
            $condition['b'] = self::number($input['b'] ?? null, $condition['a'], 1.0e20);
        }
        return $condition;
    }

    private static function text($value, int $limit): string {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value, 'UTF-8') > $limit) {
            throw new InvalidArgumentException('Required text is empty or too long / Texto obrigatório vazio ou muito longo.');
        }
        return trim($value);
    }

    private static function number($value, float $min, float $max): float {
        if (!is_scalar($value) || !is_numeric($value) || !is_finite((float) $value)
                || (float) $value < $min || (float) $value > $max) {
            throw new InvalidArgumentException('Invalid number / Número inválido: ' . $min . ' … ' . $max);
        }
        return (float) $value;
    }

    private static function listOf($value, int $max, string $name): array {
        if (!is_array($value) || count($value) > $max) {
            throw new InvalidArgumentException('Invalid list / Lista inválida: ' . $name . ' (max. ' . $max . ')');
        }
        return array_values($value);
    }

    public static function groups(string $input): array {
        return array_values(array_unique(array_filter(array_map(static function($value) {
            return mb_strtolower(rtrim(trim($value), '/'), 'UTF-8');
        }, preg_split('/[,\r\n]+/', $input)), static function($value) { return $value !== ''; })));
    }
}
