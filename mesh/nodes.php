<?php
require_once __DIR__ . '/_init.php';
$ready = mesh_db_ready();
$rows = [];
if ($ready) {
    $rows = mesh_db()
        ->query("SELECT * FROM mesh_nodes ORDER BY is_self DESC, COALESCE(last_heard, updated_at) DESC")
        ->fetchAll(PDO::FETCH_ASSOC);
}

/* "3 min", "2 h", "4 d" - a bare clock time ("Aug 19 04:12") makes the reader
 * do the subtraction, and the only thing that matters at a glance is how long
 * ago it was. */
function mesh_ago(?int $ts): string {
    if (!$ts) return '';
    $s = time() - $ts;
    if ($s < 60)    return $s . ' s';
    if ($s < 3600)  return intdiv($s, 60) . ' min';
    if ($s < 86400) return intdiv($s, 3600) . ' h';
    return intdiv($s, 86400) . ' d';
}
?><!DOCTYPE html>
<html><head><meta charset=utf-8><title>Mesh nodes - Noosphere</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include '/var/www/noosphere/shared/head.php'; ?>
<style>
  /* Read at arm's length in a moving car, so: bigger type, fewer columns,
     and colours taken from the theme rather than hardcoded greys that only
     happened to work on one background. */
  .mesh-wrap { max-width: 1040px; margin: 0 auto; padding: 12px 14px 28px; }

  .mesh-tabs { display:flex; gap:8px; margin-bottom:14px; }
  .mesh-tabs a {
    padding:11px 20px; border:1px solid var(--border, #2a2a4a); border-radius:10px;
    color:var(--link, #7ad); text-decoration:none; font-size:17px; font-weight:600;
  }
  .mesh-tabs a.active { background:var(--bg-card, #16213e); color:var(--text, #fff); }

  .lede { color:var(--text-muted, #aaa); font-size:15px; margin:0 0 12px; }

  .nodes { display:flex; flex-direction:column; gap:8px; }

  /* A row per node rather than a 8-column table: HOPS, SNR, RSSI and BATTERY
     were empty for all but a handful of nodes, so most of the width was spent
     printing dashes. What is known is shown; what is not is simply absent. */
  .node {
    display:grid; grid-template-columns:auto 1fr auto; align-items:center;
    gap:4px 16px; padding:13px 16px;
    background:var(--bg-card, #16213e); border:1px solid var(--border, #2a2a4a);
    border-radius:12px;
  }
  .node .tag {
    grid-column:1; grid-row:1 / span 2; align-self:start;
    font-size:20px; font-weight:700; letter-spacing:.02em;
    color:var(--text, #eee); min-width:72px;
  }
  .node .tag small {
    display:block; font-weight:400; font-size:12px;
    color:var(--text-dim, #777); letter-spacing:0; margin-top:3px;
  }
  .node .name { grid-column:2; grid-row:1; font-size:19px; color:var(--text, #eee); min-width:0;
                overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .node .meta { grid-column:2; grid-row:2; font-size:14px; color:var(--text-muted, #aaa); }
  .node .meta span + span::before { content:" · "; color:var(--text-dim, #777); }
  .node .when {
    grid-column:3; grid-row:1 / span 2; text-align:right; font-size:15px;
    color:var(--text-muted, #aaa); white-space:nowrap;
  }

  /* Our own node, and nodes not heard from in over 30 minutes. Stale is shown
     by dimming the whole row, not by a colour that has to be learned. */
  .node.self { border-color:var(--link, #7ad); background:var(--bg-deep, #0f0f1a); }
  .node.self .tag::after {
    content:"this node"; display:block; font-size:11px; font-weight:600;
    letter-spacing:.1em; text-transform:uppercase; color:var(--link, #7ad);
    margin-top:4px;
  }
  .node.stale { opacity:.55; }

  .empty { color:var(--text-muted, #aaa); font-size:17px; padding:18px 0; }
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
    <div class="empty">Mesh daemon not running yet.</div>
  <?php elseif (!$rows): ?>
    <div class="empty">No nodes seen.</div>
  <?php else: ?>
  <p class="lede"><?= count($rows) ?> node<?= count($rows) === 1 ? '' : 's' ?> seen.
     Dimmed rows have not been heard from in over 30 minutes.</p>
  <div class="nodes">
    <?php foreach ($rows as $r):
      $self  = (int)($r['is_self'] ?? 0);
      $lh    = $r['last_heard'] ? (int)$r['last_heard'] : null;
      $age   = $lh ? (time() - $lh) : null;
      $stale = $age !== null && $age > 1800;

      // Only the facts this node actually reported.
      $meta = [];
      if (($r['hw_model'] ?? '') !== '')   $meta[] = esc($r['hw_model']);
      if ($r['hops_away'] !== null)        $meta[] = (int)$r['hops_away'] . ' hop'
                                                     . ((int)$r['hops_away'] === 1 ? '' : 's');
      if ($r['snr'] !== null)              $meta[] = esc((string)$r['snr']) . ' dB SNR';
      if ($r['rssi'] !== null)             $meta[] = esc((string)$r['rssi']) . ' dBm';
      if ($r['battery_pct'] !== null)      $meta[] = (int)$r['battery_pct'] . '% battery';
    ?>
      <div class="node <?= $self ? 'self' : ($stale ? 'stale' : '') ?>">
        <div class="tag"><?= esc($r['short_name'] ?: '?') ?><small><?= esc($r['node_id']) ?></small></div>
        <div class="name"><?= esc($r['long_name'] ?? '') ?: '<span style="opacity:.6">unnamed</span>' ?></div>
        <div class="when"><?= $lh ? esc(mesh_ago($lh)) . ' ago' : 'never heard' ?></div>
        <div class="meta"><?php foreach ($meta as $m): ?><span><?= $m ?></span><?php endforeach; ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body></html>
