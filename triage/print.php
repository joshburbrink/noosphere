<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_triage','0') !== '1') { http_response_code(404); exit; }
if (empty($_SESSION['admin'])) { http_response_code(403); exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

$db = new SQLite3('/var/lib/noosphere/triage.db', SQLITE3_OPEN_READONLY);
$p  = $db->querySingle("SELECT * FROM patients WHERE id=$id", true);
if (!$p) { http_response_code(404); echo 'Patient not found.'; exit; }

$PRIORITIES = [
    'immediate' => ['label'=>'IMMEDIATE', 'color'=>'#cc0000', 'bg'=>'#ffdddd'],
    'delayed'   => ['label'=>'DELAYED',   'color'=>'#b35c00', 'bg'=>'#fff3cc'],
    'minor'     => ['label'=>'MINOR',     'color'=>'#006600', 'bg'=>'#ddffdd'],
    'expectant' => ['label'=>'EXPECTANT', 'color'=>'#333',    'bg'=>'#ddd'   ],
];
$pr = $PRIORITIES[$p['priority']] ?? $PRIORITIES['minor'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Triage Tag <?= htmlspecialchars($p['tag_id']) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:Arial,sans-serif; background:#fff; color:#000; }
.tag {
    width:3.5in; min-height:2.5in;
    border:4px solid <?= $pr['color'] ?>;
    background:<?= $pr['bg'] ?>;
    border-radius:8px;
    padding:14px 16px;
    margin:20px auto;
    page-break-inside:avoid;
}
.priority-bar {
    background:<?= $pr['color'] ?>;
    color:#fff;
    font-size:22px;
    font-weight:bold;
    text-align:center;
    letter-spacing:.1em;
    padding:6px 0;
    border-radius:4px;
    margin-bottom:10px;
}
.tag-id { font-size:36px; font-weight:bold; text-align:center; color:<?= $pr['color'] ?>; letter-spacing:.05em; margin-bottom:8px; }
.row { display:flex; gap:6px; margin-bottom:5px; font-size:12px; }
.row-lbl { color:#555; min-width:70px; flex-shrink:0; }
.row-val { font-weight:bold; }
.complaint { margin-top:8px; padding-top:8px; border-top:1px solid <?= $pr['color'] ?>44; font-size:12px; }
.footer { text-align:center; font-size:10px; color:#999; margin-top:10px; }
@media print {
    body { margin:0; }
    .tag { margin:0; border-radius:0; }
    .no-print { display:none; }
}
</style>
</head>
<body>
<div class="no-print" style="text-align:center;padding:10px;font-family:sans-serif;font-size:13px;color:#555">
  <button onclick="window.print()" style="background:#e94560;color:#fff;border:none;padding:7px 18px;border-radius:5px;cursor:pointer;font-size:13px">Print Tag</button>
  &nbsp; <a href="/triage/" style="color:#888">← Back to Triage Log</a>
</div>
<div class="tag">
  <div class="priority-bar"><?= $pr['label'] ?></div>
  <div class="tag-id"><?= htmlspecialchars($p['tag_id']) ?></div>
  <?php if ($p['name'] || $p['age']): ?>
  <div class="row">
    <span class="row-lbl">Patient:</span>
    <span class="row-val"><?= htmlspecialchars(($p['name'] ?: 'Unknown') . ($p['age'] ? ' · ' . $p['age'] : '')) ?></span>
  </div>
  <?php endif ?>
  <?php if ($p['location']): ?>
  <div class="row">
    <span class="row-lbl">Location:</span>
    <span class="row-val"><?= htmlspecialchars($p['location']) ?></span>
  </div>
  <?php endif ?>
  <?php if ($p['caregiver']): ?>
  <div class="row">
    <span class="row-lbl">Caregiver:</span>
    <span class="row-val"><?= htmlspecialchars($p['caregiver']) ?></span>
  </div>
  <?php endif ?>
  <div class="row">
    <span class="row-lbl">Logged:</span>
    <span class="row-val"><?= date('H:i m/d', $p['created_at']) ?></span>
  </div>
  <?php if ($p['complaint']): ?>
  <div class="complaint"><?= htmlspecialchars($p['complaint']) ?></div>
  <?php endif ?>
  <div class="footer"><?= htmlspecialchars(get_setting('instance_name','Noosphere')) ?> · START Triage</div>
</div>
<script>window.onload = function(){ window.print(); };</script>
</body>
</html>
