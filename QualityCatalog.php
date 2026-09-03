<?php
namespace Modules\Governance;

/** Small, read-only catalog lookup; never scans hosts or expands relations. */
class QualityCatalog {
    public static function valid(string $type, string $query): bool {
        return in_array($type, ['group', 'template'], true) && strlen(trim($query)) >= 2
            && strlen($query) <= 255 && !preg_match('/[\x00-\x1f\x7f]/', $query);
    }

    public static function search(string $type, string $query, callable $api): array {
        if (!self::valid($type, $query)) { throw new \InvalidArgumentException('Invalid lookup.'); }
        $group = $type === 'group';
        $id = $group ? 'groupid' : 'templateid';
        $search = ['name' => trim($query)];
        if (!$group) { $search['host'] = trim($query); }
        $rows = $api($group ? 'HostGroup' : 'Template', [
            'output' => $group ? [$id, 'name'] : [$id, 'name', 'host'],
            'search' => $search, 'searchByAny' => true, 'searchWildcardsEnabled' => false,
            'sortfield' => 'name', 'sortorder' => 'ASC', 'limit' => 21
        ]);
        if (!is_array($rows)) { throw new \RuntimeException('Catalog unavailable.'); }
        $items = [];
        foreach (array_slice(array_values($rows), 0, 20) as $row) {
            if (!is_array($row) || !isset($row[$id], $row['name'])
                    || !preg_match('/^[0-9]+$/D', (string) $row[$id]) || !is_string($row['name'])) {
                throw new \RuntimeException('Invalid catalog response.');
            }
            $items[] = ['id' => (string) $row[$id], 'name' => $row['name']];
        }
        return ['status' => 'complete', 'items' => $items, 'has_more' => count($rows) > 20];
    }
}
