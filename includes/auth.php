<?php
// includes/auth.php
// รวมฟังก์ชันเกี่ยวกับสมัครสมาชิก, login, logout และเช็คสิทธิ์การใช้งาน
// ไฟล์ที่จะใช้ฟังก์ชันพวกนี้ต้อง include config/db.php มาก่อนแล้ว (ต้องมี $pdo)

session_start();

// สมัครสมาชิกใหม่ ค่าเริ่มต้น role = athlete (แอดมินสร้างเองแยกต่างหาก ไม่เปิดให้สมัครผ่านหน้าเว็บ)
// $securityQuestion / $securityAnswer ใช้สำหรับฟีเจอร์ "ลืมรหัสผ่าน"
// (ระบบไม่มีการส่งอีเมลจริง จึงใช้คำถามกันลืมแทนลิงก์รีเซ็ตทางอีเมล)
function registerUser($pdo, $username, $email, $password, $securityQuestion, $securityAnswer)
{
    // เช็คก่อนว่า username หรือ email ซ้ำไหม
    $check = $pdo->prepare("SELECT user_id FROM users WHERE username = :username OR email = :email");
    $check->execute(['username' => $username, 'email' => $email]);
    if ($check->fetch()) {
        throw new Exception("ชื่อผู้ใช้หรืออีเมลนี้มีคนใช้แล้ว");
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    // ตัดช่องว่างหัวท้ายและแปลงเป็นตัวพิมพ์เล็กก่อน hash คำตอบ กันปัญหาพิมพ์ใหญ่เล็กไม่ตรงตอนรีเซ็ต
    $normalizedAnswer = mb_strtolower(trim($securityAnswer));
    $answerHash = password_hash($normalizedAnswer, PASSWORD_DEFAULT);

    $insert = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, role, security_question, security_answer_hash)
        VALUES (:username, :email, :password_hash, 'athlete', :question, :answer_hash)
    ");
    $insert->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $hashedPassword,
        'question' => $securityQuestion,
        'answer_hash' => $answerHash,
    ]);

    $userId = $pdo->lastInsertId();

    // ไม่สร้างโปรไฟล์ players ให้ตรงนี้แล้ว (เคยทำแบบนั้นตอนแรก)
    // เปลี่ยนเป็นให้ผู้ใช้เลือกเองตอน login ครั้งแรกว่าจะ claim โปรไฟล์เก่า
    // ที่ import มาจาก RoV หรือจะสร้างโปรไฟล์ใหม่ (ดูหน้า pages/claim-profile.php)

    return $userId;
}

// login ผู้ใช้ ถ้าถูกต้องจะเก็บข้อมูลไว้ใน session
function loginUser($pdo, $username, $password)
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new Exception("ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง");
    }

    if ($user['status'] === 'suspended') {
        $reason = trim((string) ($user['suspension_reason'] ?? ''));
        $suspendedAt = !empty($user['suspended_at']) ? date('d/m/Y H:i:s', strtotime($user['suspended_at'])) : '';
        $message = 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
        if ($reason !== '') $message .= ' เหตุผล: ' . $reason;
        if ($suspendedAt !== '') $message .= ' (ระงับเมื่อ ' . $suspendedAt . ')';
        throw new Exception($message);
    }
    if ($user['status'] !== 'active') {
        throw new Exception("บัญชีนี้ถูกปิดใช้งาน กรุณาติดต่อผู้ดูแลระบบ");
    }

    // สร้าง session id ใหม่ทุกครั้งที่ login สำเร็จ ป้องกันคนอื่นเดา/ปลูกฝัง session id เก่ามาใช้
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = :user_id")
        ->execute(['user_id' => $user['user_id']]);

    return $user;
}

// สร้าง CSRF token ไว้ใส่ในฟอร์ม (เรียกตอนแสดงฟอร์ม)
function generateCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// เช็ค CSRF token ตอนฟอร์มถูกส่งมา (เรียกตอนรับ POST)
function verifyCsrfToken($token)
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    // ใช้ hash_equals กันการเทียบ string แบบ timing attack
    return hash_equals($_SESSION['csrf_token'], $token);
}

function logoutUser()
{
    $_SESSION = [];
    session_destroy();
}

// เช็คว่า login อยู่หรือยัง
function isLoggedIn()
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        static $checkedUserId = null;
        static $isActive = null;
        $userId = (int) $_SESSION['user_id'];
        if ($checkedUserId !== $userId) {
            $stmt = $pdo->prepare('SELECT status FROM users WHERE user_id = :user_id LIMIT 1');
            $stmt->execute(['user_id' => $userId]);
            $isActive = $stmt->fetchColumn() === 'active';
            $checkedUserId = $userId;
        }

        if (!$isActive) {
            $_SESSION = [];
            session_destroy();
            return false;
        }
    }

    return true;
}

// ถ้ายังไม่ login ให้เด้งไปหน้า login เลย ใช้ต้นไฟล์ที่ต้องการป้องกัน
function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: ../auth/login.php');
        exit;
    }

    // ตรวจสถานะบัญชีจากฐานข้อมูลทุกครั้ง ป้องกันสมาชิกที่ Login ค้างอยู่
    // ใช้งานระบบต่อหลังจาก Admin ระงับบัญชีแล้ว
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT status, suspension_reason, suspended_at FROM users WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $_SESSION['user_id']]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        $accountStatus = $account['status'] ?? null;

        if ($accountStatus !== 'active') {
            $_SESSION = [];
            session_destroy();
            $statusParam = $accountStatus === 'suspended' ? 'suspended' : 'disabled';
            header('Location: ../auth/login.php?account_status=' . $statusParam);
            exit;
        }
    }
}

// เช็คว่า role ตรงตามที่ต้องการไหม (เช่นหน้า admin ต้องเป็น role admin เท่านั้น)
function requireRole($role)
{
    requireLogin();
    if ($_SESSION['role'] != $role) {
        die("ไม่มีสิทธิ์เข้าถึงหน้านี้");
    }
}

// รายการคำถามกันลืมให้เลือกตอนสมัครสมาชิก (คงที่ ไม่ให้พิมพ์เอง กันคำถามที่ตอบง่ายเกินไป)
function securityQuestionOptions()
{
    return [
        'ชื่อเล่นสมัยเด็กของคุณคืออะไร',
        'ชื่อสัตว์เลี้ยงตัวแรกของคุณคืออะไร',
        'โรงเรียนประถมที่คุณเรียนคือโรงเรียนอะไร',
        'เกมที่คุณเล่นเป็นเกมแรกคือเกมอะไร',
        'ชื่อกลางของแม่คุณคืออะไร',
    ];
}

// ตรวจคำตอบกันลืม เทียบแบบไม่สนตัวพิมพ์ใหญ่เล็ก/ช่องว่างหัวท้าย (ต้อง normalize แบบเดียวกับตอนสมัคร)
function verifySecurityAnswer($answerHash, $submittedAnswer)
{
    $normalized = mb_strtolower(trim($submittedAnswer));
    return password_verify($normalized, $answerHash);
}

// ตั้งรหัสผ่านใหม่ให้ user (ใช้ทั้งตอนผู้ใช้รีเซ็ตเองผ่านคำถามกันลืม และตอน Admin รีเซ็ตให้)
function setNewPassword($pdo, $userId, $newPassword)
{
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id")
        ->execute(['hash' => $hash, 'id' => $userId]);
}

// สร้างรหัสผ่านชั่วคราวแบบสุ่ม ใช้ตอน Admin กดรีเซ็ตให้สมาชิก (เอาไปบอกสมาชิกนอกระบบ เช่น โทร/แชท)
function generateTempPassword()
{
    $chars = 'ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz123456789';
    $password = '';
    for ($i = 0; $i < 8; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}
