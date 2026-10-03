<?php
declare(strict_types=1);
require __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ['http://localhost:5173', 'http://localhost:8100', 'http://127.0.0.1:8100'], true)) header('Access-Control-Allow-Origin: '.$origin);
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
function out(mixed $data, int $code=200): never { http_response_code($code); echo json_encode($data); exit; }
function input(): array { return json_decode(file_get_contents('php://input'), true) ?: []; }
function token(): string { return preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? ''); }
function user(): array { $hash = hash('sha256', token()); $stmt = db()->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at > NOW() AND u.status="active"'); $stmt->execute([$hash]); $row = $stmt->fetch(); if (!$row) out(['error'=>'Authentication required'],401); return $row; }
function role(array $allowed): array { $u=user(); if (!in_array($u['role'],$allowed,true)) out(['error'=>'Insufficient permissions'],403); return $u; }
function cleanUser(array $u): array { return ['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']]; }
function notify(int $uid, string $title, string $message, string $kind='info'): void { $s=db()->prepare('INSERT INTO notifications(user_id,title,message,kind) VALUES (?,?,?,?)'); $s->execute([$uid,$title,$message,$kind]); }
function audit(?int $uid,string $action,string $type,?int $entity,array $details=[]): void { $s=db()->prepare('INSERT INTO audit_logs(actor_id,action,entity_type,entity_id,details) VALUES (?,?,?,?,?)'); $s->execute([$uid,$action,$type,$entity,json_encode($details)]); }
try { $pdo=db(); $action=$_GET['action'] ?? '';
  if ($action==='login' && $_SERVER['REQUEST_METHOD']==='POST') { $d=input(); $s=$pdo->prepare('SELECT * FROM users WHERE email=? AND status="active"'); $s->execute([$d['email']??'']); $u=$s->fetch(); if (!$u || !password_verify($d['password']??'',$u['password_hash'])) out(['error'=>'Invalid email or password'],401); $plain=bin2hex(random_bytes(32)); $s=$pdo->prepare('INSERT INTO sessions(token_hash,user_id,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 8 HOUR))'); $s->execute([hash('sha256',$plain),$u['id']]); audit((int)$u['id'],'login','user',(int)$u['id']); out(['user'=>cleanUser($u),'token'=>$plain]); }
  $u=user();
  if ($action==='items' && $_SERVER['REQUEST_METHOD']==='GET') { $q=$pdo->query('SELECT i.*, u.name reporter FROM items i JOIN users u ON u.id=i.reporter_id ORDER BY i.created_at DESC'); out($q->fetchAll()); }
  if ($action==='items' && $_SERVER['REQUEST_METHOD']==='POST') { $d=input(); $code=($d['type']==='found'?'F':'L').'-'.random_int(1000,9999); $s=$pdo->prepare('INSERT INTO items(reference_code,title,category,location,report_date,type,status,description,reporter_id) VALUES (?,?,?,?,?,?,?,?,?)'); $s->execute([$code,$d['title'],$d['category'],$d['location'],$d['report_date'] ?? $d['date'],$d['type'],$d['type']==='found'?'pending':'open',$d['description'],$u['id']]); $id=(int)$pdo->lastInsertId(); audit((int)$u['id'],'create','item',$id,$d); out(['id'=>$id,'reference_code'=>$code],201); }
  if ($action==='item_update' && $_SERVER['REQUEST_METHOD']==='PATCH') { $staff=role(['admin','staff']); $d=input(); $id=(int)($_GET['id']??0); $s=$pdo->prepare('UPDATE items SET status=?,verified_by=? WHERE id=?'); $s->execute([$d['status'],$staff['id'],$id]); $q=$pdo->prepare('SELECT reporter_id FROM items WHERE id=?'); $q->execute([$id]); if ($owner=$q->fetchColumn()) notify((int)$owner,'Record updated','Your item record status is now '.$d['status'].'.','status'); audit((int)$staff['id'],'update','item',$id,$d); out(['success'=>true]); }
  if ($action==='stats') { $stats=$pdo->query("SELECT COUNT(*) total, SUM(status='open') open, SUM(type='found') found, SUM(type='lost') lost, SUM(status='claimed') recovered FROM items")->fetch(); out(array_map('intval',$stats)); }
  if ($action==='users' && $_SERVER['REQUEST_METHOD']==='GET') { role(['admin']); out($pdo->query('SELECT id,name,email,role,status,created_at FROM users ORDER BY created_at DESC')->fetchAll()); }
  if ($action==='users' && $_SERVER['REQUEST_METHOD']==='POST') { role(['admin']); $d=input(); $s=$pdo->prepare('INSERT INTO users(name,email,password_hash,role) VALUES (?,?,?,?)'); $s->execute([$d['name'],$d['email'],password_hash($d['password'],PASSWORD_DEFAULT),$d['role']]); $id=(int)$pdo->lastInsertId(); audit((int)$u['id'],'create','user',$id,['email'=>$d['email'],'role'=>$d['role']]); out(['id'=>$id,'name'=>$d['name'],'email'=>$d['email'],'role'=>$d['role']],201); }
  if ($action==='notifications') { $s=$pdo->prepare('SELECT id,title,message,kind,is_read,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30'); $s->execute([$u['id']]); out($s->fetchAll()); }
  if ($action==='notification_read' && $_SERVER['REQUEST_METHOD']==='PATCH') { $s=$pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?'); $s->execute([(int)($_GET['id']??0),$u['id']]); out(['success'=>true]); }
  if ($action==='claims' && $_SERVER['REQUEST_METHOD']==='GET') { $s=$pdo->prepare('SELECT c.*,i.reference_code,i.title,u.name claimant FROM claims c JOIN items i ON i.id=c.item_id JOIN users u ON u.id=c.claimant_id WHERE (? IN ("admin","staff") OR c.claimant_id=?) ORDER BY c.created_at DESC'); $s->execute([$u['role'],$u['id']]); out($s->fetchAll()); }
  if ($action==='claims' && $_SERVER['REQUEST_METHOD']==='POST') { $d=input(); $s=$pdo->prepare('INSERT INTO claims(item_id,claimant_id,proof) VALUES (?,?,?)'); $s->execute([(int)$d['item_id'],$u['id'],$d['proof']]); out(['success'=>true],201); }
  if ($action==='claim_review' && $_SERVER['REQUEST_METHOD']==='PATCH') { $staff=role(['admin','staff']); $d=input(); $s=$pdo->prepare('UPDATE claims SET status=?,reviewed_by=? WHERE id=?'); $s->execute([$d['status'],$staff['id'],(int)$_GET['id']]); out(['success'=>true]); }
  out(['error'=>'Route not found'],404);
} catch (Throwable $e) { error_log($e->getMessage()); out(['error'=>'Server error'],500); }
