<?php

namespace Modules\Governance;

use InvalidArgumentException;

final class GovernanceConfig {

    public const MAX_QUALITY_PAGES = 12;
    public const MAX_CARDS_PER_PAGE = 30;

    /**
     * Existing installations store one implicit page in `cards`. An empty legacy
     * list meant the built-in cards; explicit page lists never use that fallback.
     * Reading configuration does not persist the migration.
     */
    public static function getQualityPages(array $config): array {
        if (array_key_exists('quality_pages', $config)) {
            return self::validateQualityPages($config['quality_pages']);
        }

        return [[
            'id' => 'main',
            'name' => '',
            'cards' => self::normalizeCards($config['cards'] ?? [])
        ]];
    }

    /** Validate writes without silently dropping cards, truncating text or adding defaults. */
    public static function validateQualityPages($pages): array {
        $pages = self::qualityList($pages, self::MAX_QUALITY_PAGES, 'pages / páginas');
        $result = [];
        $pageIds = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                throw new InvalidArgumentException('Invalid page / Página inválida.');
            }

            $id = self::qualityId($page['id'] ?? null);
            if (isset($pageIds[$id])) {
                throw new InvalidArgumentException('Duplicate page ID / Identificador de página duplicado.');
            }
            $pageIds[$id] = true;
            $name = self::qualityText($page['name'] ?? null, 100, $id === 'main');
            $cards = self::qualityList($page['cards'] ?? null, self::MAX_CARDS_PER_PAGE, 'cards');
            $normalizedCards = [];
            $cardIds = [];

            foreach ($cards as $card) {
                if (!is_array($card)) {
                    throw new InvalidArgumentException('Invalid card / Card inválido.');
                }

                $cardId = self::qualityId($card['id'] ?? null);
                if (isset($cardIds[$cardId])) {
                    throw new InvalidArgumentException('Duplicate card ID within a page / Identificador de card duplicado na página.');
                }
                $cardIds[$cardId] = true;

                $type = $card['type'] ?? null;
                if (!is_string($type) || !in_array($type, ['tag', 'hostgroups', 'inventory', 'templates', 'interface'], true)) {
                    throw new InvalidArgumentException('Invalid card metric / Métrica de card inválida.');
                }
                $tagNames = self::qualityText($card['tag_names'] ?? '', 255, true);
                $groupNames = self::qualityText($card['group_names'] ?? '', 255, true);
                if ($type === 'tag' && !self::splitList($tagNames)) {
                    throw new InvalidArgumentException('Specify at least one tag name / Informe ao menos um nome de tag.');
                }
                if ($type === 'hostgroups' && !self::splitList($groupNames)) {
                    throw new InvalidArgumentException('Specify at least one host group / Informe ao menos um grupo de hosts.');
                }
                $includeScore = $card['include_score'] ?? 0;
                if (!in_array($includeScore, [0, 1, '0', '1', false, true], true)) {
                    throw new InvalidArgumentException('Invalid score selection / Participação no índice inválida.');
                }

                $normalizedCards[] = [
                    'id' => $cardId,
                    'type' => $type,
                    'title' => self::qualityText($card['title'] ?? null, 100),
                    'description' => self::qualityText($card['description'] ?? '', 255, true, true),
                    'tag_names' => $tagNames,
                    'tag_values' => self::qualityText($card['tag_values'] ?? '', 255, true),
                    'group_names' => $groupNames,
                    'include_score' => (int) (bool) $includeScore
                ];
            }

            $result[] = ['id' => $id, 'name' => $name, 'cards' => $normalizedCards];
        }

        return $result;
    }

    /** Unrelated availability settings must not invalidate a quality edit. */
    public static function qualityRevision(array $config): string {
        return hash('sha256', json_encode(self::getQualityPages($config), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The native module.config column is shared by every feature of this module. */
    public static function assertModuleConfigSize(array $config): void {
        // Match CModule's default json_encode flags, including Unicode escapes.
        $json = json_encode($config);
        if ($json === false) {
            throw new InvalidArgumentException('The module configuration cannot be encoded / A configuração do módulo não pode ser codificada.');
        }
        $limit = class_exists('DB') ? (int) \DB::getFieldLength('module', 'config') : 65535;
        if (strlen($json) > $limit) {
            throw new InvalidArgumentException('The complete module configuration exceeds the Zabbix storage limit (' . $limit
                . ' bytes). Quality pages and availability share this limit. Reduce the configuration; nothing was truncated'
                . ' / A configuração completa do módulo excede o limite de armazenamento do Zabbix (' . $limit
                . ' bytes). Qualidade e disponibilidade compartilham esse limite. Reduza a configuração; nenhum conteúdo foi truncado.');
        }
    }

    public static function getDefaultCards(): array {
        return [
            self::card('department', 'tag', 'Tag de Departamento',
                'Hosts com tag de departamento e valor preenchido.', 'department,departamento,dept'),
            self::card('environment', 'tag', 'Tag de Ambiente',
                'Hosts com tag de ambiente e valor preenchido.', 'environment,ambiente,env'),
            self::card('owner', 'tag', 'Tag de Responsável/Equipe',
                'Hosts com tag de responsável ou equipe e valor preenchido.',
                'owner,responsavel,responsável,responsible,team,equipe'),
            self::card('inventory', 'inventory', 'Preenchimento de Inventário',
                'Hosts com ao menos um campo essencial de inventário preenchido.'),
            self::card('templates', 'templates', 'Vínculo de Templates',
                'Hosts vinculados a pelo menos um template.'),
            self::card('interface', 'interface', 'Interface Configurada',
                'Hosts com ao menos uma interface contendo IP ou DNS.')
        ];
    }

    public static function normalizeCards($cards): array {
        if (!is_array($cards)) {
            return self::getDefaultCards();
        }

        $normalized = [];
        $usedIds = [];
        $allowedTypes = ['tag', 'hostgroups', 'inventory', 'templates', 'interface'];

        // This is a legacy read path. New writes use strict validation and must
        // never silently discard excess cards from an existing installation.
        foreach (array_values($cards) as $index => $card) {
            if (!is_array($card)) {
                continue;
            }

            $type = strtolower(trim((string) ($card['type'] ?? 'tag')));
            $title = trim((string) ($card['title'] ?? ''));
            $tagNames = trim((string) ($card['tag_names'] ?? ''));
            $groupNames = trim((string) ($card['group_names'] ?? ''));

            if (!in_array($type, $allowedTypes, true) || $title === '') {
                continue;
            }

            // Cards de tag precisam de ao menos um nome/alias.
            if ($type === 'tag' && $tagNames === '') {
                continue;
            }

            // Cards de grupo precisam de ao menos um nome ou ID de grupo.
            if ($type === 'hostgroups' && $groupNames === '') {
                continue;
            }

            $legacyId = (string) ($card['id'] ?? $title);
            $baseId = preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $legacyId)
                ? $legacyId : substr(self::slug($legacyId, $index), 0, 64);
            $id = $baseId;
            $suffix = 1;
            while (array_key_exists($id, $usedIds)) {
                $tail = '_' . $suffix++;
                $id = substr($baseId, 0, 64 - strlen($tail)) . $tail;
            }
            $usedIds[$id] = true;

            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'title' => mb_substr($title, 0, 100),
                'description' => mb_substr(trim((string) ($card['description'] ?? '')), 0, 255),
                'tag_names' => mb_substr($tagNames, 0, 255),
                'tag_values' => mb_substr(trim((string) ($card['tag_values'] ?? '')), 0, 255),
                'group_names' => mb_substr($groupNames, 0, 255),
                'include_score' => !empty($card['include_score']) ? 1 : 0
            ];
        }

        return $normalized ?: self::getDefaultCards();
    }

    public static function splitList(string $value): array {
        return array_values(array_unique(array_filter(array_map(static function(string $item): string {
            return mb_strtolower(trim($item), 'UTF-8');
        }, explode(',', $value)), static function(string $item): bool {
            return $item !== '';
        })));
    }

    private static function qualityList($value, int $limit, string $name): array {
        if (!is_array($value) || array_values($value) !== $value || count($value) > $limit) {
            throw new InvalidArgumentException('Invalid list / Lista inválida: ' . $name . ' (max. ' . $limit . ').');
        }

        return $value;
    }

    private static function qualityId($value): string {
        if (!is_string($value) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $value)) {
            throw new InvalidArgumentException('Invalid page or card ID / Identificador de página ou card inválido.');
        }

        return $value;
    }

    private static function qualityText($value, int $limit, bool $allowEmpty = false, bool $multiline = false): string {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
                || mb_strlen($value, 'UTF-8') > $limit
                || preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $value)
                || (!$allowEmpty && trim($value) === '')) {
            throw new InvalidArgumentException('Required text is invalid, empty or too long / Texto obrigatório inválido, vazio ou muito longo.');
        }

        return trim($value);
    }

    private static function card(string $id, string $type, string $title, string $description,
            string $tagNames = ''): array {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'tag_names' => $tagNames,
            'tag_values' => '',
            'group_names' => '',
            'include_score' => 1
        ];
    }

    private static function slug(string $value, int $index): string {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $value));
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'card_' . $index;
    }
}
