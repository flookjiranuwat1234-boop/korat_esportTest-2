<?php
// auth/logout.php
require_once '../includes/auth.php';

logoutUser();
header('Location: ../pages/index.php');
exit;
