<?php
require_once 'db.php';
header('Content-Type: application/json');

$telegram_bot_token = "YOUR_BOT_TOKEN_HERE";
$telegram_chat_id = "7742143795";

function sendTelegramNotification($message) {
    global $telegram_bot_token, $telegram_chat_id;
    if ($telegram_bot_token != "YOUR_BOT_TOKEN_HERE") {
        $url = "https://api.telegram.org/bot$telegram_bot_token/sendMessage?chat_id=$telegram_chat_id&text=" . urlencode($message) . "&parse_mode=Markdown";
        @file_get_contents($url);
    }
}

$action = $_GET['action'] ?? '';

if ($action === 'register') {
    $data = json_decode(file_get_contents("php://input"), true);
    $name = trim($data['name'] ?? '');
    $mobile = trim($data['mobile'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = password_hash($data['password'] ?? '', PASSWORD_DEFAULT);
    $upline = trim($data['upline'] ?? '');

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(["status" => "error", "message" => "Email already registered!"]);
        exit;
    }

    $uid = "BTC" . rand(1000, 9999);
    $stmt = $pdo->prepare("INSERT INTO users (uid, name, mobile, email, password, upline) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$uid, $name, $mobile, $email, $password, $upline])) {
        sendTelegramNotification("🔔 *New Registration*\nUID: `$uid`\nName: $name\nMobile: $mobile");
        echo json_encode(["status" => "success", "uid" => $uid]);
    } else {
        echo json_encode(["status" => "error", "message" => "Registration failed."]);
    }
} 
elseif ($action === 'login') {
    $data = json_decode(file_get_contents("php://input"), true);
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        echo json_encode(["status" => "success", "uid" => $user['uid']]);
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid email or password."]);
    }
}
elseif ($action === 'get_user') {
    $uid = $_GET['uid'] ?? '';
    $stmt = $pdo->prepare("SELECT uid, name, mobile, email, balance, plan_active, upline, last_task_date FROM users WHERE uid = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if ($user) {
        $downline_stmt = $pdo->prepare("SELECT uid, name, plan_active FROM users WHERE upline = ?");
        $downline_stmt->execute([$uid]);
        
        $dep_stmt = $pdo->prepare("SELECT amount, utr, created_at FROM deposits WHERE uid = ? ORDER BY id DESC");
        $dep_stmt->execute([$uid]);
        
        $wit_stmt = $pdo->prepare("SELECT amount, upi, status, created_at FROM withdrawals WHERE uid = ? ORDER BY id DESC");
        $wit_stmt->execute([$uid]);

        $p2p_stmt = $pdo->prepare("SELECT sender_uid, receiver_uid, amount, created_at FROM p2p_transfers WHERE sender_uid = ? OR receiver_uid = ? ORDER BY id DESC");
        $p2p_stmt->execute([$uid, $uid]);

        echo json_encode([
            "status" => "success", 
            "user" => $user, 
            "downline" => $downline_stmt->fetchAll(),
            "deposits" => $dep_stmt->fetchAll(),
            "withdrawals" => $wit_stmt->fetchAll(),
            "p2p" => $p2p_stmt->fetchAll()
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "User not found."]);
    }
}
elseif ($action === 'activate_plan') {
    $data = json_decode(file_get_contents("php://input"), true);
    $uid = $data['uid'] ?? '';
    $cost = 250;

    $stmt = $pdo->prepare("SELECT balance FROM users WHERE uid = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if (!$user || $user['balance'] < $cost) {
        echo json_encode(["status" => "error", "message" => "Insufficient balance (₹250 required)."]);
        exit;
    }

    $pdo->prepare("UPDATE users SET balance = balance - ?, plan_active = 1 WHERE uid = ?")->execute([$cost, $uid]);
    sendTelegramNotification("⚡ *Plan Activated*\nUID: `$uid`");
    echo json_encode(["status" => "success"]);
}
elseif ($action === 'complete_task') {
    $data = json_decode(file_get_contents("php://input"), true);
    $uid = $data['uid'] ?? '';
    $reward = 50;
    $today = date('Y-m-d');

    $stmt = $pdo->prepare("SELECT plan_active, last_task_date FROM users WHERE uid = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if (!$user || $user['plan_active'] != 1) {
        echo json_encode(["status" => "error", "message" => "Active plan required."]);
        exit;
    }

    if ($user['last_task_date'] === $today) {
        echo json_encode(["status" => "error", "message" => "Task already completed today! Come back tomorrow."]);
        exit;
    }

    $pdo->prepare("UPDATE users SET balance = balance + ?, last_task_date = ? WHERE uid = ?")->execute([$reward, $today, $uid]);
    echo json_encode(["status" => "success", "reward" => $reward]);
}
elseif ($action === 'deposit') {
    $uid = $_POST['uid'] ?? '';
    $amount = $_POST['amount'] ?? 0;
    $utr = $_POST['utr'] ?? '';

    if ($amount > 0 && !empty($utr)) {
        $pdo->prepare("INSERT INTO deposits (uid, amount, utr, status) VALUES (?, ?, ?, 'Success')")->execute([$uid, $amount, $utr]);
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE uid = ?")->execute([$amount, $uid]);
        sendTelegramNotification("💰 *Deposit Added*\nUID: `$uid`\nAmount: ₹$amount\nUTR: $utr");
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid details."]);
    }
}
elseif ($action === 'withdraw') {
    $data = json_decode(file_get_contents("php://input"), true);
    $uid = $data['uid'] ?? '';
    $amount = floatval($data['amount'] ?? 0);
    $upi = trim($data['upi'] ?? '');

    if ($amount < 200) {
        echo json_encode(["status" => "error", "message" => "Minimum withdrawal is ₹200."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT balance FROM users WHERE uid = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if (!$user || $user['balance'] < $amount) {
        echo json_encode(["status" => "error", "message" => "Insufficient balance."]);
        exit;
    }

    $pdo->prepare("UPDATE users SET balance = balance - ? WHERE uid = ?")->execute([$amount, $uid]);
    $pdo->prepare("INSERT INTO withdrawals (uid, amount, upi, status) VALUES (?, ?, ?, 'Pending')")->execute([$uid, $amount, $upi]);
    sendTelegramNotification("💸 *Withdrawal Request*\nUID: `$uid`\nAmount: ₹$amount\nUPI: $upi");
    echo json_encode(["status" => "success"]);
}
elseif ($action === 'p2p_transfer') {
    $data = json_decode(file_get_contents("php://input"), true);
    $sender_uid = $data['sender_uid'] ?? '';
    $receiver_uid = trim($data['receiver_uid'] ?? '');
    $amount = floatval($data['amount'] ?? 0);

    if ($sender_uid === $receiver_uid || $amount <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid transfer details."]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT uid FROM users WHERE uid = ?");
    $stmt->execute([$receiver_uid]);
    if (!$stmt->fetch()) {
        echo json_encode(["status" => "error", "message" => "Receiver UID not found!"]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT balance FROM users WHERE uid = ?");
    $stmt->execute([$sender_uid]);
    $sender = $stmt->fetch();

    if (!$sender || $sender['balance'] < $amount) {
        echo json_encode(["status" => "error", "message" => "Insufficient balance for P2P transfer."]);
        exit;
    }

    $pdo->prepare("UPDATE users SET balance = balance - ? WHERE uid = ?")->execute([$amount, $sender_uid]);
    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE uid = ?")->execute([$amount, $receiver_uid]);
    $pdo->prepare("INSERT INTO p2p_transfers (sender_uid, receiver_uid, amount) VALUES (?, ?, ?)")->execute([$sender_uid, $receiver_uid, $amount]);

    echo json_encode(["status" => "success"]);
}
elseif ($action === 'admin_get_all') {
    $stmt = $pdo->query("SELECT uid, name, mobile, email, password, balance, plan_active, created_at FROM users ORDER BY id DESC");
    $users = $stmt->fetchAll();

    $dep_stmt = $pdo->query("SELECT * FROM deposits ORDER BY id DESC");
    $wit_stmt = $pdo->query("SELECT * FROM withdrawals ORDER BY id DESC");

    echo json_encode([
        "status" => "success",
        "users" => $users,
        "deposits" => $dep_stmt->fetchAll(),
        "withdrawals" => $wit_stmt->fetchAll()
    ]);
}
?>
