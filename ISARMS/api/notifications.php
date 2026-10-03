<?php
define('ROOT', __DIR__ . '/..');
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
header('Content-Type: application/json');
startSecureSession();
if (!isLoggedIn()) { echo json_encode(['count'=>0]); exit; }

$user   = getCurrentUser();
$db     = Database::getInstance();
$rawBody = file_get_contents('php://input');
$bodyJson = $rawBody !== false && trim($rawBody) !== '' ? json_decode($rawBody, true) : [];
$action = $_GET['action'] ?? $_POST['action'] ?? ($bodyJson['action'] ?? '');
if (in_array($action, ['delete', 'delete_all', 'mark_read', 'clear_student', 'clear_user'], true)) {
    requireCSRFToken();
}

$isStudent  = ($user['role'] === 'Student');
$roleTypeMap = [
    'Student'   => 'Student',
    'Registrar' => 'User',
    'Admin'     => 'User',
    'Teacher'   => 'User',
];
$recipType  = $roleTypeMap[$user['role']] ?? 'User';
$senderRole = $user['role'];

switch ($action) {
    case 'count':
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM notifications
             WHERE recipientID=:id AND recipientType=:rt AND isRead=0",
            [':id'=>$user['id'], ':rt'=>$recipType]
        );
        echo json_encode(['count' => (int)($row['c'] ?? 0)]);
        break;

    case 'list':
        $notifs = $db->fetchAll(
            "SELECT n.*
             FROM notifications n
             JOIN (
                 SELECT MAX(notifID) AS notifID
                 FROM notifications
                 WHERE recipientID=:id AND recipientType=:rt
                 GROUP BY title, message, type, COALESCE(senderRole,''),
                          DATE_FORMAT(createdAt, '%Y-%m-%d %H:%i')
             ) latest ON latest.notifID=n.notifID
             ORDER BY n.createdAt DESC LIMIT 20",
            [':id'=>$user['id'], ':rt'=>$recipType]
        );
        echo json_encode(['success'=>true,'notifications'=>$notifs]);
        break;

    case 'delete':
        $nid = (int)($bodyJson['notifID'] ?? $_POST['notifID'] ?? 0);
        if (!$nid) { echo json_encode(['success'=>false,'message'=>'Notification ID required.']); break; }
        $db->query(
            "DELETE FROM notifications WHERE notifID=:id AND recipientID=:uid AND recipientType=:rt",
            [':id'=>$nid, ':uid'=>$user['id'], ':rt'=>$recipType]
        );
        echo json_encode(['success'=>true]);
        break;

    case 'delete_all':
        $db->query("DELETE FROM notifications WHERE recipientID=:uid AND recipientType=:rt",
            [':uid'=>$user['id'], ':rt'=>$recipType]);
        echo json_encode(['success'=>true]);
        break;

    case 'mark_read':
        $nid  = (int)($bodyJson['notifID'] ?? 0);
        if ($nid) {
            $notification = $db->fetchOne(
                "SELECT title, message, type, senderRole, createdAt
                 FROM notifications
                 WHERE notifID=:id AND recipientID=:uid AND recipientType=:rt",
                [':id'=>$nid,':uid'=>$user['id'],':rt'=>$recipType]
            );
            if ($notification) {
                $db->query(
                    "UPDATE notifications
                     SET isRead=1
                     WHERE notifID=:id AND recipientID=:uid AND recipientType=:rt",
                    [':id'=>$nid, ':uid'=>$user['id'], ':rt'=>$recipType]
                );
                $db->query(
                    "UPDATE notifications
                     SET isRead=1
                     WHERE recipientID=:uid
                       AND recipientType=:rt
                       AND title <=> :title
                       AND message <=> :message
                       AND type <=> :type
                       AND senderRole <=> :senderRole
                       AND DATE_FORMAT(createdAt, '%Y-%m-%d %H:%i') = DATE_FORMAT(:createdAt, '%Y-%m-%d %H:%i')",
                    [
                        ':uid'        => $user['id'],
                        ':rt'         => $recipType,
                        ':title'      => $notification['title'],
                        ':message'    => $notification['message'],
                        ':type'       => $notification['type'],
                        ':senderRole' => $notification['senderRole'],
                        ':createdAt'  => $notification['createdAt'],
                    ]
                );
            }
        } else {
            $db->query("UPDATE notifications SET isRead=1 WHERE recipientID=:uid AND recipientType=:rt",
                       [':uid'=>$user['id'],':rt'=>$recipType]);
        }
        echo json_encode(['success'=>true]);
        break;

    case 'clear_student':
        requireAuth('Student');
        $db->query("UPDATE notifications SET isRead=1 WHERE recipientID=:uid AND recipientType='Student'",
                   [':uid'=>$user['id']]);
        echo json_encode(['success'=>true]);
        break;

    case 'clear_user':
        requireAuth('Teacher');
        $db->query("UPDATE notifications SET isRead=1 WHERE recipientID=:uid AND recipientType='User'",
                   [':uid'=>$user['id']]);
        echo json_encode(['success'=>true]);
        break;

    default:
        echo json_encode(['count'=>0]);
}