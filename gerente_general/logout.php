<?php
session_start();
unset($_SESSION['gerente_general']);
header('Location: ../login.php'); exit;
