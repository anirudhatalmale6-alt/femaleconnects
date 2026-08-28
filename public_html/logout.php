<?php
require_once __DIR__ . '/includes/auth.php';

logout();
start_session();
flash('info', 'You are logged out. See you soon.');
redirect('index.php');
