<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();
if (get_setting('show_seeds','0') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();

$TYPES = ['heirloom'=>'Heirloom','hybrid'=>'Hybrid','op'=>'Open-Pollinated','unknown'=>'Unknown'];
$UNITS = ['packets','grams','oz','seeds'];

$db = new PDO('sqlite:/var/lib/noosphere/seeds.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS seeds (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    crop             TEXT NOT NULL,
    variety          TEXT NOT NULL DEFAULT '',
    type             TEXT NOT NULL DEFAULT 'unknown',
    quantity         REAL NOT NULL DEFAULT 0,
    unit             TEXT NOT NULL DEFAULT 'packets',
    germination_rate TEXT NOT NULL DEFAULT '',
    year_harvested   TEXT NOT NULL DEFAULT '',
    storage_location TEXT NOT NULL DEFAULT '',
    donated_by       TEXT NOT NULL DEFAULT '',
    notes            TEXT NOT NULL DEFAULT '',
    added_at         INTEGER NOT NULL
)");

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Zone 6a planting calendar — last frost ~Apr 15, first frost ~Oct 15
$CALENDAR = [
    ['crop'=>'Tomato',     'start_indoors'=>'Feb 15–Mar 15', 'transplant'=>'May 1–15',    'direct_sow'=>'—',          'harvest'=>'Jul–Oct',    'dtm'=>'60–85 days'],
    ['crop'=>'Pepper',     'start_indoors'=>'Feb 1–Mar 1',   'transplant'=>'May 15–Jun 1', 'direct_sow'=>'—',          'harvest'=>'Aug–Oct',    'dtm'=>'70–90 days'],
    ['crop'=>'Squash',     'start_indoors'=>'Apr 15–May 1',  'transplant'=>'May 10–20',   'direct_sow'=>'May 1–20',   'harvest'=>'Jul–Oct',    'dtm'=>'50–65 days'],
    ['crop'=>'Zucchini',   'start_indoors'=>'Apr 15–May 1',  'transplant'=>'May 10–20',   'direct_sow'=>'May 1–20',   'harvest'=>'Jun–Sep',    'dtm'=>'45–55 days'],
    ['crop'=>'Bean',       'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'May 1–Jul 1','harvest'=>'Jul–Sep',    'dtm'=>'50–60 days'],
    ['crop'=>'Corn',       'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'May 1–Jun 1','harvest'=>'Aug–Sep',    'dtm'=>'65–90 days'],
    ['crop'=>'Potato',     'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'Apr 1–May 1','harvest'=>'Jul–Sep',    'dtm'=>'70–120 days'],
    ['crop'=>'Onion',      'start_indoors'=>'Jan 15–Feb 15', 'transplant'=>'Mar 15–Apr 15','direct_sow'=>'Mar–Apr',   'harvest'=>'Jul–Aug',    'dtm'=>'100–120 days'],
    ['crop'=>'Carrot',     'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'Apr 1–Aug 1','harvest'=>'Jun–Oct',    'dtm'=>'70–80 days'],
    ['crop'=>'Lettuce',    'start_indoors'=>'Feb 15–Mar 15', 'transplant'=>'Apr 1–15',    'direct_sow'=>'Apr 1–May 1, Aug–Sep','harvest'=>'May–Jun, Sep–Oct','dtm'=>'45–60 days'],
    ['crop'=>'Broccoli',   'start_indoors'=>'Feb 15–Mar 15', 'transplant'=>'Apr 1–15',    'direct_sow'=>'—',          'harvest'=>'May–Jun',    'dtm'=>'60–80 days'],
    ['crop'=>'Cabbage',    'start_indoors'=>'Feb 1–Mar 1',   'transplant'=>'Mar 15–Apr 15','direct_sow'=>'—',          'harvest'=>'Jun–Jul',    'dtm'=>'80–100 days'],
    ['crop'=>'Kale',       'start_indoors'=>'Feb 15–Mar 15', 'transplant'=>'Apr 1–15',    'direct_sow'=>'Apr–May, Jul–Aug','harvest'=>'May–Nov','dtm'=>'50–70 days'],
    ['crop'=>'Cucumber',   'start_indoors'=>'Apr 15–May 1',  'transplant'=>'May 15–Jun 1','direct_sow'=>'May 15–Jun 1','harvest'=>'Jul–Sep',   'dtm'=>'50–70 days'],
    ['crop'=>'Pumpkin',    'start_indoors'=>'Apr 15–May 1',  'transplant'=>'May 10–20',   'direct_sow'=>'May 1–15',   'harvest'=>'Sep–Oct',    'dtm'=>'90–120 days'],
    ['crop'=>'Sunflower',  'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'May 1–Jun 1','harvest'=>'Aug–Sep',    'dtm'=>'70–100 days'],
    ['crop'=>'Basil',      'start_indoors'=>'Apr 1–May 1',   'transplant'=>'May 15–Jun 1','direct_sow'=>'May 15+',    'harvest'=>'Jun–Sep',    'dtm'=>'25–30 days'],
    ['crop'=>'Cilantro',   'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'Apr–May, Aug–Sep','harvest'=>'May–Jun, Sep–Oct','dtm'=>'45–70 days'],
    ['crop'=>'Radish',     'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'Apr–May, Aug–Sep','harvest'=>'May, Sep','dtm'=>'25–30 days'],
    ['crop'=>'Spinach',    'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'Mar–Apr, Aug–Sep','harvest'=>'Apr–May, Sep–Oct','dtm'=>'40–50 days'],
    ['crop'=>'Sweet Potato','start_indoors'=>'Mar 1–Apr 1',  'transplant'=>'May 15–Jun 1','direct_sow'=>'—',          'harvest'=>'Sep–Oct',    'dtm'=>'90–120 days'],
    ['crop'=>'Garlic',     'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'Oct (fall)',  'harvest'=>'Jun–Jul',    'dtm'=>'240–270 days'],
    ['crop'=>'Pea',        'start_indoors'=>'—',             'transplant'=>'—',            'direct_sow'=>'Mar 15–Apr 15','harvest'=>'May–Jun',  'dtm'=>'55–75 days'],
    ['crop'=>'Watermelon', 'start_indoors'=>'Apr 15–May 1',  'transplant'=>'May 20–Jun 1','direct_sow'=>'May 20+',    'harvest'=>'Aug–Sep',    'dtm'=>'80–90 days'],
];

// ── POST handlers ──────────────────────────────────────────────────────────
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_admin) { http_response_code(403); exit; }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(403); exit; }

    $act = $_POST['act'] ?? '';

    if ($act === 'add_seed') {
        $crop = trim($_POST['crop'] ?? '');
        if ($crop === '') { $err = 'Crop name is required.'; }
        else {
            $db->prepare("INSERT INTO seeds (crop,variety,type,quantity,unit,germination_rate,year_harvested,storage_location,donated_by,notes,added_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $crop,
                   trim($_POST['variety'] ?? ''),
                   $_POST['type'] ?? 'unknown',
                   (float)($_POST['quantity'] ?? 0),
                   $_POST['unit'] ?? 'packets',
                   trim($_POST['germination_rate'] ?? ''),
                   trim($_POST['year_harvested'] ?? ''),
                   trim($_POST['storage_location'] ?? ''),
                   trim($_POST['donated_by'] ?? ''),
                   trim($_POST['notes'] ?? ''),
                   time(),
               ]);
            header('Location: /seeds/'); exit;
        }
    }

    if ($act === 'edit_seed') {
        $id   = (int)($_POST['id'] ?? 0);
        $crop = trim($_POST['crop'] ?? '');
        if ($crop === '') { $err = 'Crop name is required.'; }
        else {
            $db->prepare("UPDATE seeds SET crop=?,variety=?,type=?,quantity=?,unit=?,germination_rate=?,year_harvested=?,storage_location=?,donated_by=?,notes=? WHERE id=?")
               ->execute([
                   $crop,
                   trim($_POST['variety'] ?? ''),
                   $_POST['type'] ?? 'unknown',
                   (float)($_POST['quantity'] ?? 0),
                   $_POST['unit'] ?? 'packets',
                   trim($_POST['germination_rate'] ?? ''),
                   trim($_POST['year_harvested'] ?? ''),
                   trim($_POST['storage_location'] ?? ''),
                   trim($_POST['donated_by'] ?? ''),
                   trim($_POST['notes'] ?? ''),
                   $id,
               ]);
            header('Location: /seeds/'); exit;
        }
    }

    if ($act === 'delete_seed') {
        $db->prepare("DELETE FROM seeds WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        header('Location: /seeds/'); exit;
    }
}

// ── Load data ──────────────────────────────────────────────────────────────
$tab    = in_array($_GET['tab'] ?? '', ['calendar']) ? 'calendar' : 'catalog';
$search = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM seeds";
$params = [];
if ($search !== '') {
    $sql .= " WHERE crop LIKE ? OR variety LIKE ? OR notes LIKE ?";
    $like = '%'.$search.'%';
    $params = [$like, $like, $like];
}
$sql .= " ORDER BY crop, variety";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$seeds = $stmt->fetchAll(PDO::FETCH_ASSOC);

$name = get_setting('instance_name','Noosphere');
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Seed Library — <?= esc($name) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; padding:1.5rem; }
h1 { font-size:1.5rem; margin-bottom:.25rem; }
.topbar { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:.75rem; margin-bottom:1.5rem; }
.nav a { color:#aaa; text-decoration:none; margin-left:1rem; font-size:13px; }
.nav a:hover { color:#e94560; }
.tabs { display:flex; gap:0; margin-bottom:1.5rem; border-bottom:1px solid #2a2a4a; }
.tab { padding:8px 20px; cursor:pointer; text-decoration:none; color:#aaa; font-size:14px; border-bottom:2px solid transparent; margin-bottom:-1px; }
.tab.active { color:#e94560; border-bottom-color:#e94560; }
.tab:hover:not(.active) { color:#eee; }
.search-row { display:flex; gap:.75rem; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; }
.search-row input { padding:7px 12px; background:#16213e; border:1px solid #2a2a4a; color:#eee; border-radius:6px; font-size:14px; width:260px; }
.search-row input:focus { outline:none; border-color:#e94560; }
.count { color:#555; font-size:13px; }
table { width:100%; border-collapse:collapse; font-size:14px; }
th { text-align:left; padding:8px 10px; color:#888; font-weight:600; border-bottom:1px solid #2a2a4a; }
td { padding:8px 10px; border-bottom:1px solid #1e1e3a; vertical-align:top; }
tr:last-child td { border-bottom:none; }
.tag { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:bold; }
.tag-heirloom { background:#1a3a1a; color:#2ecc71; }
.tag-op       { background:#1a2a3a; color:#3498db; }
.tag-hybrid   { background:#2a1a2a; color:#9b59b6; }
.tag-unknown  { background:#2a2a2a; color:#888; }
.qty-low { color:#e94560; font-weight:bold; }
.notes { font-size:12px; color:#888; margin-top:2px; }
.btn { padding:8px 18px; background:#e94560; border:none; color:#fff; border-radius:6px; cursor:pointer; font-size:14px; }
.btn:hover { background:#c73652; }
.btn-sm { padding:4px 10px; font-size:12px; background:#16213e; border:1px solid #444; color:#ccc; border-radius:4px; cursor:pointer; }
.btn-sm:hover { border-color:#e94560; color:#e94560; }
.btn-del { border-color:#5a1a1a; color:#e94560; }
.btn-del:hover { background:#e94560; color:#fff; border-color:#e94560; }
.add-panel { background:#16213e; border:1px solid #2a2a4a; border-radius:8px; padding:1.25rem; margin-top:1.5rem; }
.add-panel h3 { font-size:.95rem; margin-bottom:1rem; color:#ccc; }
.form-row { display:flex; flex-wrap:wrap; gap:.75rem; margin-bottom:.75rem; }
.form-row label { display:flex; flex-direction:column; gap:4px; font-size:13px; color:#aaa; }
.form-row input, .form-row select, .form-row textarea {
    padding:6px 10px; background:#111126; border:1px solid #2a2a4a; color:#eee; border-radius:4px; font-size:13px; font-family:inherit;
}
.err { color:#e94560; font-size:14px; margin-bottom:1rem; }
.empty { color:#555; font-size:14px; padding:1rem 0; }
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:100; align-items:center; justify-content:center; }
.modal-bg.open { display:flex; }
.modal { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:1.5rem; width:100%; max-width:560px; max-height:90vh; overflow-y:auto; }
.modal h3 { margin-bottom:1rem; }
/* Calendar styles */
.cal-table { width:100%; border-collapse:collapse; font-size:13px; }
.cal-table th { padding:8px 10px; background:#16213e; color:#e94560; text-align:left; border-bottom:2px solid #2a2a4a; white-space:nowrap; }
.cal-table td { padding:7px 10px; border-bottom:1px solid #1e1e3a; vertical-align:top; }
.cal-table tr:last-child td { border-bottom:none; }
.cal-table tr:hover td { background:#16213e; }
.cal-crop { font-weight:bold; }
.frost-note { background:#1a1a3a; border:1px solid #2a2a5a; border-radius:6px; padding:.75rem 1rem; margin-bottom:1.25rem; font-size:13px; color:#aaa; }
.frost-note strong { color:#eee; }
@media print {
    .topbar, .tabs, .search-row, .add-panel, .modal-bg, .btn, .btn-sm, .nav { display:none !important; }
    body { background:#fff; color:#000; padding:.5rem; }
    table th { background:#eee; color:#000; }
}
</style>
</head>
<body>

<div class="topbar">
  <div>
    <h1>🌱 Seed Library</h1>
    <div style="font-size:13px;color:#666;margin-top:2px">Zone 6a — Bartholomew &amp; Brown County, Indiana</div>
  </div>
  <div class="nav">
    <a href="/resources/">← Resources</a>
    <a href="/">Home</a>
    <?php if ($tab === 'catalog'): ?><a href="javascript:window.print()">Print</a><?php endif; ?>
  </div>
</div>

<div class="tabs">
  <a class="tab <?= $tab==='catalog'?'active':'' ?>" href="/seeds/?tab=catalog">Catalog</a>
  <a class="tab <?= $tab==='calendar'?'active':'' ?>" href="/seeds/?tab=calendar">Planting Calendar</a>
</div>

<?php if ($err): ?><div class="err"><?= esc($err) ?></div><?php endif; ?>

<?php if ($tab === 'catalog'): ?>

<div class="search-row">
  <form method="get" style="display:flex;gap:.5rem;align-items:center">
    <input type="hidden" name="tab" value="catalog">
    <input type="text" name="q" value="<?= esc($search) ?>" placeholder="Search crops…" autofocus>
    <button type="submit" class="btn-sm" style="padding:7px 14px">Search</button>
    <?php if ($search): ?><a href="/seeds/" class="btn-sm">Clear</a><?php endif; ?>
  </form>
  <span class="count"><?= count($seeds) ?> <?= count($seeds) === 1 ? 'variety' : 'varieties' ?></span>
</div>

<?php if (empty($seeds)): ?>
<div class="empty"><?= $search ? 'No matches.' : 'No seeds in catalog yet.' ?> <?= ($is_admin && !$search) ? 'Add one below.' : '' ?></div>
<?php else: ?>
<table>
  <thead><tr>
    <th>Crop</th><th>Variety</th><th>Type</th><th>Quantity</th><th>Year</th><th>Location</th>
    <?php if ($is_admin): ?><th>Actions</th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($seeds as $s): ?>
  <tr>
    <td class="cal-crop"><?= esc($s['crop']) ?></td>
    <td><?= esc($s['variety']) ?: '<span style="color:#555">—</span>' ?></td>
    <td><span class="tag tag-<?= esc($s['type']) ?>"><?= esc($TYPES[$s['type']] ?? $s['type']) ?></span></td>
    <td>
      <span class="<?= $s['quantity'] <= 0 ? 'qty-low' : '' ?>"><?= esc($s['quantity']) ?> <?= esc($s['unit']) ?></span>
      <?php if ($s['germination_rate']): ?><div class="notes">Germ: <?= esc($s['germination_rate']) ?></div><?php endif; ?>
      <?php if ($s['donated_by']): ?><div class="notes">From: <?= esc($s['donated_by']) ?></div><?php endif; ?>
      <?php if ($s['notes']): ?><div class="notes"><?= esc($s['notes']) ?></div><?php endif; ?>
    </td>
    <td><?= esc($s['year_harvested']) ?: '<span style="color:#555">—</span>' ?></td>
    <td><?= esc($s['storage_location']) ?: '<span style="color:#555">—</span>' ?></td>
    <?php if ($is_admin): ?>
    <td>
      <button class="btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)">Edit</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
        <input type="hidden" name="act" value="delete_seed">
        <input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn-sm btn-del" onclick="return confirm('Delete this entry?')">Delete</button>
      </form>
    </td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($is_admin && !$is_readonly): ?>
<div class="add-panel">
  <h3>Add Seed</h3>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
    <input type="hidden" name="act" value="add_seed">
    <div class="form-row">
      <label style="flex:2">Crop *
        <input type="text" name="crop" required placeholder="e.g. Tomato">
      </label>
      <label style="flex:2">Variety
        <input type="text" name="variety" placeholder="e.g. Cherokee Purple">
      </label>
      <label>Type
        <select name="type">
          <?php foreach ($TYPES as $tk => $tl): ?>
          <option value="<?= esc($tk) ?>"><?= esc($tl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="form-row">
      <label>Quantity
        <input type="number" name="quantity" value="0" step="any" min="0" style="width:90px">
      </label>
      <label>Unit
        <select name="unit">
          <?php foreach ($UNITS as $u): ?><option><?= esc($u) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Germination %
        <input type="text" name="germination_rate" placeholder="e.g. 85%" style="width:100px">
      </label>
      <label>Year harvested
        <input type="text" name="year_harvested" placeholder="2024" style="width:90px">
      </label>
      <label>Storage location
        <input type="text" name="storage_location" placeholder="e.g. Bin A">
      </label>
    </div>
    <div class="form-row">
      <label>Donated by
        <input type="text" name="donated_by" placeholder="optional">
      </label>
      <label style="flex:2">Notes
        <input type="text" name="notes" placeholder="optional">
      </label>
    </div>
    <button type="submit" class="btn">Add Seed</button>
  </form>
</div>

<!-- Edit modal -->
<div class="modal-bg" id="editModal">
  <div class="modal">
    <h3>Edit Seed</h3>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
      <input type="hidden" name="act" value="edit_seed">
      <input type="hidden" name="id" id="edit_id">
      <div class="form-row">
        <label style="flex:2">Crop *<input type="text" name="crop" id="edit_crop" required></label>
        <label style="flex:2">Variety<input type="text" name="variety" id="edit_variety"></label>
        <label>Type<select name="type" id="edit_type">
          <?php foreach ($TYPES as $tk => $tl): ?><option value="<?= esc($tk) ?>"><?= esc($tl) ?></option><?php endforeach; ?>
        </select></label>
      </div>
      <div class="form-row">
        <label>Quantity<input type="number" name="quantity" id="edit_qty" step="any" min="0" style="width:90px"></label>
        <label>Unit<select name="unit" id="edit_unit"><?php foreach ($UNITS as $u): ?><option><?= esc($u) ?></option><?php endforeach; ?></select></label>
        <label>Germination %<input type="text" name="germination_rate" id="edit_germ" style="width:100px"></label>
        <label>Year<input type="text" name="year_harvested" id="edit_year" style="width:90px"></label>
        <label>Location<input type="text" name="storage_location" id="edit_loc"></label>
      </div>
      <div class="form-row">
        <label>Donated by<input type="text" name="donated_by" id="edit_donor"></label>
        <label style="flex:2">Notes<input type="text" name="notes" id="edit_notes"></label>
      </div>
      <div style="display:flex;gap:.75rem;margin-top:.5rem">
        <button type="submit" class="btn">Save</button>
        <button type="button" class="btn-sm" onclick="closeEdit()">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
function openEdit(s) {
    document.getElementById('edit_id').value    = s.id;
    document.getElementById('edit_crop').value  = s.crop;
    document.getElementById('edit_variety').value = s.variety;
    document.getElementById('edit_type').value  = s.type;
    document.getElementById('edit_qty').value   = s.quantity;
    document.getElementById('edit_unit').value  = s.unit;
    document.getElementById('edit_germ').value  = s.germination_rate;
    document.getElementById('edit_year').value  = s.year_harvested;
    document.getElementById('edit_loc').value   = s.storage_location;
    document.getElementById('edit_donor').value = s.donated_by;
    document.getElementById('edit_notes').value = s.notes;
    document.getElementById('editModal').classList.add('open');
}
function closeEdit() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('editModal').addEventListener('click',function(e){ if(e.target===this) closeEdit(); });
</script>
<?php endif; ?>

<?php else: // calendar tab ?>

<div class="frost-note">
  <strong>Zone 6a — Southern Indiana</strong> &nbsp;|&nbsp;
  Last frost: <strong>~April 15</strong> &nbsp;|&nbsp;
  First frost: <strong>~October 15</strong> &nbsp;|&nbsp;
  Growing season: <strong>~183 days</strong>
</div>

<table class="cal-table">
  <thead><tr>
    <th>Crop</th>
    <th>Start Indoors</th>
    <th>Transplant Out</th>
    <th>Direct Sow</th>
    <th>Harvest</th>
    <th>Days to Maturity</th>
  </tr></thead>
  <tbody>
  <?php foreach ($CALENDAR as $r): ?>
  <tr>
    <td class="cal-crop"><?= esc($r['crop']) ?></td>
    <td><?= esc($r['start_indoors']) ?></td>
    <td><?= esc($r['transplant']) ?></td>
    <td><?= esc($r['direct_sow']) ?></td>
    <td><?= esc($r['harvest']) ?></td>
    <td><?= esc($r['dtm']) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php endif; ?>

</body>
</html>
