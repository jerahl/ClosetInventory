<?php declare(strict_types=1);

/**
 * Closet Inventory — detail view shell.
 * Identical asset/script wiring to closet.list.view.php; the boot payload's
 * initialView / initialUid tell App.jsx to open the detail card directly.
 *
 * @var CView $this
 * @var array $data
 */

$boot = $data['boot'] ?? [];
?>
<link rel="stylesheet" href="modules/closet_inventory/assets/styles.css">
<div id="closet-root"></div>
<script>
window.CLOSET_BOOT = <?= json_encode($boot, JSON_UNESCAPED_SLASHES) ?>;
window.SeedData = window.SeedData || { schools: [], closets: [] };
</script>
<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/icons.jsx"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/components.jsx"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/tweaks-panel.jsx"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/ListView.jsx"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/DetailView.jsx"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/QueueView.jsx"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/MaintenanceView.jsx"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/SchoolsView.jsx"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/Modals.jsx"></script>
<script type="text/babel" data-presets="env,react" src="modules/closet_inventory/assets/App.jsx"></script>
