<?php

namespace Modules\Governance;

final class GovernanceConfig {

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
        $allowedTypes = ['tag', 'inventory', 'templates', 'interface'];

        foreach (array_slice($cards, 0, 30) as $index => $card) {
            if (!is_array($card)) {
                continue;
            }

            $type = strtolower(trim((string) ($card['type'] ?? 'tag')));
            $title = trim((string) ($card['title'] ?? ''));
            $tagNames = trim((string) ($card['tag_names'] ?? ''));

            if (!in_array($type, $allowedTypes, true) || $title === '') {
                continue;
            }

            // Cards de tag precisam de ao menos um nome/alias.
            if ($type === 'tag' && $tagNames === '') {
                continue;
            }

            $id = self::slug((string) ($card['id'] ?? $title), $index);
            if (array_key_exists($id, $usedIds)) {
                $id .= '_' . $index;
            }
            $usedIds[$id] = true;

            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'title' => mb_substr($title, 0, 100),
                'description' => mb_substr(trim((string) ($card['description'] ?? '')), 0, 255),
                'tag_names' => mb_substr($tagNames, 0, 255),
                'tag_values' => mb_substr(trim((string) ($card['tag_values'] ?? '')), 0, 255),
                'include_score' => !empty($card['include_score']) ? 1 : 0
            ];
        }

        return $normalized ?: self::getDefaultCards();
    }

    public static function splitList(string $value): array {
        return array_values(array_unique(array_filter(array_map(static function(string $item): string {
            return strtolower(trim($item));
        }, explode(',', $value)), static function(string $item): bool {
            return $item !== '';
        })));
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
            'include_score' => 1
        ];
    }

    private static function slug(string $value, int $index): string {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $value));
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'card_' . $index;
    }
}
