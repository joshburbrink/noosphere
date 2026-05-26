<?php
require_once __DIR__ . '/_init.php';
$ready = mesh_db_ready();
$rows = [];
if ($ready) {
    $rows = mesh_db()
        ->query("SELECT * FROM mesh_nodes ORDER BY is_self DESC, COALESCE(last_heard, updated_at) DESC")
        ->fetchAll(PDO::FETCH_ASSOC);
}
?><!DOCTYPE html>
<html><head><meta charset=utf-8><title>Mesh nodes - Noosphere</title>
<?php include '/var/www/noosphere/shared/head.php'; ?>
<style>
  .mesh-wrap { max-width: 900px; margin: 0 auto; padding: 12px; }
  .mesh-tabs { display:flex; gap:6px; margin-bottom:10px; }
  .mesh-tabs a { padding:6px 12px; border:1px solid #2a2a4a; border-radius:6px; color:#7ad; text-decoration:none; font-size:13px; }
  .mesh-tabs a.active { background:#1a1a2e; color:#fff; }
  table.dtable { width:100%; border-collapse:collapse; font-size:13px; }
  table.dtable th { text-align:left; padding:6px; border-bottom:1px solid #2a2a4a; color:#888; font-weight:normal; font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
  table.dtable td { padding:6px; border-bottom:1px solid #1a1a2e; color:#ccc; }
  .self { background:#1a2a3a; }
  .stale { color:#666; }
</style>
</head><body>
<div class="mesh-wrap">
  <h2 style="margin:8px 0">📻 Mesh / Nodes</h2>
  <div class="mesh-tabs">
    <a href="/mesh/">Chat</a>
    <a href="/mesh/nodes.php" class="active">Nodes</a>
    <a href="/mesh/log.php">Raw Log</a>
  </div>
  <?php if (!$ready): ?>
    <div style="color:#888;font-size:13px">Mesh daemon not running yet.</div>
  <?php elseif (!$rows): ?>
    <div style="color:#888;font-size:13px">No nodes seen.</div>
  <?php else: ?>
  <table class="dtable">
    <thead><tr>
      <th>Node</th><th>Long name</th><th>HW</th><th>Hops</th><th>SNR</th><th>RSSI</th><th>Battery</th><th>Last heard</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $self = (int)($r['is_self'] ?? 0);
      $age  = $r['last_heard'] ? (time() - (int)$r['last_heard']) : null;
      $stale = $age !== null && $age > 1800;
    ?>
      <tr class="<?= $self ? 'self' : ($stale ? 'stale' : '') ?>">
        <td><b><?= esc($r['short_name'] ?: '?') ?></b><br><span style="color:#666;font-size:11px"><?= esc($r['node_id']) ?></span></td>
        <td><?= esc($r['long_name'] ?? '') ?></td>
        <td style="color:#888"><?= esc($r['hw_model'] ?? '') ?></td>
        <td><?= $r['hops_away'] === null ? '-' : (int)$r['hops_away'] ?></td>
        <td><?= $r['snr'] !== null ? esc((string)$r['snr']) : '-' ?></td>
        <td><?= $r['rssi'] !== null ? esc((string)$r['rssi']).' dBm' : '-' ?></td>
        <td><?= $r['battery_pct'] !== null ? (int)$r['battery_pct'].'%' : '-' ?></td>
        <td><?= $r['last_heard'] ? date('M j H:i', (int)$r['last_heard']) : '-' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
</body></html>
