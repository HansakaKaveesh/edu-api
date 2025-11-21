<?php
session_start();
session_destroy();
header("Location: managment_login.php");
?>