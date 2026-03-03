<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';

if (!is_logged_in()) {
    redirect_to('login.php');
}
