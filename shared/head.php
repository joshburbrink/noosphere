<?php
/*
 * shared/head.php  -  theme bootstrap and picker widget
 * Include this BEFORE any CSS link/style in <head>.
 * Requires shared/settings.php to already be loaded.
 */
$_ns_theme_default        = get_setting('theme_default', 'dark');
$_ns_theme_allow_override = get_setting('theme_allow_user_override', '1') === '1';
$_ns_identity_strip       = get_setting('identity_strip', '1') === '1' && !is_readonly();
?>
<script>
(function(){
  var def   = "<?= htmlspecialchars($_ns_theme_default, ENT_QUOTES) ?>";
  var allow = <?= $_ns_theme_allow_override ? 'true' : 'false' ?>;
  var t     = (allow && localStorage.getItem("ns_theme")) || def;
  var valid = ["dark","darker","light","high-contrast","forest","amber"];
  if (valid.indexOf(t) === -1) t = def;
  document.documentElement.setAttribute("data-theme", t);
})();
</script>
<link rel="stylesheet" href="/static/theme.css">
<link rel="stylesheet" href="/static/mobile.css">
<script>
document.addEventListener("DOMContentLoaded", function(){
  var allow = <?= $_ns_theme_allow_override ? 'true' : 'false' ?>;
  if (!allow) return;

  var themes = [
    {t:"dark",          label:"Dark"},
    {t:"darker",        label:"Darker / OLED"},
    {t:"light",         label:"Light"},
    {t:"high-contrast", label:"High Contrast"},
    {t:"forest",        label:"Forest"},
    {t:"amber",         label:"Amber / Night Vision"},
  ];
  var bar = document.createElement("div");
  bar.id = "ns-theme-bar";
  bar.title = "Theme";
  themes.forEach(function(th){
    var sw = document.createElement("span");
    sw.className = "ns-swatch";
    sw.dataset.t = th.t;
    sw.title = th.label;
    if (document.documentElement.getAttribute("data-theme") === th.t) sw.classList.add("active");
    sw.addEventListener("click", function(){
      document.documentElement.setAttribute("data-theme", th.t);
      localStorage.setItem("ns_theme", th.t);
      document.querySelectorAll(".ns-swatch").forEach(function(s){ s.classList.remove("active"); });
      sw.classList.add("active");
    });
    bar.appendChild(sw);
  });
  document.body.appendChild(bar);
});
</script>
<?php if ($_ns_identity_strip): ?>
<style>
/* Floats top-right on every page. Pages with their own top-right content
 * (admin, etc) opt-in to clearance with body padding-top + header padding. */
#ns-identity-strip {
  position: fixed; top: 0; right: 0; z-index: 9998;
  font: 12px system-ui, sans-serif;
  background: rgba(15,15,26,.9); color: #aaa;
  padding: 4px 12px; border-bottom-left-radius: 6px;
  border-left: 1px solid #2a2a4a; border-bottom: 1px solid #2a2a4a;
  max-width: 90vw; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  display: flex; align-items: center; gap: 8px;
}
#ns-identity-strip .ns-who   { color: #4fc3f7; font-weight: 600; }
#ns-identity-strip .ns-roles { color: #f39c12; }
#ns-identity-strip a         { color: #4fc3f7; text-decoration: none; }
#ns-identity-strip a:hover   { text-decoration: underline; }
@media (max-width: 500px) {
  #ns-identity-strip { font-size: 11px; padding: 3px 8px; }
}
</style>
<script>
(function(){
  if (location.pathname.indexOf('/registry/login') === 0) return;
  fetch('/registry/whoami.php', {credentials: 'same-origin'}).then(function(r){return r.json();}).then(function(d){
    var bar = document.createElement('div');
    bar.id = 'ns-identity-strip';
    var next = encodeURIComponent(location.pathname + location.search);
    var esc = function(s){ return String(s).replace(/[<>&"]/g, function(c){ return ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'})[c]; }); };
    if (d.signed_in) {
      var rolesStr = (d.roles && d.roles.length) ? ' · <span class="ns-roles">' + esc(d.roles.join(', ')) + '</span>' : '';
      var admin = d.is_admin ? ' <span style="color:#e94560">[admin]</span>' : '';
      bar.innerHTML = '👤 <span class="ns-who">' + esc(d.name) + '</span>' + admin + rolesStr +
                      ' · ' + esc(d.ip) + ' <a href="/registry/logout.php">Sign out</a>';
    } else {
      bar.innerHTML = '👤 Anonymous · ' + esc(d.ip) + ' <a href="/registry/login.php?next=' + next + '">Sign in</a>';
    }
    document.body.appendChild(bar);
  }).catch(function(){});
})();
</script>
<?php endif; ?>
