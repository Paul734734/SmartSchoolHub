<?php
/**
 * SmartSchool Hub — Point d'entrée
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (Auth::isLoggedIn()) {
    header('Location: ' . Auth::getDashboardUrl(Auth::role()));
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
