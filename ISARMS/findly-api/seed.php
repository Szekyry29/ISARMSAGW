<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
if (getenv('FINDLY_ALLOW_DEMO_SEED') !== '1') {
  fwrite(STDERR, "Findly demo seed disabled. Set FINDLY_ALLOW_DEMO_SEED=1 only in a local test environment.\n");
  exit(1);
}

require __DIR__.'/config.php';
$pdo = db();
$accounts = [
  ['Mika Santos', 'admin@findly.local', 'admin123', 'admin'],
  ['Paolo Reyes', 'staff@findly.local', 'staff123', 'staff'],
  ['Ana Cruz', 'ana@findly.local', 'user123', 'user'],
  ['Juan Dela Cruz', 'juan@findly.local', 'juan12345', 'user'],
];
$stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role=VALUES(role)');
foreach ($accounts as [$name, $email, $password, $role]) $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
$admin = (int) $pdo->query("SELECT id FROM users WHERE email='admin@findly.local'")->fetchColumn();
$staff = (int) $pdo->query("SELECT id FROM users WHERE email='staff@findly.local'")->fetchColumn();
$items = [
  ['F-1042','Black wireless earbuds','Electronics','Library, 2nd floor','2026-09-18','found','verified','Black case with a small blue sticker.',$staff],
  ['L-1041','Blue canvas tote bag','Bags','North Gate','2026-09-17','lost','open','Contains a notebook and silver water bottle.',$admin],
  ['F-1038','Student ID card','IDs & Cards','Engineering Building','2026-09-15','found','claimed','ID belongs to a third-year student.',$admin],
  ['L-1035','Silver Casio watch','Personal','Cafeteria','2026-09-12','lost','open','Silver band, small scratches near the clasp.',$admin],
];
$itemStmt = $pdo->prepare('INSERT IGNORE INTO items (reference_code,title,category,location,report_date,type,status,description,reporter_id) VALUES (?,?,?,?,?,?,?,?,?)');
foreach ($items as $item) $itemStmt->execute($item);
foreach ([$admin, $staff] as $uid) { $pdo->prepare('INSERT INTO notifications (user_id,title,message,kind) VALUES (?,?,?,?)')->execute([$uid,'Welcome to Findly','Your recovery desk is ready. Review today\'s activity and pending actions.','info']); }
echo "Findly database seeded successfully.\n";
