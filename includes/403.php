<?php
/**
 * SmartSchool Hub — Page d'erreur 403 Accès refusé
 */
if (!defined('BASE_PATH')) { http_response_code(403); exit; }

$pageTitle = 'Accès refusé';
$schoolName = APP_NAME;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>403 — Accès refusé | <?= htmlspecialchars($schoolName) ?></title>
  <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg); }
    .error-box { text-align:center; padding:3rem; max-width:480px; }
    .error-code { font-size:6rem; font-weight:800; color:var(--rose); line-height:1; }
    .error-title { font-size:1.5rem; font-weight:700; margin:.5rem 0 1rem; color:var(--text); }
    .error-msg { color:var(--text-muted); margin-bottom:2rem; line-height:1.6; }
    .btn-back { display:inline-block; padding:.75rem 1.5rem; background:var(--primary);
                color:#fff; border-radius:var(--radius); text-decoration:none;
                font-weight:600; transition:opacity .2s; }
    .btn-back:hover { opacity:.85; }
  </style>
</head>
<body>
  <div class="error-box">
    <div class="error-code">403</div>
    <div class="error-title">Accès refusé</div>
    <p class="error-msg">
      Vous n'avez pas les permissions nécessaires pour accéder à cette page.<br>
      Contactez votre administrateur si vous pensez qu'il s'agit d'une erreur.
    </p>
    <a href="<?= BASE_URL ?>/login.php" class="btn-back">← Retour à l'accueil</a>
  </div>
</body>
</html>
