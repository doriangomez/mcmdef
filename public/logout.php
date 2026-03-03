<?php
require_once __DIR__ . '/../app/config/auth.php';
require_once __DIR__ . '/../app/config/app.php';
session_destroy();
redirect_to('login.php');
