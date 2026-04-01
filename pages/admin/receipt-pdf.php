<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/modules/shared/pdf/PdfService.php';

Auth::check('admin');

$paymentId = (int)($_GET['payment_id'] ?? 0);
if (!$paymentId) { http_response_code(400); die('payment_id requis'); }

$r = Database::fetchOne(
  'SELECT p.*, fi.label AS inst_label, f.label AS fee_label, f.level,
          s.id AS student_id, s.matricule, CONCAT(u.first_name," ",u.last_name) AS student_name,
          c.name AS class_name,
          CONCAT(rec.first_name," ",rec.last_name) AS recorder_name
   FROM payments p
   JOIN fee_installments fi ON fi.id=p.fee_installment_id
   JOIN fees f ON f.id=fi.fee_id
   JOIN students s ON s.id=p.student_id
   JOIN users u ON u.id=s.user_id
   JOIN classes c ON c.id=s.class_id
   JOIN users rec ON rec.id=p.recorded_by
   WHERE p.id=?',
  [$paymentId]
);
if (!$r) { http_response_code(404); die('Paiement introuvable'); }

$school = getSchool();

$html = '<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#0b1f3a;}
h1{font-size:18px;margin:0;}
.muted{color:#64748b;}
.row{display:flex;justify-content:space-between;gap:10px;}
.box{border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-top:12px;}
table{width:100%;border-collapse:collapse;margin-top:12px;}
td{padding:6px;border:1px solid #e5e7eb;}
</style></head><body>';

$html .= '<div class="row"><div><h1>'.e($school['name'] ?? APP_NAME).'</h1><div class="muted">Reçu de paiement</div></div>';
$html .= '<div style="text-align:right;"><b>'.e($r['receipt_number']).'</b><div class="muted">'.e(formatDate($r['payment_date'])).'</div></div></div>';

$html .= '<div class="box"><b>Élève:</b> '.e($r['student_name']).' (#'.e($r['matricule']).') — '.e($r['class_name']).'</div>';
$html .= '<table><tr><td><b>Échéance</b></td><td>'.e($r['inst_label']).'</td></tr>';
$html .= '<tr><td><b>Montant payé</b></td><td>'.e(number_format((float)$r['amount_paid'],0,'',' ')).'</td></tr>';
$html .= '<tr><td><b>Méthode</b></td><td>'.e($r['method']).'</td></tr>';
$html .= '<tr><td><b>Référence</b></td><td>'.e($r['transaction_ref'] ?? '—').'</td></tr>';
$html .= '<tr><td><b>Enregistré par</b></td><td>'.e($r['recorder_name']).'</td></tr></table>';
if (!empty($r['notes'])) $html .= '<div class="muted" style="margin-top:10px;">Note: '.e($r['notes']).'</div>';
$html .= '</body></html>';

$stored = PdfService::generateAndStore($html, 'receipt', (int)$r['student_id'], $paymentId, (int)Auth::id(), 'receipt');
if (!$stored) {
    setFlash('error', 'PDF serveur indisponible. Utilise Imprimer/Export PDF.');
    header('Location: ' . BASE_URL . '/modules/admin/pages/payments.php?receipt=' . $paymentId);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="receipt-' . $paymentId . '.pdf"');
readfile(BASE_PATH . '/' . $stored['file_path']);
exit;

