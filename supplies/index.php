<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
sec_session_start();
if (get_setting('show_supplies','0') !== '1') { http_response_code(404); exit; }

$is_admin    = legacy_is_admin();
$is_readonly = is_readonly();

$CATEGORIES = [
    'water'   => 'Water',
    'food'    => 'Food',
    'fuel'    => 'Fuel',
    'medical' => 'Medical',
    'other'   => 'Other',
];

$db = new PDO('sqlite:/var/lib/noosphere/inventory.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS items (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    category           TEXT NOT NULL DEFAULT 'other',
    name               TEXT NOT NULL,
    quantity           REAL NOT NULL DEFAULT 0,
    unit               TEXT NOT NULL DEFAULT '',
    low_threshold      REAL NOT NULL DEFAULT 0,
    consumption_per_day REAL NOT NULL DEFAULT 0,
    last_updated       INTEGER NOT NULL DEFAULT 0,
    notes              TEXT NOT NULL DEFAULT ''
)");

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function item_status($qty, $low, $cpd) {
    if ($cpd > 0) {
        $days = $qty / $cpd;
        if ($days < 1)  return 'red';
        if ($days < 3)  return 'yellow';
        return 'green';
    }
    if ($low > 0) {
        if ($qty <= $low)          return 'red';
        if ($qty <= $low * 1.5)    return 'yellow';
    }
    return 'green';
}

function days_label($qty, $cpd) {
    if ($cpd <= 0) return '';
    $d = $qty / $cpd;
    if ($d >= 30) return '30+ days';
    if ($d < 1)   return round($d * 24) . 'h';
    return round($d, 1) . ' days';
}

// ── POST handlers ──────────────────────────────────────────────────────────
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can('supplies.edit')) { http_response_code(403); exit; }
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(403); exit; }

    $act = $_POST['act'] ?? '';

    if ($act === 'add_item') {
        $cat  = $_POST['category'] ?? 'other';
        $name = trim($_POST['name'] ?? '');
        $qty  = (float)($_POST['quantity'] ?? 0);
        $unit = trim($_POST['unit'] ?? '');
        $low  = (float)($_POST['low_threshold'] ?? 0);
        $cpd  = (float)($_POST['consumption_per_day'] ?? 0);
        $note = trim($_POST['notes'] ?? '');
        if ($name === '') { $err = 'Name is required.'; }
        else {
            $db->prepare("INSERT INTO items (category,name,quantity,unit,low_threshold,consumption_per_day,last_updated,notes) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$cat, $name, $qty, $unit, $low, $cpd, time(), $note]);
            header('Location: /supplies/'); exit;
        }
    }

    if ($act === 'edit_item') {
        $id   = (int)($_POST['id'] ?? 0);
        $cat  = $_POST['category'] ?? 'other';
        $name = trim($_POST['name'] ?? '');
        $qty  = (float)($_POST['quantity'] ?? 0);
        $unit = trim($_POST['unit'] ?? '');
        $low  = (float)($_POST['low_threshold'] ?? 0);
        $cpd  = (float)($_POST['consumption_per_day'] ?? 0);
        $note = trim($_POST['notes'] ?? '');
        if ($name === '') { $err = 'Name is required.'; }
        else {
            $db->prepare("UPDATE items SET category=?,name=?,quantity=?,unit=?,low_threshold=?,consumption_per_day=?,last_updated=?,notes=? WHERE id=?")
               ->execute([$cat, $name, $qty, $unit, $low, $cpd, time(), $note, $id]);
            header('Location: /supplies/'); exit;
        }
    }

    if ($act === 'update_qty') {
        $id  = (int)($_POST['id'] ?? 0);
        $qty = (float)($_POST['quantity'] ?? 0);
        $db->prepare("UPDATE items SET quantity=?,last_updated=? WHERE id=?")
           ->execute([$qty, time(), $id]);
        header('Location: /supplies/'); exit;
    }

    if ($act === 'delete_item') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
        header('Location: /supplies/'); exit;
    }
}

// ── Load data ──────────────────────────────────────────────────────────────
$items = $db->query("SELECT * FROM items ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);

// Overall status badge: worst status across all items
$overall = 'green';
foreach ($items as $it) {
    $s = item_status($it['quantity'], $it['low_threshold'], $it['consumption_per_day']);
    if ($s === 'red')    { $overall = 'red'; break; }
    if ($s === 'yellow') { $overall = 'yellow'; }
}

$status_colors = ['green'=>'#2ecc71','yellow'=>'#f39c12','red'=>'#e94560'];
$name = get_setting('instance_name','Noosphere');
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Supply Inventory  -  <?= esc($name) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:sans-serif; background:#1a1a2e; color:#eee; min-height:100vh; padding:1.5rem; }
h1 { font-size:1.5rem; margin-bottom:.25rem; }
.topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem; margin-bottom:1.5rem; }
.badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:13px; font-weight:bold; color:#111; }
.nav { font-size:13px; }
.nav a { color:#aaa; text-decoration:none; margin-right:1rem; }
.nav a:hover { color:#e94560; }
.section { margin-bottom:2rem; }
.section-title { font-size:1rem; font-weight:bold; color:#e94560; border-bottom:1px solid #2a2a4a; padding-bottom:.4rem; margin-bottom:.75rem; text-transform:uppercase; letter-spacing:.05em; }
table { width:100%; border-collapse:collapse; font-size:14px; }
th { text-align:left; padding:8px 10px; color:#888; font-weight:600; border-bottom:1px solid #2a2a4a; }
td { padding:8px 10px; border-bottom:1px solid #1e1e3a; vertical-align:top; }
tr:last-child td { border-bottom:none; }
.dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
.qty-form { display:flex; gap:6px; align-items:center; }
.qty-form input[type=number] { width:80px; padding:4px 6px; background:#111126; border:1px solid #2a2a4a; color:#eee; border-radius:4px; font-size:13px; }
.qty-form button { padding:4px 10px; background:#16213e; border:1px solid #e94560; color:#e94560; border-radius:4px; cursor:pointer; font-size:12px; }
.qty-form button:hover { background:#e94560; color:#fff; }
.days { font-size:12px; color:#aaa; margin-top:2px; }
.notes { font-size:12px; color:#888; }
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
.form-row textarea { resize:vertical; min-height:60px; }
.err { color:#e94560; font-size:14px; margin-bottom:1rem; }
.empty { color:#555; font-size:14px; padding:1rem 0; }
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:100; align-items:center; justify-content:center; }
.modal-bg.open { display:flex; }
.modal { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:1.5rem; width:100%; max-width:520px; }
.modal h3 { margin-bottom:1rem; }
</style>
</head>
<body>

<div class="topbar">
  <div>
    <h1>📦 Supply Inventory</h1>
    <div style="font-size:13px;color:#666;margin-top:2px">Last updated: <?= $items ? date('M j, g:ia', max(array_column($items,'last_updated'))) : 'never' ?></div>
  </div>
  <div style="display:flex;align-items:center;gap:1rem">
    <span class="badge" style="background:<?= $status_colors[$overall] ?>"><?= ucfirst($overall) ?></span>
    <div class="nav">
      <a href="/resources/">← Resources</a>
      <a href="/">Home</a>
    </div>
  </div>
</div>

<?php if ($err): ?><div class="err"><?= esc($err) ?></div><?php endif; ?>

<?php if (empty($items)): ?>
<div class="empty">No items yet. <?= $is_admin ? 'Add one below.' : 'Check back later.' ?></div>
<?php else: ?>

<?php
$grouped = [];
foreach ($items as $it) $grouped[$it['category']][] = $it;
foreach ($CATEGORIES as $ckey => $clabel):
    if (empty($grouped[$ckey])) continue;
?>
<div class="section">
  <div class="section-title"><?= esc($clabel) ?></div>
  <table>
    <thead><tr>
      <th>Status</th><th>Item</th><th>Quantity</th>
      <?php if ($is_admin): ?><th>Actions</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($grouped[$ckey] as $it):
        $st  = item_status($it['quantity'], $it['low_threshold'], $it['consumption_per_day']);
        $col = $status_colors[$st];
        $dl  = days_label($it['quantity'], $it['consumption_per_day']);
    ?>
    <tr>
      <td><span class="dot" style="background:<?= $col ?>"></span></td>
      <td>
        <?= esc($it['name']) ?>
        <?php if ($it['notes']): ?><div class="notes"><?= esc($it['notes']) ?></div><?php endif; ?>
      </td>
      <td>
        <?php if ($is_admin): ?>
        <form method="post" class="qty-form">
          <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
          <input type="hidden" name="act" value="update_qty">
          <input type="hidden" name="id" value="<?= $it['id'] ?>">
          <input type="number" name="quantity" value="<?= esc($it['quantity']) ?>" step="any" min="0">
          <span style="color:#888;font-size:13px"><?= esc($it['unit']) ?></span>
          <button type="submit">Update</button>
        </form>
        <?php else: ?>
        <?= esc($it['quantity']) ?> <?= esc($it['unit']) ?>
        <?php endif; ?>
        <?php if ($dl): ?><div class="days"><?= esc($dl) ?> remaining</div><?php endif; ?>
        <?php if ($it['low_threshold'] > 0 && !$dl): ?>
        <div class="days">Low at: <?= esc($it['low_threshold']) ?> <?= esc($it['unit']) ?></div>
        <?php endif; ?>
      </td>
      <?php if ($is_admin): ?>
      <td>
        <button class="btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($it), ENT_QUOTES) ?>)">Edit</button>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
          <input type="hidden" name="act" value="delete_item">
          <input type="hidden" name="id" value="<?= $it['id'] ?>">
          <button type="submit" class="btn-sm btn-del" onclick="return confirm('Delete this item?')">Delete</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($is_admin && !$is_readonly): ?>
<div class="add-panel">
  <h3>Add Item</h3>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
    <input type="hidden" name="act" value="add_item">
    <div class="form-row">
      <label>Category
        <select name="category">
          <?php foreach ($CATEGORIES as $ck => $cl): ?>
          <option value="<?= esc($ck) ?>"><?= esc($cl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="flex:2">Name *
        <input type="text" name="name" required placeholder="e.g. Bottled Water">
      </label>
      <label>Quantity
        <input type="number" name="quantity" value="0" step="any" min="0">
      </label>
      <label>Unit
        <input type="text" name="unit" placeholder="gallons, meals…" style="width:110px">
      </label>
    </div>
    <div class="form-row">
      <label>Low threshold
        <input type="number" name="low_threshold" value="0" step="any" min="0">
      </label>
      <label>Use per day <span style="font-size:11px;color:#555">(for days-remaining)</span>
        <input type="number" name="consumption_per_day" value="0" step="any" min="0">
      </label>
      <label style="flex:3">Notes
        <input type="text" name="notes" placeholder="optional">
      </label>
    </div>
    <button type="submit" class="btn">Add Item</button>
  </form>
</div>

<!-- Edit modal -->
<div class="modal-bg" id="editModal">
  <div class="modal">
    <h3>Edit Item</h3>
    <form method="post" id="editForm">
      <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
      <input type="hidden" name="act" value="edit_item">
      <input type="hidden" name="id" id="edit_id">
      <div class="form-row">
        <label>Category
          <select name="category" id="edit_category">
            <?php foreach ($CATEGORIES as $ck => $cl): ?>
            <option value="<?= esc($ck) ?>"><?= esc($cl) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="flex:2">Name *
          <input type="text" name="name" id="edit_name" required>
        </label>
      </div>
      <div class="form-row">
        <label>Quantity
          <input type="number" name="quantity" id="edit_quantity" step="any" min="0">
        </label>
        <label>Unit
          <input type="text" name="unit" id="edit_unit" style="width:110px">
        </label>
        <label>Low threshold
          <input type="number" name="low_threshold" id="edit_low" step="any" min="0">
        </label>
        <label>Use per day
          <input type="number" name="consumption_per_day" id="edit_cpd" step="any" min="0">
        </label>
      </div>
      <div class="form-row">
        <label style="flex:1">Notes
          <input type="text" name="notes" id="edit_notes">
        </label>
      </div>
      <div style="display:flex;gap:.75rem;margin-top:.5rem">
        <button type="submit" class="btn">Save</button>
        <button type="button" class="btn-sm" onclick="closeEdit()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(it) {
    document.getElementById('edit_id').value       = it.id;
    document.getElementById('edit_category').value = it.category;
    document.getElementById('edit_name').value     = it.name;
    document.getElementById('edit_quantity').value = it.quantity;
    document.getElementById('edit_unit').value     = it.unit;
    document.getElementById('edit_low').value      = it.low_threshold;
    document.getElementById('edit_cpd').value      = it.consumption_per_day;
    document.getElementById('edit_notes').value    = it.notes;
    document.getElementById('editModal').classList.add('open');
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('open');
}
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});
</script>
<?php endif; ?>

</body>
</html>
