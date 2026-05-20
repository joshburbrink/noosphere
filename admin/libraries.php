<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/audit.php';
require_once '/var/www/noosphere/shared/settings.php';
require_once '/var/www/noosphere/shared/identity.php';
require_once '/var/www/noosphere/shared/capabilities.php';
require_once '/var/www/noosphere/shared/libraries.php';
sec_session_start();

if (!can('libraries.manage')) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:2rem;color:#e94560;background:#0f0f1a;min-height:100vh;margin:0">Admin login required.</p>');
}

$msg = '';
$err = '';

function _decode_features_post(array $post): array {
    $f = [];
    foreach (array_keys(lib_default_features()) as $k) {
        $f[$k] = !empty($post['feat_' . $k]);
    }
    return $f;
}

function _decode_schema_post(array $post): array {
    $keys = $post['field_key']     ?? [];
    $labs = $post['field_label']   ?? [];
    $typs = $post['field_type']    ?? [];
    $reqs = $post['field_required']?? [];
    $opts = $post['field_options'] ?? [];
    $schema = [];
    for ($i = 0; $i < count($keys); $i++) {
        $k = trim((string)($keys[$i] ?? ''));
        if ($k === '') continue;
        $schema[] = [
            'key'      => $k,
            'label'    => (string)($labs[$i] ?? ''),
            'type'     => (string)($typs[$i] ?? 'text'),
            'required' => !empty($reqs[$i]),
            'options'  => (string)($opts[$i] ?? ''),
        ];
    }
    return $schema;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'create') {
            $id = create_library([
                'slug'         => $_POST['slug'] ?? '',
                'name'         => $_POST['name'] ?? '',
                'icon'         => $_POST['icon'] ?? '📚',
                'description'  => $_POST['description'] ?? '',
                'field_schema' => _decode_schema_post($_POST),
                'features'     => _decode_features_post($_POST),
                'visibility'   => $_POST['visibility'] ?? 'public',
                'sort_order'   => (int)($_POST['sort_order'] ?? 100),
                'disabled'     => empty($_POST['enabled']),
            ]);
            log_audit('library_create', "id=$id slug=" . ($_POST['slug'] ?? ''), 'info');
            header('Location: /admin/libraries.php?msg=created'); exit;
        }
        if ($act === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            update_library($id, [
                'name'         => $_POST['name'] ?? '',
                'icon'         => $_POST['icon'] ?? '📚',
                'description'  => $_POST['description'] ?? '',
                'field_schema' => _decode_schema_post($_POST),
                'features'     => _decode_features_post($_POST),
                'visibility'   => $_POST['visibility'] ?? 'public',
                'sort_order'   => (int)($_POST['sort_order'] ?? 100),
                'enabled'      => !empty($_POST['enabled']),
            ]);
            log_audit('library_update', "id=$id", 'info');
            header('Location: /admin/libraries.php?msg=saved&edit=' . $id); exit;
        }
        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            delete_library($id);
            log_audit('library_delete', "id=$id", 'warn');
            header('Location: /admin/libraries.php?msg=deleted'); exit;
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$edit_id = (int)($_GET['edit'] ?? 0);
$editing = $edit_id ? get_library($edit_id) : null;
$new     = !empty($_GET['new']);
$libs    = list_libraries(true);
if (isset($_GET['msg'])) {
    $msg = ['created'=>'Library created.', 'saved'=>'Library saved.', 'deleted'=>'Library deleted.'][$_GET['msg']] ?? '';
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Libraries  -  Noosphere</title>
<link rel="stylesheet" href="/static/mobile.css">
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui, sans-serif; background:#0f0f1a; color:#e0e0e0; }
header { background:#1a1a2e; border-bottom:2px solid #e94560; padding:12px 20px; display:flex; gap:12px; align-items:center; }
header h1 { font-size:16px; color:#e94560; flex:1; }
header a { color:#888; text-decoration:none; font-size:13px; }
header a:hover { color:#e94560; }
.container { max-width:960px; margin:0 auto; padding:20px 20px 60px; }
.card { background:#16213e; border:1px solid #2a2a4a; border-radius:10px; padding:16px 20px; margin-bottom:18px; }
.card h2 { font-size:13px; color:#e94560; text-transform:uppercase; letter-spacing:.04em; margin-bottom:12px; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:left; color:#888; padding:7px 8px; border-bottom:1px solid #2a2a4a; font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
td { padding:8px; border-bottom:1px solid #1a1a2e; vertical-align:top; }
.btn { background:#0f0f1a; border:1px solid #2a2a4a; color:#aaa; border-radius:5px; padding:5px 10px; font-size:12px; cursor:pointer; text-decoration:none; display:inline-block; }
.btn:hover { border-color:#e94560; color:#e94560; }
.btn-red { background:#e94560; color:#fff; border-color:#e94560; }
.btn-red:hover { background:#c73652; }
label { display:block; font-size:11px; color:#888; margin:14px 0 4px; text-transform:uppercase; letter-spacing:.04em; }
input[type=text], input[type=number], textarea, select { width:100%; background:#0f0f1a; border:1px solid #2a2a4a; color:#e0e0e0; border-radius:5px; padding:8px 10px; font-size:13px; font-family:inherit; }
textarea { resize:vertical; min-height:60px; }
.row { display:grid; grid-template-columns:80px 1fr 1fr 130px 70px 1fr 32px; gap:8px; align-items:end; margin-bottom:6px; }
.row > div { display:flex; flex-direction:column; }
.row label { margin:0 0 2px; font-size:10px; }
.row input, .row select { padding:6px 8px; font-size:12px; }
.feat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-top:8px; }
.feat-grid label { display:flex; align-items:center; gap:8px; margin:0; font-size:13px; color:#ccc; text-transform:none; letter-spacing:0; }
.flash { background:#0d2d0d; border:1px solid #2ecc71; color:#2ecc71; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:13px; }
.err { background:#2d0d0d; border:1px solid #e94560; color:#e94560; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:13px; }
.tag { display:inline-block; background:#0f0f1a; border:1px solid #2a2a4a; border-radius:3px; padding:1px 7px; font-size:10px; color:#888; }
</style>
</head>
<body>
<header>
  <a href="/admin/">← Admin</a>
  <h1>Custom Libraries</h1>
  <a class="btn" href="/admin/libraries.php?new=1">+ New library</a>
</header>

<div class="container">
<?php if ($msg): ?><div class="flash"><?= esc($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="err"><?= esc($err) ?></div><?php endif; ?>

<?php if ($new || $editing): ?>
<?php
$f = $editing ? lib_features($editing) : lib_default_features();
$schema = $editing ? lib_schema($editing) : [];
// Ensure at least 3 blank rows for new
while (count($schema) < ($new ? 3 : 0)) $schema[] = ['key'=>'','label'=>'','type'=>'text','required'=>false];
?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="<?= $editing ? 'update' : 'create' ?>">
  <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

  <h2><?= $editing ? 'Edit Library' : 'New Library' ?></h2>

  <div style="display:grid;grid-template-columns:80px 1fr 100px;gap:12px">
    <div>
      <label>Icon</label>
      <input type="text" name="icon" value="<?= esc($editing['icon'] ?? '📚') ?>" maxlength="4">
    </div>
    <div>
      <label>Name *</label>
      <input type="text" name="name" required value="<?= esc($editing['name'] ?? '') ?>" placeholder="Book Library">
    </div>
    <div>
      <label>Sort</label>
      <input type="number" name="sort_order" value="<?= esc($editing['sort_order'] ?? 100) ?>">
    </div>
  </div>

  <?php if (!$editing): ?>
    <label>Slug (lowercase, hyphens, used in URL: /resources/lib/?slug=&lt;slug&gt;)</label>
    <input type="text" name="slug" required pattern="[a-z0-9][a-z0-9-]*" maxlength="48" placeholder="books">
  <?php else: ?>
    <label>Slug (immutable)</label>
    <code style="background:#0f0f1a;border:1px solid #2a2a4a;padding:5px 10px;border-radius:5px;display:inline-block"><?= esc($editing['slug']) ?></code>
  <?php endif; ?>

  <label>Description</label>
  <textarea name="description" placeholder="What is this library for?"><?= esc($editing['description'] ?? '') ?></textarea>

  <label>Visibility</label>
  <select name="visibility">
    <?php foreach (['public'=>'Public (anyone can view)','members'=>'Members (signed-in users)','operators'=>'Operators only'] as $k=>$v): ?>
      <option value="<?= $k ?>" <?= ($editing['visibility'] ?? 'public') === $k ? 'selected' : '' ?>><?= esc($v) ?></option>
    <?php endforeach; ?>
  </select>

  <label>Features</label>
  <div class="feat-grid">
    <label><input type="checkbox" name="feat_lending"           <?= !empty($f['lending']) ? 'checked' : '' ?>> Lending (check out / return)</label>
    <label><input type="checkbox" name="feat_quantities"        <?= !empty($f['quantities']) ? 'checked' : '' ?>> Quantities (multiple copies)</label>
    <label><input type="checkbox" name="feat_photos"            <?= !empty($f['photos']) ? 'checked' : '' ?>> Photo upload per item</label>
    <label><input type="checkbox" name="feat_public_submission" <?= !empty($f['public_submission']) ? 'checked' : '' ?>> Signed-in members can add items</label>
    <label><input type="checkbox" name="feat_search"            <?= !empty($f['search']) ? 'checked' : '' ?>> Text search</label>
  </div>

  <label>Enabled</label>
  <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;color:#ccc;font-size:13px">
    <input type="checkbox" name="enabled" <?= (!$editing || $editing['enabled']) ? 'checked' : '' ?>> Show on /resources/ index
  </label>

  <label style="margin-top:24px">Field schema</label>
  <p style="font-size:11px;color:#888;margin-bottom:8px">These become the per-item fields. Key is the storage name (snake_case). Options applies only to <code>select</code>: comma-separated.</p>
  <div id="schema-rows">
  <?php foreach ($schema as $row): ?>
    <div class="row">
      <div><label>Type</label><select name="field_type[]"><?php foreach (lib_field_types() as $t): ?><option value="<?= $t ?>" <?= ($row['type'] ?? '') === $t ? 'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
      <div><label>Key</label><input type="text" name="field_key[]" value="<?= esc($row['key'] ?? '') ?>" placeholder="author"></div>
      <div><label>Label</label><input type="text" name="field_label[]" value="<?= esc($row['label'] ?? '') ?>" placeholder="Author"></div>
      <div><label>Options</label><input type="text" name="field_options[]" value="<?= esc(is_array($row['options'] ?? null) ? implode(', ', $row['options']) : ($row['options'] ?? '')) ?>" placeholder="for select"></div>
      <div><label>Req</label><label style="display:flex;align-items:center;height:28px"><input type="checkbox" name="field_required[]" value="1" <?= !empty($row['required']) ? 'checked':'' ?>></label></div>
      <div></div>
      <div><label>&nbsp;</label><button type="button" class="btn" onclick="this.closest('.row').remove()">✕</button></div>
    </div>
  <?php endforeach; ?>
  </div>
  <button type="button" class="btn" onclick="addFieldRow()" style="margin-top:6px">+ Add field</button>

  <div style="margin-top:24px;display:flex;gap:8px">
    <button type="submit" class="btn btn-red"><?= $editing ? 'Save' : 'Create library' ?></button>
    <a class="btn" href="/admin/libraries.php">Cancel</a>
  </div>
</form>

<?php if ($editing): ?>
<form method="post" class="card" onsubmit="return confirm('Delete library &quot;<?= esc(addslashes($editing['name'])) ?>&quot; and all its items? This cannot be undone.')">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="delete">
  <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
  <h2 style="color:#e94560">Danger zone</h2>
  <button type="submit" class="btn" style="border-color:#e94560;color:#e94560">Delete library + all items</button>
</form>
<?php endif; ?>

<script>
function addFieldRow() {
  var t = document.querySelector('#schema-rows .row');
  if (!t) {
    document.getElementById('schema-rows').innerHTML = '<div class="row"></div>';
    t = document.querySelector('#schema-rows .row');
  }
  var clone = t.cloneNode(true);
  clone.querySelectorAll('input').forEach(function(i){ i.value=''; i.checked=false; });
  clone.querySelector('select').selectedIndex = 0;
  document.getElementById('schema-rows').appendChild(clone);
}
</script>

<?php else: ?>

<div class="card">
  <h2>All libraries (<?= count($libs) ?>)</h2>
  <?php if (!$libs): ?>
    <p style="color:#888;font-size:13px">No custom libraries yet. <a href="/admin/libraries.php?new=1" style="color:#e94560">Create one</a>.</p>
  <?php else: ?>
    <table>
      <thead><tr>
        <th></th><th>Name</th><th>Slug</th><th>Items</th><th>Visibility</th><th>Features</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($libs as $L): $count = (int)lib_db()->query("SELECT COUNT(*) FROM library_items WHERE library_id=" . (int)$L['id'])->fetchColumn(); $feats = lib_features($L); ?>
        <tr>
          <td style="font-size:22px"><?= esc($L['icon']) ?></td>
          <td><strong><?= esc($L['name']) ?></strong><?php if ($L['description']): ?><br><span style="font-size:11px;color:#888"><?= esc(substr($L['description'],0,80)) ?></span><?php endif; ?></td>
          <td><code style="font-size:11px;color:#888"><?= esc($L['slug']) ?></code></td>
          <td><?= $count ?></td>
          <td style="font-size:11px;color:#888"><?= esc($L['visibility']) ?></td>
          <td>
            <?php foreach (['lending','quantities','photos','public_submission'] as $fk): if (!empty($feats[$fk])): ?><span class="tag"><?= esc($fk) ?></span> <?php endif; endforeach; ?>
          </td>
          <td><?= $L['enabled'] ? '<span style="color:#2ecc71;font-size:11px">ON</span>' : '<span style="color:#888;font-size:11px">OFF</span>' ?></td>
          <td>
            <a class="btn" href="/admin/libraries.php?edit=<?= (int)$L['id'] ?>">Edit</a>
            <a class="btn" href="/resources/lib/?slug=<?= esc($L['slug']) ?>" target="_blank">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php endif; ?>
</div>
<script>
// #83: Scroll + open-details preservation
(function(){
  function captureState() {
    var openKeys = [];
    document.querySelectorAll('details[open]').forEach(function(d){
      var s = d.querySelector('summary');
      if (s) openKeys.push(s.textContent.replace(/[▾▸]/g,'').trim().split('\n')[0].trim());
    });
    try {
      sessionStorage.setItem('ns_scroll_y',    String(Math.round(window.scrollY)));
      sessionStorage.setItem('ns_open_details', JSON.stringify(openKeys));
    } catch(e){}
  }
  document.addEventListener('submit', captureState, true);
  function restoreState() {
    var scrollY, openKeys;
    try {
      scrollY  = sessionStorage.getItem('ns_scroll_y');
      openKeys = JSON.parse(sessionStorage.getItem('ns_open_details') || 'null');
      sessionStorage.removeItem('ns_scroll_y');
      sessionStorage.removeItem('ns_open_details');
    } catch(e){ return; }
    if (openKeys === null) return;
    document.querySelectorAll('details').forEach(function(d){
      var s = d.querySelector('summary');
      if (!s) return;
      var text = s.textContent.replace(/[▾▸]/g,'').trim().split('\n')[0].trim();
      if (openKeys.indexOf(text) !== -1) d.setAttribute('open','');
      else d.removeAttribute('open');
    });
    if (scrollY) window.scrollTo(0, parseInt(scrollY, 10));
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ setTimeout(restoreState, 80); });
  } else {
    setTimeout(restoreState, 80);
  }
})();
</script>
</body>
</html>
