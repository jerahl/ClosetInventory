<?php declare(strict_types=1);

namespace Modules\ClosetInventory\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ClosetInventory\Lib\InventoryStore;

/**
 * POST zabbix.php?action=closet.seed
 *
 * One-time deterministic seed. No-op if any closets already exist, so
 * re-running it never clobbers operator edits.
 *
 * Phase 1 ships a hardcoded starter set (3 schools, 4 closets) instead of
 * parsing assets/data.js — keeps the seed deterministic without dragging
 * a JS parser server-side. data.js stays in assets/ as future reference.
 */
class ActionSeed extends CController {

    protected function checkPermissions(): bool {
        if (!CWebUser::isLoggedIn()) {
            http_response_code(401);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => 'unauthenticated'])
            ]));
            return false;
        }
        return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function doAction(): void {
        try {
            [$schools, $closets] = $this->starterData();
            $store = new InventoryStore();
            $store->seedFromArray($closets, $schools);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => true])
            ]));
        }
        catch (\Throwable $e) {
            http_response_code(500);
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode(['ok' => false, 'error' => $e->getMessage()])
            ]));
        }
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function starterData(): array {
        $schools = [
            ['id' => 'RHS', 'name' => 'Riverside High School',   'type' => 'High',   'zbxGroup' => 'Site/Riverside High School',   'colorHue' => 256],
            ['id' => 'NMS', 'name' => 'Northgate Middle School', 'type' => 'Middle', 'zbxGroup' => 'Site/Northgate Middle School', 'colorHue' => 195],
            ['id' => 'OAK', 'name' => 'Oakmont Elementary',      'type' => 'Elem',   'zbxGroup' => 'Site/Oakmont Elementary',      'colorHue' => 72]
        ];

        $closets = [
            [
                'code' => 'RHS-MDF', 'type' => 'MDF', 'schoolId' => 'RHS',
                'building' => 'Main', 'floor' => 1, 'room' => 'Main Distribution Frame',
                'switches' => [
                    ['name' => 'RHS-MDF-SW1', 'vendor' => 'Cisco', 'model' => 'Catalyst 9500-16X', 'ports' => 16, 'used' => 12, 'poe' => false, 'uplinks' => 4, 'uplinkSpeed' => '10G SFP+', 'mgmtIp' => '10.10.0.2', 'serial' => 'FOC2401AAA10', 'stack' => 1],
                    ['name' => 'RHS-MDF-SW2', 'vendor' => 'Cisco', 'model' => 'Catalyst 9300-48P', 'ports' => 48, 'used' => 31, 'poe' => true,  'uplinks' => 2, 'uplinkSpeed' => '10G SFP+', 'mgmtIp' => '10.10.0.3', 'serial' => 'FOC2401AAB11', 'stack' => 2]
                ],
                'power' => [
                    'upsList'  => [['model' => 'APC Smart-UPS SRT 3000VA', 'va' => 3000, 'loadPct' => 42, 'batteryHealthPct' => 88, 'runtimeMin' => 28]],
                    'pduList'  => [['model' => 'APC AP8941 Switched', 'outlets' => 24, 'loadAmps' => 6.4]],
                    'circuits' => ['A — 20A / 120V', 'B — 20A / 120V']
                ],
                'maintenance' => [
                    ['date' => '2026-04-18', 'type' => 'Quarterly inspection', 'tone' => 'default', 'tech' => 'J. Whitfield', 'notes' => 'Routine inspection — power, environment, labeling verified.']
                ]
            ],
            [
                'code' => 'RHS-IDF-203', 'type' => 'IDF', 'schoolId' => 'RHS',
                'building' => 'Science Wing', 'floor' => 2, 'room' => 'IDF 207',
                'switches' => [
                    ['name' => 'RHS-IDF-SW1', 'vendor' => 'Cisco', 'model' => 'Catalyst 9300-48P', 'ports' => 48, 'used' => 31, 'poe' => true, 'uplinks' => 2, 'uplinkSpeed' => '10G SFP+', 'mgmtIp' => '10.10.3.5', 'serial' => 'FOC2412ABCD', 'stack' => 1]
                ],
                'power' => [
                    'upsList'  => [['model' => 'APC Smart-UPS 1500VA', 'va' => 1500, 'loadPct' => 55, 'batteryHealthPct' => 62, 'runtimeMin' => 18]],
                    'pduList'  => [],
                    'circuits' => ['A — 20A / 120V']
                ],
                'maintenance' => [],
                'flagged'     => true,
                'flagReason'  => 'UPS battery health below 70% — schedule replacement.',
                'flagTech'    => 'M. Alvarez',
                'flagDate'    => '2026-05-20'
            ],
            [
                'code' => 'NMS-MDF', 'type' => 'MDF', 'schoolId' => 'NMS',
                'building' => 'Main', 'floor' => 1, 'room' => 'Main Distribution Frame',
                'switches' => [
                    ['name' => 'NMS-MDF-SW1', 'vendor' => 'Aruba', 'model' => 'CX 6300M 48G', 'ports' => 48, 'used' => 22, 'poe' => true, 'uplinks' => 2, 'uplinkSpeed' => '10G SFP+', 'mgmtIp' => '10.40.0.2', 'serial' => 'SG40ABC123', 'stack' => 1]
                ],
                'power' => [
                    'upsList'  => [['model' => 'Eaton 5PX 2200VA', 'va' => 2200, 'loadPct' => 38, 'batteryHealthPct' => 91, 'runtimeMin' => 32]],
                    'pduList'  => [['model' => 'Eaton EMAB10 Managed', 'outlets' => 16, 'loadAmps' => 4.8]],
                    'circuits' => ['A — 20A / 120V']
                ],
                'maintenance' => []
            ],
            [
                'code' => 'OAK-MDF', 'type' => 'MDF', 'schoolId' => 'OAK',
                'building' => 'Main', 'floor' => 1, 'room' => 'Telecom Rm 1A',
                'switches' => [
                    ['name' => 'OAK-MDF-SW1', 'vendor' => 'Meraki', 'model' => 'MS225-48LP', 'ports' => 48, 'used' => 18, 'poe' => true, 'uplinks' => 2, 'uplinkSpeed' => '1G SFP', 'mgmtIp' => '10.60.0.2', 'serial' => 'Q2GD-XXXX-YYYY', 'stack' => 1]
                ],
                'power' => [
                    'upsList'  => [['model' => 'APC Smart-UPS 1500VA', 'va' => 1500, 'loadPct' => 33, 'batteryHealthPct' => 95, 'runtimeMin' => 25]],
                    'pduList'  => [],
                    'circuits' => ['A — 20A / 120V']
                ],
                'maintenance' => []
            ]
        ];

        return [$schools, $closets];
    }
}
