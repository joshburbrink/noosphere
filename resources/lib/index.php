<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/audit.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
require_once '/var/www/noosphere/shared/capabilities.php';
require_once '/var/www/noosphere/shared/libraries.php';
sec_session_start();

$slug = trim($_GET['slug'] ?? '');
if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) { http_response_code(404); exit('Unknown library'); }
$lib = get_library_by_slug($slug);
if (!$lib || !$lib['enabled']) { http_response_code(404); exit('Unknown library'); }
if (!lib_can($lib, 'view')) { http_response_code(403); exit('Permission denied.'); }

$is_readonly = is_readonly();
$features    = lib_features($lib);
$schema      = lib_schema($lib);
$can_edit    = !$is_readonly && (lib_can($lib, 'edit') || !empty($_SESSION['admin']) || in_array('operator', current_roles(), true));
$can_add     = !$is_readonly && ($can_edit || lib_can($lib, 'add'));
$can_lend    = !$is_readonly && $features['lending'] && (lib_can($lib, 'lend') || $can_edit);

$photo_dir   = '/var/lib/noosphere/library_photos/' . $lib['slug'];
$photo_url   = '/library-photos/' . rawurlencode($lib['slug']);

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function _collect_item_data(array $schema, array $post): array {
    $data = [];
    foreach ($schema as $f) {
        $key = $f['key'];
        $type = $f['type'];
        $raw = $post['f_' . $key] ?? '';
        switch ($type) {
            case 'checkbox': $data[$key] = !empty($raw); break;
            case 'number':   $data[$key] = $raw === '' ? '' : (is_numeric($raw) ? (float)$raw : trim((string)$raw)); break;
            case 'tags':     $data[$key] = array_values(array_filter(array_map('trim', preg_split('/[,\n]/', (string)$raw)))); break;
            default:         $data[$key] = is_array($raw) ? '' : trim((string)$raw);
        }
    }
    return $data;
}

function _save_photo(string $dir, ?array $upload, ?string $existing): string {
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $existing ?? '';
    if ($upload['error'] !== UPLOAD_ERR_OK) return $existing ?? '';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $info = @getimagesize($upload['tmp_name']);
    if (!$info) return $existing ?? '';
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$info['mime']] ?? null;
    if (!$ext) return $existing ?? '';
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $name)) return $existing ?? '';
    if ($existing && file_exists($dir . '/' . $existing)) @unlink($dir . '/' . $existing);
    return $name;
}

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    try {
        if ($act === 'add_item') {
            if (!$can_add) throw new RuntimeException('Permission denied');
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('Name required');
            $data = _collect_item_data($schema, $_POST);
            $qty  = $features['quantities'] ? max(1, (int)($_POST['qty'] ?? 1)) : 1;
            $photo = $features['photos'] ? _save_photo($photo_dir, $_FILES['photo'] ?? null, null) : '';
            $id = add_item((int)$lib['id'], $name, $data, $qty, $photo);
            log_audit('lib_item_add', "lib={$lib['slug']} item=$id", 'info');
            header('Location: ?slug=' . urlencode($slug)); exit;
        }
        if ($act === 'update_item') {
            if (!$can_edit) throw new RuntimeException('Permission denied');
            $id   = (int)($_POST['id'] ?? 0);
            $item = get_item($id);
            if (!$item || $item['library_id'] != $lib['id']) throw new RuntimeException('Item not found');
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('Name required');
            $data = _collect_item_data($schema, $_POST);
            $qty  = $features['quantities'] ? max(1, (int)($_POST['qty'] ?? 1)) : 1;
            $new_photo = null;
            if ($features['photos']) $new_photo = _save_photo($photo_dir, $_FILES['photo'] ?? null, $item['photo']);
            update_item($id, $name, $data, $qty, $new_photo);
            header('Location: ?slug=' . urlencode($slug) . '&edit=' . $id); exit;
        }
        if ($act === 'delete_item') {
            if (!$can_edit) throw new RuntimeException('Permission denied');
            $id = (int)($_POST['id'] ?? 0);
            $item = get_item($id);
            if ($item && $item['library_id'] == $lib['id']) {
                if ($features['photos'] && $item['photo'] && file_exists($photo_dir . '/' . $item['photo'])) {
                    @unlink($photo_dir . '/' . $item['photo']);
                }
                delete_item($id);
                log_audit('lib_item_delete', "lib={$lib['slug']} item=$id", 'warn');
            }
            header('Location: ?slug=' . urlencode($slug)); exit;
        }
        if ($act === 'checkout') {
            if (!$can_lend) throw new RuntimeException('Permission denied');
            $item_id  = (int)($_POST['item_id'] ?? 0);
            $borrower = trim($_POST['borrower'] ?? '');
            $note     = trim($_POST['note'] ?? '');
            $due      = trim($_POST['due'] ?? '');
            $due_ts   = $due ? strtotime($due) : null;
            if ($borrower === '') throw new RuntimeException('Borrower name required');
            checkout_item((int)$lib['id'], $item_id, $borrower, $note, $due_ts ?: null);
            log_audit('lib_checkout', "lib={$lib['slug']} item=$item_id", 'info');
            header('Location: ?slug=' . urlencode($slug)); exit;
        }
        if ($act === 'return') {
            if (!$can_lend) throw new RuntimeException('Permission denied');
            return_checkout((int)($_POST['checkout_id'] ?? 0));
            log_audit('lib_return', "lib={$lib['slug']} co=" . (int)$_POST['checkout_id'], 'info');
            header('Location: ?slug=' . urlencode($slug)); exit;
        }
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

$q       = trim($_GET['q'] ?? '');
$edit_id = (int)($_GET['edit'] ?? 0);
$add     = !empty($_GET['add']);
$items   = get_items((int)$lib['id'], $features['search'] ? $q : '');
$editing = $edit_id ? get_item($edit_id) : null;
if ($editing && $editing['library_id'] != $lib['id']) $editing = null;
$active_checkouts = $features['lending'] ? library_active_checkouts((int)$lib['id']) : [];

function _render_field_input(array $f, $value, $prefix = 'f_') {
    $name = $prefix . $f['key'];
    $req  = !empty($f['required']) ? 'required' : '';
    switch ($f['type']) {
        case 'textarea':
            return '<textarea name="' . esc($name) . '" ' . $req . '>' . esc($value) . '</textarea>';
        case 'number':
            return '<input type="number" step="any" name="' . esc($name) . '" value="' . esc($value) . '" ' . $req . '>';
        case 'date':
            return '<input type="date" name="' . esc($name) . '" value="' . esc($value) . '" ' . $req . '>';
        case 'checkbox':
            $checked = !empty($value) ? 'checked' : '';
            return '<input type="checkbox" name="' . esc($name) . '" value="1" ' . $checked . '>';
        case 'select':
            $opts = $f['options'] ?? [];
            $h = '<select name="' . esc($name) . '" ' . $req . '><option value="">-</option>';
            foreach ($opts as $o) {
                $sel = ((string)$value === (string)$o) ? 'selected' : '';
                $h .= '<option ' . $sel . '>' . esc($o) . '</option>';
            }
            return $h . '</select>';
        case 'tags':
            $v = is_array($value) ? implode(', ', $value) : (string)$value;
            return '<input type="text" name="' . esc($name) . '" value="' . esc($v) . '" placeholder="comma-separated" ' . $req . '>';
        default:
            return '<input type="text" name="' . esc($name) . '" value="' . esc($value) . '" ' . $req . '>';
    }
}

function _render_field_value($f, $val) {
    if ($val === '' || $val === null) return '<span style="color:#555">-</span>';
    switch ($f['type']) {
        case 'checkbox': return $val ? '✓' : '';
        case 'tags':     return implode(', ', array_map('htmlspecialchars', is_array($val) ? $val : [$val]));
        case 'textarea': return nl2br(esc($val));
        case 'date':     return esc($val);
        default:         return esc($val);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($lib['name']) ?>  -  Noosphere</title>
<link rel="stylesheet" href="/static/mobile.css">
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui, sans-serif; background:#0f0f1a; color:#e0e0e0; min-height:100vh; }
header { background:#1a1a2e; border-bottom:2px solid #e94560; padding:12px 20px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
header a { color:#888; text-decoration:none; font-size:13px; }
header a:hover { color:#e94560; }
header h1 { font-size:18px; color:#e94560; flex:1; }
.container { max-width:980px; margin:0 auto; padding:20px; }
.desc { color:#888; font-size:13px; margin-bottom:14px; }
.toolbar { display:flex; gap:8px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
.toolbar input[type=text] { flex:1; min-width:200px; background:#16213e; border:1px solid #2a2a4a; color:#e0e0e0; border-radius:5px; padding:8px 10px; font-size:13px; }
.btn { background:#16213e; border:1px solid #2a2a4a; color:#aaa; border-radius:5px; padding:7px 12px; font-size:12px; cursor:pointer; text-decoration:none; display:inline-block; }
.btn:hover { border-color:#e94560; color:#e94560; }
.btn-red { background:#e94560; color:#fff; border-color:#e94560; }
.btn-red:hover { background:#c73652; }
.card { background:#16213e; border:1px solid #2a2a4a; border-radius:8px; padding:14px 18px; margin-bottom:14px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.item { background:#16213e; border:1px solid #2a2a4a; border-radius:8px; padding:14px; }
.item h3 { color:#e94560; font-size:15px; margin-bottom:6px; }
.item .photo { width:100%; aspect-ratio:4/3; background:#0f0f1a; border-radius:5px; margin-bottom:10px; background-size:cover; background-position:center; }
.item .field { font-size:12px; color:#bbb; margin:3px 0; }
.item .field b { color:#888; font-weight:normal; }
.item .actions { display:flex; gap:6px; margin-top:10px; flex-wrap:wrap; }
.tag { font-size:10px; background:#0f0f1a; border:1px solid #2a2a4a; padding:1px 6px; border-radius:3px; color:#888; }
.tag.warn { color:#ffb84d; border-color:#5a4a28; }
.tag.ok { color:#2ecc71; border-color:#1f4e3a; }
label { display:block; font-size:11px; color:#888; margin:12px 0 4px; text-transform:uppercase; letter-spacing:.04em; }
input[type=text], input[type=number], input[type=date], textarea, select { width:100%; background:#0f0f1a; border:1px solid #2a2a4a; color:#e0e0e0; border-radius:5px; padding:8px 10px; font-size:13px; font-family:inherit; }
input:focus, textarea:focus, select:focus { outline:none; border-color:#e94560; }
.flash { background:#0d2d0d; border:1px solid #2ecc71; color:#2ecc71; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:13px; }
.err { background:#2d0d0d; border:1px solid #e94560; color:#e94560; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:13px; }
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:1000; align-items:center; justify-content:center; }
.modal-bg.open { display:flex; }
.modal { background:#16213e; border:1px solid #2a2a4a; border-radius:8px; max-width:420px; width:92%; padding:18px 22px; max-height:90vh; overflow:auto; }
</style>
</head>
<body>
<header>
  <a href="/resources/">← Resources</a>
  <h1><?= esc($lib['icon']) ?> <?= esc($lib['name']) ?></h1>
  <?php if ($can_add): ?>
    <a class="btn btn-red" href="?slug=<?= urlencode($slug) ?>&add=1">+ Add item</a>
  <?php endif; ?>
</header>

<div class="container">
<?php if ($lib['description']): ?><div class="desc"><?= esc($lib['description']) ?></div><?php endif; ?>
<?php if ($err): ?><div class="err"><?= esc($err) ?></div><?php endif; ?>

<?php if ($add || $editing): ?>
<?php
$initial = [];
if ($editing) $initial = json_decode($editing['data'], true) ?: [];
?>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="<?= $editing ? 'update_item' : 'add_item' ?>">
  <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

  <h2 style="font-size:14px;color:#e94560;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px"><?= $editing ? 'Edit item' : 'New item' ?></h2>

  <label>Name *</label>
  <input type="text" name="name" required value="<?= esc($editing['name'] ?? '') ?>">

  <?php foreach ($schema as $f): ?>
    <label><?= esc($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?></label>
    <?= _render_field_input($f, $initial[$f['key']] ?? '') ?>
  <?php endforeach; ?>

  <?php if ($features['quantities']): ?>
    <label>Quantity (copies)</label>
    <input type="number" name="qty" min="1" value="<?= esc($editing['qty'] ?? 1) ?>">
  <?php endif; ?>

  <?php if ($features['photos']): ?>
    <label>Photo<?php if ($editing && $editing['photo']): ?> (current: <code><?= esc($editing['photo']) ?></code>, upload to replace)<?php endif; ?></label>
    <input type="file" name="photo" accept="image/*">
  <?php endif; ?>

  <div style="margin-top:18px;display:flex;gap:8px">
    <button type="submit" class="btn btn-red"><?= $editing ? 'Save' : 'Add' ?></button>
    <a class="btn" href="?slug=<?= urlencode($slug) ?>">Cancel</a>
    <?php if ($editing && $can_edit): ?>
      <form method="post" style="margin-left:auto;display:inline" onsubmit="return confirm('Delete this item?')">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="delete_item">
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
        <button type="submit" class="btn" style="color:#e94560;border-color:#e94560">Delete</button>
      </form>
    <?php endif; ?>
  </div>
</form>

<?php else: ?>

<?php if ($features['search']): ?>
<form method="get" class="toolbar">
  <input type="hidden" name="slug" value="<?= esc($slug) ?>">
  <input type="text" name="q" value="<?= esc($q) ?>" placeholder="Search items...">
  <button type="submit" class="btn">Search</button>
  <?php if ($q): ?><a class="btn" href="?slug=<?= urlencode($slug) ?>">Clear</a><?php endif; ?>
</form>
<?php endif; ?>

<?php if ($features['lending'] && $active_checkouts): ?>
<div class="card">
  <h2 style="font-size:13px;color:#e94560;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">Currently checked out (<?= count($active_checkouts) ?>)</h2>
  <table style="width:100%;font-size:13px"><thead><tr>
    <th style="text-align:left;color:#888;padding:5px 6px">Item</th>
    <th style="text-align:left;color:#888;padding:5px 6px">Borrower</th>
    <th style="text-align:left;color:#888;padding:5px 6px">Out since</th>
    <th style="text-align:left;color:#888;padding:5px 6px">Due</th>
    <th></th>
  </tr></thead><tbody>
  <?php foreach ($active_checkouts as $c):
        $overdue = $c['expected_return'] && $c['expected_return'] < time(); ?>
    <tr style="<?= $overdue ? 'background:#3a1a1a' : '' ?>">
      <td style="padding:6px"><?= esc($c['item_name']) ?></td>
      <td style="padding:6px"><?= esc($c['borrower_name']) ?><?php if ($c['borrower_note']): ?><br><span style="font-size:11px;color:#888"><?= esc($c['borrower_note']) ?></span><?php endif; ?></td>
      <td style="padding:6px;font-size:11px;color:#aaa"><?= date('M j', $c['checked_out_at']) ?></td>
      <td style="padding:6px;font-size:11px;color:<?= $overdue ? '#e94560' : '#aaa' ?>"><?= $c['expected_return'] ? date('M j', $c['expected_return']) : '-' ?><?= $overdue ? ' overdue' : '' ?></td>
      <td style="padding:6px">
        <?php if ($can_lend): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="return">
          <input type="hidden" name="checkout_id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="btn">Return</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>

<?php if (!$items): ?>
  <div class="card" style="text-align:center;color:#888;padding:40px 20px">
    <?= $q ? 'No items match your search.' : 'No items yet.' ?>
    <?php if ($can_add): ?><br><br><a class="btn btn-red" href="?slug=<?= urlencode($slug) ?>&add=1">+ Add the first item</a><?php endif; ?>
  </div>
<?php else: ?>
<div class="grid">
<?php foreach ($items as $it): $d = json_decode($it['data'], true) ?: []; $available = (int)$it['qty'] - (int)$it['qty_out']; ?>
  <div class="item">
    <?php if ($features['photos'] && $it['photo']): ?>
      <div class="photo" style="background-image:url('<?= esc($photo_url . '/' . $it['photo']) ?>')"></div>
    <?php endif; ?>
    <h3><?= esc($it['name']) ?></h3>
    <?php foreach ($schema as $f): $v = $d[$f['key']] ?? ''; if ($v === '' || $v === null) continue; ?>
      <div class="field"><b><?= esc($f['label']) ?>:</b> <?= _render_field_value($f, $v) ?></div>
    <?php endforeach; ?>
    <?php if ($features['quantities']): ?>
      <div class="field"><b>Available:</b> <span class="tag <?= $available > 0 ? 'ok' : 'warn' ?>"><?= $available ?> / <?= $it['qty'] ?></span></div>
    <?php endif; ?>
    <div class="actions">
      <?php if ($features['lending'] && $can_lend && $available > 0): ?>
        <button type="button" class="btn btn-red" onclick="openCheckout(<?= (int)$it['id'] ?>, <?= json_encode($it['name']) ?>)">Check out</button>
      <?php endif; ?>
      <?php if ($can_edit): ?>
        <a class="btn" href="?slug=<?= urlencode($slug) ?>&edit=<?= (int)$it['id'] ?>">Edit</a>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>
</div>

<?php if ($features['lending'] && $can_lend): ?>
<div id="co-modal" class="modal-bg" onclick="if(event.target===this)closeCheckout()">
  <form method="post" class="modal">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="checkout">
    <input type="hidden" name="item_id" id="co-item-id">
    <h2 style="font-size:14px;color:#e94560;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">Check out</h2>
    <p style="font-size:13px;color:#aaa;margin-bottom:8px" id="co-item-name"></p>
    <label>Borrower name *</label>
    <input type="text" name="borrower" required>
    <label>Note (optional)</label>
    <input type="text" name="note" placeholder="Phone, condition, etc.">
    <label>Expected return (optional)</label>
    <input type="date" name="due">
    <div style="margin-top:14px;display:flex;gap:8px">
      <button type="submit" class="btn btn-red">Check out</button>
      <button type="button" class="btn" onclick="closeCheckout()">Cancel</button>
    </div>
  </form>
</div>
<script>
function openCheckout(id, name) {
  document.getElementById('co-item-id').value = id;
  document.getElementById('co-item-name').textContent = name;
  document.getElementById('co-modal').classList.add('open');
}
function closeCheckout() { document.getElementById('co-modal').classList.remove('open'); }
</script>
<?php endif; ?>
</body>
</html>
