<?php
require_once __DIR__ . '/../app/config/auth.php';
session_destroy();
header('Location: /login.php');
