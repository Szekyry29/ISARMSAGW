<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
startSecureSession();
session_unset();
session_destroy();
header('Location: ' . BASE_URL . 'index.php');
exit;
