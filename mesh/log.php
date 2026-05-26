<?php
require_once __DIR__ . '/_init.php';
$ready = mesh_db_ready();
$rows = [];
if ($ready) {
    $rows = mesh_db()
        ->query("SELECT * FROM mesh_messages ORDER BY id DESC LIMIT 200")
        ->fetchAll(PDO::FETCH_ASSOC);
}
?><!DOCTYPE html>
<html><head><meta charset=utf-8><title>Mesh log - Noosphere</title>
<?php include '/var/www/noosphere/shared/head.php'; ?>
<style>
  .mesh-wrap { max-width: 1100px; margin: 0 auto; padding: 12px; }
  .mesh-tabs { display:flex; gap:6px; margin-bottom:10px; }
  .mesh-tabs a { padding:6px 12px; border:1px solid #2a2a4a; border-radius:6px; color:#7ad; text-decoration:none; font-size:13px; }
  .mesh-tabs a.active { background:#1a1a2e; color:#fff; }
  table.dtable { width:100%; border-collapse:collapse; font-size:12px; font-family:monospace; }
  table.dtable th { text-align:left; padding:5px; border-bottom:1px solid #2a2a4a; color:#888; font-weight:normal; }
  table.dtable td { padding:5px; border-bottom:1px solid #1a1a2e; color:#ccc; vertical-align:top; }
  .out { color:#2ecc71; }
</style>
</head><body>
<div class="mesh-wrap">
  <h2 style="margin:8px 0">📻 Mesh / Raw Log</h2>
  <div class="mesh-tabs">
    <a href="/mesh/">Chat</a>
    <a href="/mesh/nodes.php">Nodes</a>
    <a href="/mesh/log.php" class="active">Raw Log</a>
  </div>
  <?php if (!$ready): ?>
    <div style="color:#888;font-size:13px">Mesh daemon not running yet.</div>
  <?php elseif (!$rows): ?>
    <div style="color:#888;font-size:13px">No mesh traffic recorded.</div>
  <?php else: ?>
  <table class="dtable">
    <thead><tr>
      <th>Time</th><th>Dir</th><th>From</th><th>To</th><th>Ch</th><th>RSSI</th><th>SNR</th><th>Hops</th><th>Body</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['direction']==='out' ? 'out' : '' ?>">
        <td><?= date('M j H:i:s', (int)$r['received_at']) ?></td>
        <td><?= esc($r['direction']) ?></td>
        <td><?= esc($r['from_id'] ?? '-') ?></td>
        <td><?= esc($r['to_id'] ?? '-') ?></td>
        <td><?= (int)$r['channel'] ?></td>
        <td><?= $r['rssi'] !== null ? (int)$r['rssi'] : '-' ?></td>
        <td><?= $r['snr'] !== null ? esc((string)$r['snr']) : '-' ?></td>
        <td><?= $r['hop_limit'] !== null ? (int)$r['hop_limit'] : '-' ?></td>
        <td style="max-width:400px;word-break:break-word"><?= esc($r['body']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
</body></html>
