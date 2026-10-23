<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/sso.php';
if (current_user()) redirect('index.php');
if (!sso_enabled()) { flash('Microsoft sign-in is not set up.', 'err'); redirect('login.php'); }
header('Location: ' . sso_start_url()); exit;
