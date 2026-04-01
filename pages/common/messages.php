<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin','teacher','parent','student');

$userId = (int)Auth::id();
$role = Auth::role();
$errors = [];

// Envoi message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token invalide.';
    } else {
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $subject = sanitize($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if (!$receiverId || !$subject || !$body) {
            $errors[] = 'Champs obligatoires.';
        } else {
            Database::insert(
                'INSERT INTO messages (sender_id,receiver_id,subject,body) VALUES (?,?,?,?)',
                [$userId, $receiverId, $subject, $body]
            );
            createNotification($receiverId, 'message', 'Nouveau message', $subject, BASE_URL . '/modules/common/pages/messages.php');
            setFlash('success', 'Message envoye.');
            header('Location: ' . BASE_URL . '/modules/common/pages/messages.php');
            exit;
        }
    }
}

$tab = sanitize($_GET['tab'] ?? 'inbox'); // inbox|sent|compose|view
$msgId = (int)($_GET['id'] ?? 0);

// Marquer comme lu si view
$view = null;
if ($tab === 'view' && $msgId) {
    $view = Database::fetchOne(
        'SELECT m.*, 
                CONCAT(us.first_name," ",us.last_name) AS sender_name,
                CONCAT(ur.first_name," ",ur.last_name) AS receiver_name
         FROM messages m
         JOIN users us ON us.id = m.sender_id
         JOIN users ur ON ur.id = m.receiver_id
         WHERE m.id = ? AND (m.sender_id = ? OR m.receiver_id = ?)
         LIMIT 1',
        [$msgId, $userId, $userId]
    );
    if ($view && (int)$view['receiver_id'] === $userId) {
        Database::execute('UPDATE messages SET is_read=1, read_at=NOW() WHERE id=?', [$msgId]);
    }
}

$inbox = Database::fetchAll(
    'SELECT m.id, m.subject, m.is_read, m.created_at,
            CONCAT(u.first_name," ",u.last_name) AS from_name
     FROM messages m
     JOIN users u ON u.id = m.sender_id
     WHERE m.receiver_id = ?
     ORDER BY m.created_at DESC
     LIMIT 120',
    [$userId]
);
$sent = Database::fetchAll(
    'SELECT m.id, m.subject, m.created_at,
            CONCAT(u.first_name," ",u.last_name) AS to_name
     FROM messages m
     JOIN users u ON u.id = m.receiver_id
     WHERE m.sender_id = ?
     ORDER BY m.created_at DESC
     LIMIT 120',
    [$userId]
);

$users = Database::fetchAll(
    'SELECT id, role, first_name, last_name
     FROM users
     WHERE is_active=1 AND id <> ?
     ORDER BY role, last_name, first_name
     LIMIT 500',
    [$userId]
);

$pageTitle = 'Messagerie';
$pageIcon  = '💬';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="three-col">
  <div class="card">
    <div class="card-title">Boites</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a class="btn btn-secondary btn-sm" href="?tab=inbox">Inbox</a>
      <a class="btn btn-secondary btn-sm" href="?tab=sent">Envoyes</a>
      <a class="btn btn-primary btn-sm" href="?tab=compose">Nouveau</a>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><?= $tab==='sent'?'Envoyes':'Inbox' ?></div>
    <div style="max-height:520px;overflow:auto;">
      <?php foreach (($tab==='sent') ? $sent : $inbox as $m): ?>
        <a href="?tab=view&id=<?= (int)$m['id'] ?>" style="text-decoration:none;display:block;padding:10px 0;border-bottom:1px solid var(--border);">
          <div style="font-weight:800;color:var(--navy);">
            <?= e($m['subject']) ?>
            <?php if (isset($m['is_read']) && (int)$m['is_read']===0 && $tab!=='sent'): ?>
              <span class="badge badge-amber" style="margin-left:6px;">new</span>
            <?php endif; ?>
          </div>
          <div style="color:var(--muted);font-size:12px;">
            <?= e(($tab==='sent') ? ('A: '.$m['to_name']) : ('De: '.$m['from_name'])) ?> · <?= e(timeAgo($m['created_at'])) ?>
          </div>
        </a>
      <?php endforeach; ?>
      <?php if (($tab==='sent' ? empty($sent) : empty($inbox))): ?>
        <div class="empty-state" style="padding:20px;"><p>Aucun message.</p></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-title">
      <?= $tab==='compose' ? 'Nouveau message' : ($view ? 'Lecture' : 'Selectionne un message') ?>
    </div>

    <?php if ($tab === 'compose'): ?>
      <form method="post">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <div class="form-group">
          <label class="form-label">Destinataire</label>
          <select class="form-select" name="receiver_id" required>
            <option value="">Choisir...</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= e($u['role'].' - '.$u['last_name'].' '.$u['first_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Objet</label>
          <input class="form-input" name="subject" required>
        </div>
        <div class="form-group">
          <label class="form-label">Message</label>
          <textarea class="form-input" name="body" style="min-height:160px;" required></textarea>
        </div>
        <button class="btn btn-primary btn-full" type="submit">Envoyer</button>
      </form>
    <?php elseif ($view): ?>
      <div style="font-weight:900;color:var(--navy);margin-bottom:6px;"><?= e($view['subject']) ?></div>
      <div style="color:var(--muted);font-size:12px;margin-bottom:12px;">
        De <?= e($view['sender_name']) ?> · A <?= e($view['receiver_name']) ?> · <?= e(formatDateTime($view['created_at'])) ?>
      </div>
      <div style="white-space:pre-wrap;color:var(--navy);line-height:1.5;"><?= e($view['body']) ?></div>
    <?php else: ?>
      <div class="empty-state" style="padding:20px;"><p>Choisis un message a gauche, ou cree un nouveau.</p></div>
    <?php endif; ?>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

