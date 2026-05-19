<?php
require_once __DIR__ . '/../shared/security.php';
require_once __DIR__ . '/../shared/settings.php';
require_once __DIR__ . '/../shared/identity.php';

sec_session_start();

$msg = '';
$next = $_GET['next'] ?? $_POST['next'] ?? '/';
// Only allow same-origin redirects
if (!preg_match('#^/[^/\\\\]#', $next)) $next = '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $pin  = trim($_POST['pin']  ?? '');

    $row = verify_pin($name, $pin);  // already rate-limited, hits registry.db
    if ($row) {
        sign_in_registry_user($row);
        header('Location: ' . $next);
        exit;
    }
    $msg = 'Name or PIN not recognized.';
}
?>
<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign In · Noosphere</title>
<?php require_once __DIR__ . '/../shared/head.php'; ?>
<style>
body { font-family: system-ui, sans-serif; background:#0f0f1a; color:#e0e0e0;
       display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
.card { background:#1a1a2e; padding:2rem; border-radius:8px; width:90%; max-width:380px;
        box-shadow:0 4px 20px rgba(0,0,0,.4); }
h1 { margin:0 0 1rem; font-size:1.4rem; color:#4fc3f7; }
label { display:block; margin:.75rem 0 .25rem; font-size:.9rem; color:#aaa; }
input { width:100%; padding:.6rem; border:1px solid #333; background:#0f0f1a; color:#fff;
        border-radius:4px; font-size:1rem; box-sizing:border-box; }
button { margin-top:1rem; width:100%; padding:.7rem; background:#4fc3f7; color:#0f0f1a;
         border:none; border-radius:4px; font-weight:bold; font-size:1rem; cursor:pointer; }
.err { color:#e94560; margin-top:.5rem; font-size:.9rem; }
.help { color:#888; font-size:.85rem; margin-top:1rem; text-align:center; }
a { color:#4fc3f7; }
</style>
</head><body>
<div class="card">
  <h1>Sign In</h1>
  <p style="color:#888;font-size:.9rem;margin:0 0 1rem">
    Use the name and PIN from your registry check-in.
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
    <label>Name</label>
    <input name="name" autofocus required autocomplete="name">
    <label>PIN</label>
    <input name="pin" type="password" inputmode="numeric" required autocomplete="current-password">
    <button type="submit">Sign In</button>
    <?php if ($msg): ?><div class="err"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  </form>
  <div class="help">
    Not registered? <a href="/registry/">Check in at the registry</a>.
    <br><a href="/">← Back to home</a>
  </div>
</div>
</body></html>
