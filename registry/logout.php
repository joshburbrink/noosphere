<?php
require_once __DIR__ . '/../shared/security.php';
require_once __DIR__ . '/../shared/identity.php';
sec_session_start();
sign_out_registry_user();
header('Location: /');
exit;
