<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
sec_session_start();
if (get_setting('show_tools','0') !== '1') { http_response_code(404); exit; }

$is_admin    = legacy_is_admin();
$is_readonly = is_readonly();

$CATEGORIES = [
    'hand'    => 'Hand Tools',
    'power'   => 'Power Tools',
    'medical' => 'Medical',
    'shelter' => 'Shelter & Construction',
    'comms'   => 'Communications',
    'vehicle' => 'Vehicle & Transport',
    'other'   => 'Other',
];

$CONDITIONS = ['good'=>'Good','fair'=>'Fair','poor'=>'Poor — use with caution'];

$db = new PDO('sqlite:/var/lib/noosphere/tools.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS tools (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    name           TEXT NOT NULL,
    category       TEXT NOT NULL DEFAULT 'other',
    description    TEXT NOT NULL DEFAULT '',
    condition_val  TEXT NOT NULL DEFAULT 'good',
    quantity_total INTEGER NOT NULL DEFAULT 1,
    notes          TEXT NOT NULL DEFAULT ''
)");
$db->exec("CREATE TABLE IF NOT EXISTS checkouts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    tool_id         INTEGER NOT NULL,
    borrower_name   TEXT NOT NULL,
    checked_out_at  INTEGER NOT NULL,
    expected_return TEXT NOT NULL DEFAULT '',
    returned_at     INTEGER
)");

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── POST handlers ──────────────────────────────────────────────────────────
$err = '';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(403); exit; }

    $act = $_POST['act'] ?? '';

    // Public: check out a tool
    if ($act === 'checkout' && !$is_readonly) {
        $tool_id  = (int)($_POST['tool_id'] ?? 0);
        $borrower = trim($_POST['borrower_name'] ?? '');
        $ret_date = trim($_POST['expected_return'] ?? '');
        if ($borrower === '') { $err = 'Your name is required.'; }
        else {
            // Verify availability
            $avail = $db->prepare("SELECT t.quantity_total - COUNT(c.id) AS avail FROM tools t LEFT JOIN checkouts c ON c.tool_id=t.id AND c.returned_at IS NULL WHERE t.id=?");
            $avail->execute([$tool_id]);
            $row = $avail->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['avail'] < 1) { $err = 'Sorry, that item is not available.'; }
            else {
                $db->prepare("INSERT INTO checkouts (tool_id,borrower_name,checked_out_at,expected_return) VALUES (?,?,?,?)")
                   ->execute([$tool_id, $borrower, time(), $ret_date]);
                $msg = 'Checked out successfully. Please return it when done.';
            }
        }
    }

    // Public: return a tool by checkout id
    if ($act === 'checkin' && !$is_readonly) {
        $checkout_id = (int)($_POST['checkout_id'] ?? 0);
        $db->prepare("UPDATE checkouts SET returned_at=? WHERE id=? AND returned_at IS NULL")
           ->execute([time(), $checkout_id]);
        $msg = 'Item returned. Thank you!';
    }

    // Manager actions below
    if (can('tools.manage')) {
        if ($act === 'add_tool') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { $err = 'Name is required.'; }
            else {
                $db->prepare("INSERT INTO tools (name,category,description,condition_val,quantity_total,notes) VALUES (?,?,?,?,?,?)")
                   ->execute([
                       $name,
                       $_POST['category'] ?? 'other',
                       trim($_POST['description'] ?? ''),
                       $_POST['condition_val'] ?? 'good',
                       max(1,(int)($_POST['quantity_total'] ?? 1)),
                       trim($_POST['notes'] ?? ''),
                   ]);
                header('Location: /tools/'); exit;
            }
        }

        if ($act === 'edit_tool') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { $err = 'Name is required.'; }
            else {
                $db->prepare("UPDATE tools SET name=?,category=?,description=?,condition_val=?,quantity_total=?,notes=? WHERE id=?")
                   ->execute([
                       $name,
                       $_POST['category'] ?? 'other',
                       trim($_POST['description'] ?? ''),
                       $_POST['condition_val'] ?? 'good',
                       max(1,(int)($_POST['quantity_total'] ?? 1)),
                       trim($_POST['notes'] ?? ''),
                       $id,
                   ]);
                header('Location: /tools/'); exit;
            }
        }

        if ($act === 'delete_tool') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM checkouts WHERE tool_id=?")->execute([$id]);
            $db->prepare("DELETE FROM tools WHERE id=?")->execute([$id]);
            header('Location: /tools/'); exit;
        }

        if ($act === 'admin_return') {
            $checkout_id = (int)($_POST['checkout_id'] ?? 0);
            $db->prepare("UPDATE checkouts SET returned_at=? WHERE id=?")->execute([time(), $checkout_id]);
            header('Location: /tools/'); exit;
        }
    }
}

// ── Load data ──────────────────────────────────────────────────────────────
$view = $_GET['view'] ?? 'browse';
if (!in_array($view, ['browse','checkedout'])) $view = 'browse';

$tools_raw = $db->query("SELECT t.*, (t.quantity_total - COUNT(c.id)) AS avail FROM tools t LEFT JOIN checkouts c ON c.tool_id=t.id AND c.returned_at IS NULL GROUP BY t.id ORDER BY t.category, t.name")->fetchAll(PDO::FETCH_ASSOC);

$active_checkouts = $db->query("SELECT c.*, t.name AS tool_name FROM checkouts c JOIN tools t ON t.id=c.tool_id WHERE c.returned_at IS NULL ORDER BY c.checked_out_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Group tools by category
$grouped = [];
foreach ($tools_raw as $t) $grouped[$t['category']][] = $t;

$name = get_setting('instance_name','Noosphere');
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tool Lending — <?= esc($name) ?></title>
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
.section { margin-bottom:2rem; }
.section-title { font-size:1rem; font-weight:bold; color:#e94560; border-bottom:1px solid #2a2a4a; padding-bottom:.4rem; margin-bottom:.75rem; text-transform:uppercase; letter-spacing:.05em; }
.tool-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:1rem; }
.tool-card { background:#16213e; border:1px solid #2a2a4a; border-radius:8px; padding:1rem; }
.tool-card.unavail { opacity:.6; }
.tool-name { font-weight:bold; font-size:15px; margin-bottom:.25rem; }
.tool-meta { font-size:12px; color:#777; margin-bottom:.5rem; }
.avail-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-size:12px; font-weight:bold; }
.avail-yes { background:#1a3a1a; color:#2ecc71; }
.avail-no  { background:#3a1a1a; color:#e94560; }
.avail-low { background:#3a2a1a; color:#f39c12; }
.checkout-form { margin-top:.75rem; border-top:1px solid #2a2a4a; padding-top:.75rem; }
.checkout-form input { width:100%; padding:6px 10px; background:#111126; border:1px solid #2a2a4a; color:#eee; border-radius:4px; font-size:13px; margin-bottom:.5rem; font-family:inherit; }
.checkout-form input:focus { outline:none; border-color:#e94560; }
.btn { padding:8px 18px; background:#e94560; border:none; color:#fff; border-radius:6px; cursor:pointer; font-size:14px; }
.btn:hover { background:#c73652; }
.btn-sm { padding:4px 10px; font-size:12px; background:#16213e; border:1px solid #444; color:#ccc; border-radius:4px; cursor:pointer; }
.btn-sm:hover { border-color:#e94560; color:#e94560; }
.btn-del { border-color:#5a1a1a; color:#e94560; }
.btn-del:hover { background:#e94560; color:#fff; border-color:#e94560; }
.btn-full { width:100%; }
.err { color:#e94560; font-size:14px; margin-bottom:1rem; padding:.75rem 1rem; background:#2a1a1a; border-radius:6px; }
.msg { color:#2ecc71; font-size:14px; margin-bottom:1rem; padding:.75rem 1rem; background:#1a2a1a; border-radius:6px; }
.empty { color:#555; font-size:14px; padding:1rem 0; }
.add-panel { background:#16213e; border:1px solid #2a2a4a; border-radius:8px; padding:1.25rem; margin-top:1.5rem; }
.add-panel h3 { font-size:.95rem; margin-bottom:1rem; color:#ccc; }
.form-row { display:flex; flex-wrap:wrap; gap:.75rem; margin-bottom:.75rem; }
.form-row label { display:flex; flex-direction:column; gap:4px; font-size:13px; color:#aaa; }
.form-row input, .form-row select, .form-row textarea {
    padding:6px 10px; background:#111126; border:1px solid #2a2a4a; color:#eee; border-radius:4px; font-size:13px; font-family:inherit;
}
.co-table { width:100%; border-collapse:collapse; font-size:14px; }
.co-table th { text-align:left; padding:8px 10px; color:#888; font-weight:600; border-bottom:1px solid #2a2a4a; }
.co-table td { padding:8px 10px; border-bottom:1px solid #1e1e3a; vertical-align:top; }
.co-table tr:last-child td { border-bottom:none; }
.overdue { color:#e94560; font-weight:bold; }
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:100; align-items:center; justify-content:center; }
.modal-bg.open { display:flex; }
.modal { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:1.5rem; width:100%; max-width:520px; }
.modal h3 { margin-bottom:1rem; }
</style>
</head>
<body>

<div class="topbar">
  <div>
    <h1>🔧 Tool Lending</h1>
    <div style="font-size:13px;color:#666;margin-top:2px">
      <?= count($tools_raw) ?> items &nbsp;·&nbsp; <?= count($active_checkouts) ?> checked out
    </div>
  </div>
  <div class="nav">
    <a href="/resources/">← Resources</a>
    <a href="/">Home</a>
  </div>
</div>

<div class="tabs">
  <a class="tab <?= $view==='browse'?'active':'' ?>" href="/tools/?view=browse">Browse</a>
  <a class="tab <?= $view==='checkedout'?'active':'' ?>" href="/tools/?view=checkedout">Checked Out (<?= count($active_checkouts) ?>)</a>
</div>

<?php if ($err): ?><div class="err"><?= esc($err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="msg"><?= esc($msg) ?></div><?php endif; ?>

<?php if ($view === 'browse'): ?>

<?php if (empty($tools_raw)): ?>
<div class="empty">No tools in the library yet.<?= $is_admin ? ' Add one below.' : '' ?></div>
<?php else: ?>

<?php foreach ($CATEGORIES as $ckey => $clabel):
    if (empty($grouped[$ckey])) continue;
?>
<div class="section">
  <div class="section-title"><?= esc($clabel) ?></div>
  <div class="tool-grid">
  <?php foreach ($grouped[$ckey] as $t):
      $avail = (int)$t['avail'];
      $total = (int)$t['quantity_total'];
      $avail_class = $avail < 1 ? 'avail-no' : ($avail < $total ? 'avail-low' : 'avail-yes');
      $avail_label = $avail < 1 ? 'Not available' : ($avail === $total ? "$avail available" : "$avail of $total available");
  ?>
  <div class="tool-card <?= $avail < 1 ? 'unavail' : '' ?>">
    <div class="tool-name"><?= esc($t['name']) ?></div>
    <div class="tool-meta">
      <?= esc($CONDITIONS[$t['condition_val']] ?? $t['condition_val']) ?>
      <?php if ($t['description']): ?> · <?= esc($t['description']) ?><?php endif; ?>
      <?php if ($t['notes']): ?><br><span style="color:#666"><?= esc($t['notes']) ?></span><?php endif; ?>
    </div>
    <span class="avail-badge <?= $avail_class ?>"><?= esc($avail_label) ?></span>

    <?php if ($is_admin): ?>
    <div style="margin-top:.75rem;display:flex;gap:.5rem">
      <button class="btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)">Edit</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
        <input type="hidden" name="act" value="delete_tool">
        <input type="hidden" name="id" value="<?= $t['id'] ?>">
        <button type="submit" class="btn-sm btn-del" onclick="return confirm('Delete this tool and all its checkout records?')">Delete</button>
      </form>
    </div>
    <?php elseif ($avail > 0 && !$is_readonly): ?>
    <div class="checkout-form">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
        <input type="hidden" name="act" value="checkout">
        <input type="hidden" name="tool_id" value="<?= $t['id'] ?>">
        <input type="text" name="borrower_name" placeholder="Your name" required>
        <input type="date" name="expected_return" placeholder="Return by (optional)">
        <button type="submit" class="btn btn-full" style="font-size:13px;padding:7px">Check Out</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($is_admin && !$is_readonly): ?>
<div class="add-panel">
  <h3>Add Tool / Equipment</h3>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
    <input type="hidden" name="act" value="add_tool">
    <div class="form-row">
      <label style="flex:2">Name *<input type="text" name="name" required placeholder="e.g. Chainsaw"></label>
      <label>Category
        <select name="category">
          <?php foreach ($CATEGORIES as $ck => $cl): ?><option value="<?= esc($ck) ?>"><?= esc($cl) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Condition
        <select name="condition_val">
          <?php foreach ($CONDITIONS as $ck => $cl): ?><option value="<?= esc($ck) ?>"><?= esc($cl) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Qty<input type="number" name="quantity_total" value="1" min="1" style="width:70px"></label>
    </div>
    <div class="form-row">
      <label style="flex:2">Description<input type="text" name="description" placeholder="optional"></label>
      <label style="flex:2">Notes<input type="text" name="notes" placeholder="optional"></label>
    </div>
    <button type="submit" class="btn">Add Item</button>
  </form>
</div>

<!-- Edit modal -->
<div class="modal-bg" id="editModal">
  <div class="modal">
    <h3>Edit Tool</h3>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
      <input type="hidden" name="act" value="edit_tool">
      <input type="hidden" name="id" id="edit_id">
      <div class="form-row">
        <label style="flex:2">Name *<input type="text" name="name" id="edit_name" required></label>
        <label>Category<select name="category" id="edit_cat">
          <?php foreach ($CATEGORIES as $ck => $cl): ?><option value="<?= esc($ck) ?>"><?= esc($cl) ?></option><?php endforeach; ?>
        </select></label>
        <label>Condition<select name="condition_val" id="edit_cond">
          <?php foreach ($CONDITIONS as $ck => $cl): ?><option value="<?= esc($ck) ?>"><?= esc($cl) ?></option><?php endforeach; ?>
        </select></label>
        <label>Qty<input type="number" name="quantity_total" id="edit_qty" min="1" style="width:70px"></label>
      </div>
      <div class="form-row">
        <label style="flex:2">Description<input type="text" name="description" id="edit_desc"></label>
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
function openEdit(t) {
    document.getElementById('edit_id').value    = t.id;
    document.getElementById('edit_name').value  = t.name;
    document.getElementById('edit_cat').value   = t.category;
    document.getElementById('edit_cond').value  = t.condition_val;
    document.getElementById('edit_qty').value   = t.quantity_total;
    document.getElementById('edit_desc').value  = t.description;
    document.getElementById('edit_notes').value = t.notes;
    document.getElementById('editModal').classList.add('open');
}
function closeEdit() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('editModal').addEventListener('click',function(e){ if(e.target===this) closeEdit(); });
</script>
<?php endif; ?>

<?php else: // checked out view ?>

<?php if (empty($active_checkouts)): ?>
<div class="empty">Nothing is currently checked out.</div>
<?php else: ?>
<table class="co-table">
  <thead><tr>
    <th>Item</th><th>Borrower</th><th>Checked Out</th><th>Return By</th><th>Action</th>
  </tr></thead>
  <tbody>
  <?php foreach ($active_checkouts as $co):
      $overdue = $co['expected_return'] && $co['expected_return'] < date('Y-m-d');
  ?>
  <tr>
    <td><?= esc($co['tool_name']) ?></td>
    <td><?= esc($co['borrower_name']) ?></td>
    <td><?= date('M j, g:ia', $co['checked_out_at']) ?></td>
    <td class="<?= $overdue ? 'overdue' : '' ?>"><?= $co['expected_return'] ? esc($co['expected_return']) : '<span style="color:#555">—</span>' ?></td>
    <td>
      <?php if ($is_admin): ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
        <input type="hidden" name="act" value="admin_return">
        <input type="hidden" name="checkout_id" value="<?= $co['id'] ?>">
        <button type="submit" class="btn-sm">Mark Returned</button>
      </form>
      <?php else: ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
        <input type="hidden" name="act" value="checkin">
        <input type="hidden" name="checkout_id" value="<?= $co['id'] ?>">
        <button type="submit" class="btn-sm" onclick="return confirm('Return this item?')">Return</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php endif; ?>

</body>
</html>
