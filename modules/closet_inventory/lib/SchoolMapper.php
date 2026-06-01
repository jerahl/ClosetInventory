<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Lib;

use API;

/**
 * Static helpers that bridge district schools (authored in
 * tcs_closet_schools) and Zabbix host groups using the `Site/*` prefix
 * convention used elsewhere in the district (mirrors
 * reference/actions/ActionSwitches.php::collectFleet()).
 *
 * No state — every method is a pure function over its inputs plus
 * (optionally) the live Zabbix HostGroup API.
 */
class SchoolMapper {

    /**
     * Strip the `Site/` prefix from a Zabbix host-group name and slug the
     * remainder to a short upper-case stem suitable for use as a school id.
     *
     * The stem strategy is "initials of significant words" — e.g.
     *   `Site/Riverside High School`   → `RHS`
     *   `Site/Northport Elementary`    → `NE`
     *   `Site/Central Office`          → `CO`
     *
     * Returns null for any group whose name does not start with `Site/`.
     */
    public static function deriveSchoolIdFromGroup(string $groupName): ?string {
        if (!str_starts_with($groupName, 'Site/')) {
            return null;
        }
        $name = trim(substr($groupName, strlen('Site/')));
        if ($name === '') {
            return null;
        }

        // Tokenise on whitespace / punctuation; take the first letter of each
        // word that is not a noise word ("of", "the", "and", "at").
        $tokens = preg_split('/[^a-z0-9]+/i', $name) ?: [];
        $noise  = ['of' => 1, 'the' => 1, 'and' => 1, 'at' => 1, 'for' => 1];

        $initials = '';
        foreach ($tokens as $tok) {
            $tok = trim((string) $tok);
            if ($tok === '') continue;
            if (isset($noise[strtolower($tok)])) continue;
            $initials .= strtoupper($tok[0]);
        }

        if ($initials === '') {
            // Pure-numeric / punctuation-only name — fall back to a sluggified
            // upper-case stem of the whole string.
            $initials = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $name) ?? '');
        }

        return $initials !== '' ? $initials : null;
    }

    /**
     * Return the Zabbix host group(s) the given school is mapped to. Phase 2
     * is one-to-one via `tcs_closet_schools.zbx_group`; later phases may
     * widen this to multiple groups per school.
     *
     * @return array<int, string>
     */
    public static function hostgroupsForSchool(string $schoolId, InventoryStore $store): array {
        if ($schoolId === '') {
            return [];
        }
        foreach ($store->listSchools() as $s) {
            if ((string) $s['id'] !== $schoolId) continue;
            $g = $s['zbxGroup'] ?? null;
            if (!is_string($g) || $g === '') return [];
            return [$g];
        }
        return [];
    }

    /**
     * Fetch every host group whose name starts with `$prefix`, returning the
     * raw `[{groupid,name}, ...]` rows. Wraps `API::HostGroup()->get(...)`
     * so callers do not have to repeat the search-shape boilerplate.
     *
     * @return array<int, array<string, string>>
     */
    public static function fetchZbxGroupsByPrefix(string $prefix = 'Site/'): array {
        $rows = API::HostGroup()->get([
            'output'      => ['groupid', 'name'],
            'search'      => ['name' => $prefix],
            'startSearch' => true
        ]);
        return is_array($rows) ? $rows : [];
    }
}
