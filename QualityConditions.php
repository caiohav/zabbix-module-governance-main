<?php
namespace Modules\Governance;

use InvalidArgumentException;

/** Shared rules and bounded boolean parser; expressions never execute PHP or JavaScript. */
final class QualityConditions {
    const INVENTORY = ['os', 'os_full', 'os_short', 'serialno_a', 'serialno_b', 'location', 'type', 'software', 'hardware', 'name', 'contact'];

    public static function validate($selection): array {
        if (!is_array($selection) || ($selection['version'] ?? null) !== 1
                || !in_array($selection['mode'] ?? null, ['all', 'any', 'custom'], true)
                || !is_array($selection['conditions'] ?? null)
                || array_values($selection['conditions']) !== $selection['conditions'] || count($selection['conditions']) > 20) {
            throw new InvalidArgumentException('Invalid host selection / Seleção de hosts inválida (máximo 20 condições).');
        }
        $conditions = [];
        foreach ($selection['conditions'] as $condition) {
            if (!is_array($condition)) { throw new InvalidArgumentException('Invalid condition / Condição inválida.'); }
            $type = $condition['type'] ?? '';
            $ops = ['tag' => ['equals', 'not_equals', 'exists', 'not_exists'], 'group' => ['equals', 'not_equals'],
                'template' => ['equals', 'not_equals'], 'inventory' => ['exists', 'not_exists']];
            if (!is_string($type) || !isset($ops[$type]) || !in_array($condition['operator'] ?? null, $ops[$type], true)) {
                throw new InvalidArgumentException('Invalid condition operator / Operador de condição inválido.');
            }
            $row = ['type' => $type, 'operator' => $condition['operator']];
            foreach (['name', 'value'] as $key) {
                $text = $condition[$key] ?? '';
                if (!is_string($text) || mb_strlen($text) > 255 || preg_match('/[\x00-\x1f\x7f]/', $text)) {
                    throw new InvalidArgumentException('Invalid condition text / Texto da condição inválido.');
                }
                $row[$key] = trim($text);
            }
            $children = $condition['subgroups'] ?? 1;
            if (!in_array($children, [0, 1, '0', '1', true, false], true)) { throw new InvalidArgumentException('Invalid subgroups / Subgrupos inválidos.'); }
            $row['subgroups'] = (int) (bool) $children;
            if (($type === 'tag' && $row['name'] === '')
                    || (in_array($type, ['group', 'template'], true) && !self::items($row['value']))
                    || ($type === 'inventory' && !in_array($row['name'], self::INVENTORY, true))) {
                throw new InvalidArgumentException('Complete the condition fields / Preencha os campos da condição.');
            }
            $conditions[] = $row;
        }
        $result = ['version' => 1, 'mode' => $selection['mode'], 'conditions' => $conditions];
        if ($selection['mode'] === 'custom') {
            self::compile($selection['formula'] ?? null, count($conditions));
            $result['formula'] = trim($selection['formula']);
        }
        return $result;
    }

    /** Grammar: letters A-T, and/or/not and parentheses. All existing rows must be used. */
    public static function compile($formula, int $count): array {
        $fail = static function() { return new InvalidArgumentException('Invalid expression. Use all condition labels, and/or/not and parentheses / Expressão inválida. Use todos os rótulos, and/or/not e parênteses.'); };
        if (!is_string($formula) || strlen($formula) > 512 || $count < 1 || $count > 20) { throw $fail(); }
        $formula = trim($formula); $offset = 0; $tokens = [];
        while ($offset < strlen($formula)) {
            if (!preg_match('/\G(?:\s+|and\b|or\b|not\b|[A-T]|\(|\))/i', $formula, $match, 0, $offset)) { throw $fail(); }
            $offset += strlen($match[0]); $token = trim($match[0]);
            if ($token !== '') { $tokens[] = strlen($token) === 1 ? strtoupper($token) : strtolower($token); }
            if (count($tokens) > 256) { throw $fail(); }
        }
        $out = []; $stack = []; $seen = []; $operand = true;
        $priority = ['or'=>1, 'and'=>2, 'not'=>3];
        foreach ($tokens as $token) {
            if (preg_match('/^[A-T]$/D', $token)) {
                $index = ord($token) - 65;
                if (!$operand || $index >= $count) { throw $fail(); }
                $out[] = $index; $seen[$index] = true; $operand = false;
            }
            elseif ($token === '(') {
                if (!$operand) { throw $fail(); } $stack[] = $token;
            }
            elseif ($token === ')') {
                if ($operand) { throw $fail(); }
                while ($stack && end($stack) !== '(') { $out[] = array_pop($stack); }
                if (!$stack) { throw $fail(); } array_pop($stack);
            }
            else {
                if ($token === 'not') { if (!$operand) { throw $fail(); } }
                else { if ($operand) { throw $fail(); } $operand = true; }
                while ($stack && end($stack) !== '(' && $priority[end($stack)] >= $priority[$token]
                        && !($token === 'not' && end($stack) === 'not')) { $out[] = array_pop($stack); }
                $stack[] = $token;
            }
        }
        if ($operand || count($seen) !== $count) { throw $fail(); }
        while ($stack) { $token = array_pop($stack); if ($token === '(') { throw $fail(); } $out[] = $token; }
        return $out;
    }

    public static function evaluate(array $program, array $values): bool {
        $stack = [];
        foreach ($program as $token) {
            if (is_int($token)) { $stack[] = $values[$token]; }
            elseif ($token === 'not') { $stack[] = !array_pop($stack); }
            else { $right = array_pop($stack); $left = array_pop($stack); $stack[] = $token === 'and' ? $left && $right : $left || $right; }
        }
        return $stack[0];
    }

    public static function fromCard(array $card): array {
        if (isset($card['selection'])) { return self::validate($card['selection']); }
        $rows = [];
        if (($card['scope_tag_name'] ?? '') !== '') {
            $rows[] = ['type' => 'tag', 'operator' => ($card['scope_tag_value'] ?? '') === '' ? 'exists' : 'equals',
                'name' => $card['scope_tag_name'], 'value' => $card['scope_tag_value'] ?? '', 'subgroups' => 1];
        }
        if (($card['scope_group_names'] ?? '') !== '') {
            $rows[] = ['type' => 'group', 'operator' => 'equals', 'name' => '', 'value' => $card['scope_group_names'],
                'subgroups' => $card['scope_include_subgroups'] ?? 1];
        }
        return self::validate(['version' => 1, 'mode' => 'all', 'conditions' => $rows]);
    }

    private static function lower(string $value): string { return mb_strtolower(trim($value), 'UTF-8'); }
    private static function items(string $value): array { return array_values(array_filter(array_unique(array_map([self::class, 'lower'], explode(',', $value))), static function($v) { return $v !== ''; })); }

    public static function matches(array $host, array $selection): bool {
        if ($selection['mode'] === 'custom') {
            $values = array_map(static function($row) use ($host) { return self::condition($host, $row); }, $selection['conditions']);
            return self::evaluate($selection['_program'] ?? self::compile($selection['formula'], count($values)), $values);
        }
        if (!$selection['conditions']) { return true; }
        foreach ($selection['conditions'] as $row) {
            $match = self::condition($host, $row);
            if ($selection['mode'] === 'all' && !$match) { return false; }
            if ($selection['mode'] === 'any' && $match) { return true; }
        }
        return $selection['mode'] === 'all';
    }

    private static function condition(array $host, array $row): bool {
        $found = false;
        switch ($row['type']) {
            case 'tag':
                foreach ($host['tags'] ?? [] as $tag) {
                    if (self::lower($tag['tag']) === self::lower($row['name'])
                            && (in_array($row['operator'], ['exists', 'not_exists'], true)
                                || self::lower($tag['value']) === self::lower($row['value']))) { $found = true; break; }
                }
                break;
            case 'group':
                foreach ($host['groups'] ?? [] as $group) {
                    foreach (self::items($row['value']) as $accepted) {
                        $prefix = rtrim($accepted, '/'); $name = self::lower($group['name']);
                        if ((string) $group['groupid'] === $accepted || $name === $prefix
                                || ($row['subgroups'] && $prefix !== '' && strpos($name, $prefix . '/') === 0)) { $found = true; break 2; }
                    }
                }
                break;
            case 'template':
                foreach ($host['parentTemplates'] ?? [] as $template) {
                    foreach (['templateid', 'host', 'name'] as $key) {
                        if (in_array(self::lower((string) ($template[$key] ?? '')), self::items($row['value']), true)) { $found = true; break 2; }
                    }
                }
                break;
            case 'inventory': $found = trim($host['inventory'][$row['name']] ?? '') !== ''; break;
        }
        return in_array($row['operator'], ['not_equals', 'not_exists'], true) ? !$found : $found;
    }

    public static function addSelects(array $options, array $cards): array {
        foreach ($cards as $card) {
            foreach ($card['selection']['conditions'] as $row) {
                switch ($row['type']) {
                    case 'tag': $options['selectTags'] = ['tag', 'value']; break;
                    case 'group': $options['selectGroups'] = ['groupid', 'name']; break;
                    case 'template': $options['selectParentTemplates'] = ['templateid', 'host', 'name']; break;
                    case 'inventory': $options['selectInventory'] = array_values(array_unique(array_merge($options['selectInventory'] ?? [], [$row['name']]))); break;
                }
            }
        }
        return $options;
    }
}
