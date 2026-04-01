<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$export = sanitize($_GET['export'] ?? '');

if ($export === 'payments_csv') {
    $rows = Database::fetchAll(
      'SELECT p.payment_date, p.amount_paid, p.method, p.receipt_number,
              s.matricule, CONCAT(u.first_name," ",u.last_name) AS student_name
       FROM payments p
       JOIN students s ON s.id=p.student_id
       JOIN users u ON u.id=s.user_id
       ORDER BY p.payment_date DESC
       LIMIT 5000'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['payment_date','amount_paid','method','receipt','matricule','student']);
    foreach ($rows as $r) fputcsv($out, [$r['payment_date'],$r['amount_paid'],$r['method'],$r['receipt_number'],$r['matricule'],$r['student_name']]);
    fclose($out);
    exit;
}

if ($export === 'attendance_csv') {
    $rows = Database::fetchAll(
      'SELECT a.date, a.status, s.matricule, CONCAT(u.first_name," ",u.last_name) AS student_name, c.name AS class_name
       FROM attendance a
       JOIN students s ON s.id=a.student_id
       JOIN users u ON u.id=s.user_id
       JOIN classes c ON c.id=s.class_id
       ORDER BY a.date DESC
       LIMIT 5000'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['date','status','matricule','student','class']);
    foreach ($rows as $r) fputcsv($out, [$r['date'],$r['status'],$r['matricule'],$r['student_name'],$r['class_name']]);
    fclose($out);
    exit;
}

$pageTitle = 'Rapports';
$pageIcon  = '📊';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">Exports</div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a class="btn btn-secondary" href="?export=payments_csv">Exporter paiements (CSV)</a>
    <a class="btn btn-secondary" href="?export=attendance_csv">Exporter présences (CSV)</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/admin/pages/health.php">Health check</a>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

