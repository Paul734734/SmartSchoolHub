<?php
/**
 * SmartSchool Hub — Header HTML réutilisable
 * Usage : require_once BASE_PATH . '/includes/header.php';
 * Variables attendues : $pageTitle (string), $pageIcon (string emoji)
 */

// Sécurité : ce fichier ne s'ouvre pas directement
if (!defined('BASE_PATH')) {
    http_response_code(403); exit;
}

require_once BASE_PATH . '/includes/functions.php';

$school      = getSchool();
$currentYear = getCurrentYear();
$schoolName  = $school['name']       ?? APP_NAME;
$yearLabel   = $currentYear['label'] ?? '';
$pageTitle   = $pageTitle ?? 'Tableau de bord';
$pageIcon    = $pageIcon  ?? '📊';

$unreadNotif = Auth::isLoggedIn() ? getUnreadNotifCount(Auth::id()) : 0;
$unreadMsg   = Auth::isLoggedIn() ? getUnreadMsgCount(Auth::id())   : 0;

$flash = getFlash();
$lang = locale();
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($pageTitle) ?> — <?= e($schoolName) ?></title>

  <!-- Favicon dynamique -->
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">

  <!-- Styles -->
  <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css">

  <!-- Chart.js (lazy-loaded si besoin) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>

  <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body>

<div class="app-layout">

  <?php require_once BASE_PATH . '/includes/sidebar.php'; ?>

  <div class="main-content" id="main-content">

    <!-- ── Topbar ────────────────────────────────────────── -->
    <div class="topbar" id="topbar">

      <!-- Burger menu (mobile) -->
      <button class="burger-btn" onclick="toggleSidebar()" aria-label="Menu" style="
        display:none;width:40px;height:40px;border:none;background:none;
        cursor:pointer;font-size:22px;color:var(--navy);padding:0;flex-shrink:0;
      " id="burger-btn">☰</button>

      <!-- Titre de la page -->
      <div class="topbar-title" id="topbar-title">
        <?= $pageIcon ?> <?= e($pageTitle) ?>
      </div>

      <!-- Barre de recherche -->
      <div class="topbar-search">
        <span>🔍</span>
        <input
          type="text"
          id="global-search"
          placeholder="<?= e(tr('Rechercher un eleve, enseignant...', 'Search student, teacher...')) ?>"
          autocomplete="off"
          onkeyup="globalSearch(this.value)"
        >
      </div>
      <div id="search-results" style="
        position:absolute;top:64px;left:50%;transform:translateX(-50%);
        width:400px;background:var(--card);border:1px solid var(--border);
        border-radius:12px;box-shadow:var(--shadow2);z-index:200;
        display:none;max-height:320px;overflow-y:auto;
      "></div>

      <div class="topbar-icon" title="<?= e(tr('Langue', 'Language')) ?>" style="width:auto;padding:0 10px;">
        <a href="?lang=fr" style="text-decoration:none;color:<?= $lang === 'fr' ? 'var(--blue)' : 'var(--muted)' ?>;font-weight:700;">FR</a>
        <span style="margin:0 6px;color:var(--muted);">|</span>
        <a href="?lang=en" style="text-decoration:none;color:<?= $lang === 'en' ? 'var(--blue)' : 'var(--muted)' ?>;font-weight:700;">EN</a>
      </div>

      <!-- Notifications -->
      <div class="topbar-icon" onclick="togglePanel('notif-panel')" title="Notifications">
        🔔
        <?php if ($unreadNotif > 0): ?>
        <div class="notif-dot" style="width:16px;height:16px;font-size:9px;font-weight:700;
          display:flex;align-items:center;justify-content:center;color:#fff;">
          <?= $unreadNotif > 9 ? '9+' : $unreadNotif ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Messages -->
      <div class="topbar-icon" onclick="window.location='<?= BASE_URL ?>/modules/common/pages/messages.php'" title="Messages">
        💬
        <?php if ($unreadMsg > 0): ?>
        <div class="notif-dot"></div>
        <?php endif; ?>
      </div>

      <!-- Avatar utilisateur -->
      <div class="topbar-icon" onclick="togglePanel('user-panel')" style="
        background:var(--grad2);border:none;color:#fff;
        font-size:13px;font-weight:700;
      " title="Mon compte">
        <?= e(Auth::initials()) ?>
      </div>

    </div><!-- /topbar -->

    <!-- ── Panneau notifications ─────────────────────── -->
    <div id="notif-panel" style="
      position:fixed;top:64px;right:16px;width:360px;
      background:var(--card);border:1px solid var(--border);
      border-radius:16px;box-shadow:var(--shadow2);z-index:150;
      display:none;max-height:480px;overflow-y:auto;
    ">
      <div style="padding:16px 20px;border-bottom:1px solid var(--border);
        display:flex;align-items:center;justify-content:space-between;">
        <div style="font-family:'Sora',sans-serif;font-weight:700;font-size:15px;color:var(--navy);">
          🔔 Notifications
        </div>
        <button onclick="markAllRead()" class="btn btn-sm btn-secondary" style="font-size:11px;padding:4px 10px;">
          Tout lire
        </button>
      </div>
      <div id="notif-list" style="padding:8px 0;">
        <div style="padding:40px 20px;text-align:center;color:var(--muted);font-size:14px;">
          ⏳ Chargement…
        </div>
      </div>
      <div style="padding:12px 20px;border-top:1px solid var(--border);text-align:center;">
        <a href="<?= BASE_URL ?>/modules/common/pages/notifications.php"
           style="font-size:13px;font-weight:600;color:var(--blue);">
          Voir toutes les notifications →
        </a>
      </div>
    </div>

    <!-- ── Panneau utilisateur ───────────────────────── -->
    <div id="user-panel" style="
      position:fixed;top:64px;right:16px;width:280px;
      background:var(--card);border:1px solid var(--border);
      border-radius:16px;box-shadow:var(--shadow2);z-index:150;
      display:none;
    ">
      <div style="padding:20px;border-bottom:1px solid var(--border);display:flex;gap:14px;align-items:center;">
        <div class="sidebar-avatar avatar-lg" style="width:48px;height:48px;font-size:18px;border-radius:50%;">
          <?= e(Auth::initials()) ?>
        </div>
        <div>
          <div style="font-weight:700;font-size:14px;color:var(--navy);"><?= e(Auth::name()) ?></div>
          <div style="font-size:12px;color:var(--muted);"><?= e(Auth::email()) ?></div>
          <div style="margin-top:4px;">
            <span class="badge badge-blue" style="font-size:10px;">
              <?= ucfirst(e(Auth::role())) ?>
            </span>
          </div>
        </div>
      </div>
      <div style="padding:8px;">
        <a href="<?= BASE_URL ?>/modules/common/pages/profile.php" class="sidebar-item" style="margin:2px 0;border-radius:10px;text-decoration:none;display:flex;">
          <span class="sidebar-item-icon">👤</span> Mon profil
        </a>
        <a href="<?= BASE_URL ?>/modules/common/pages/settings.php" class="sidebar-item" style="margin:2px 0;border-radius:10px;text-decoration:none;display:flex;">
          <span class="sidebar-item-icon">⚙️</span> Paramètres
        </a>
      </div>
      <div style="padding:8px;border-top:1px solid var(--border);">
        <a href="<?= BASE_URL ?>/logout.php" style="
          display:flex;align-items:center;gap:12px;
          padding:11px 12px;border-radius:10px;cursor:pointer;
          color:var(--rose);font-size:14px;font-weight:600;
          text-decoration:none;transition:background .2s;
        " onmouseover="this.style.background='#FEE2E2'" onmouseout="this.style.background='none'">
          <span style="font-size:18px;width:22px;text-align:center;">🚪</span> Déconnexion
        </a>
      </div>
    </div>

    <!-- ── Overlay fond panneaux ────────────────────── -->
    <div id="panel-overlay" onclick="closePanels()" style="
      position:fixed;inset:0;z-index:140;display:none;
    "></div>

    <!-- ── Flash message ─────────────────────────────── -->
    <?php if ($flash): ?>
    <div style="padding:12px 32px 0;" id="flash-msg">
      <div class="flash flash-<?= e($flash['type']) ?>">
        <?= $flash['type'] === 'success' ? '✅' : ($flash['type'] === 'error' ? '⚠️' : 'ℹ️') ?>
        <?= e($flash['message']) ?>
        <button onclick="this.closest('.flash').remove()" style="
          margin-left:auto;background:none;border:none;cursor:pointer;
          font-size:16px;opacity:.6;
        ">✕</button>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Contenu de la page (ouverture) ────────────── -->
    <div class="page-body">
