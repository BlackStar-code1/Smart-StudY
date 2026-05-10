<?php
session_start();
require_once 'config/auth.php';

logoutUser();
header('Location: index.php');
exit();
?>
