<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) audit('logout');
$_SESSION = [];
session_destroy();
redirect('login.php');
