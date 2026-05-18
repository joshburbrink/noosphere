<?php
/*
 * shared/head.php — theme bootstrap and picker widget
 * Include this BEFORE any CSS link/style in <head>.
 * Requires shared/settings.php to already be loaded.
 */
$_ns_theme_default        = get_setting('theme_default', 'dark');
$_ns_theme_allow_override = get_setting('theme_allow_user_override', '1') === '1';
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
