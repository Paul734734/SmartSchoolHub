<?php
/**
 * SmartSchool Hub — Footer HTML réutilisable
 * Ferme les balises ouvertes dans header.php
 */
if (!defined('BASE_PATH')) { http_response_code(403); exit; }
?>

    </div><!-- /page-body -->

    <!-- Pied de page intérieur -->
    <footer style="
      padding: 16px 32px;
      border-top: 1px solid var(--border);
      background: var(--card);
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 12px;
      color: var(--muted);
    ">
      <span>
        © <?= date('Y') ?>
        <strong style="color:var(--navy);">SmartSchool Hub</strong>
        v<?= APP_VERSION ?> —
        <?= e($school['name'] ?? APP_NAME) ?>
      </span>
      <span>
        <?= e(tr('Developpe par', 'Built by')) ?>
        <a href="mailto:pauljovani15@gmail.com" style="color:var(--blue);font-weight:600;">
          Etouke Paul Jovani
        </a>
        · Yaounde
      </span>
    </footer>

  </div><!-- /main-content -->

</div><!-- /app-layout -->

<!-- Scripts JS -->
<script>
  // Injecter BASE_URL pour app.js
  document.documentElement.dataset.baseUrl = '<?= BASE_URL ?>';
</script>
<script src="<?= ASSETS_URL ?>/js/app.js"></script>

<!-- CSRF meta tag pour requêtes AJAX -->
<meta name="csrf-token" content="<?= e(Auth::csrfToken()) ?>">

<?php if (isset($extraScripts)) echo $extraScripts; ?>

</body>
</html>
