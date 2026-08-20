<?php
// includes/upload.php
// ฟังก์ชันอัปโหลดรูปภาพ ใช้ร่วมกันหลายหน้า (ข่าวสาร, แกลลอรี่, โปรไฟล์, โลโก้ทีม)
// เก็บไฟล์ไว้ใน assets/uploads/{โฟลเดอร์ย่อย}/ แล้วคืน path สั้นๆ กลับไปเก็บใน database

$MAX_UPLOAD_SIZE = 5 * 1024 * 1024; // 5MB
$ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

function handleImageUpload($file, $subfolder)
{
    global $MAX_UPLOAD_SIZE, $ALLOWED_TYPES;

    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // ไม่ได้แนบไฟล์มา ไม่ต้องทำอะไรต่อ
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('อัปโหลดไฟล์ไม่สำเร็จ');
    }
    if ($file['size'] > $MAX_UPLOAD_SIZE) {
        throw new Exception('ไฟล์ใหญ่เกินไป (ไม่เกิน 5MB)');
    }

    // เช็คชนิดไฟล์จากตัวเนื้อหาจริง ไม่ใช่แค่ดูนามสกุล เผื่อมีคนเปลี่ยนนามสกุลไฟล์หลอกระบบ
    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, $ALLOWED_TYPES)) {
        throw new Exception('อัปโหลดได้แค่ไฟล์รูปภาพ (JPG, PNG, WEBP)');
    }

    $ext = ($mimeType == 'image/png') ? 'png' : (($mimeType == 'image/webp') ? 'webp' : 'jpg');
    $fileName = uniqid() . '_' . time() . '.' . $ext;

    $uploadDir = __DIR__ . '/../assets/uploads/' . $subfolder . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    move_uploaded_file($file['tmp_name'], $uploadDir . $fileName);

    return 'uploads/' . $subfolder . '/' . $fileName;
}

// ลบไฟล์รูปเก่าออกจากเซิร์ฟเวอร์ เรียกตอนอัปโหลดรูปใหม่ทับ หรือตอนลบข้อมูล
function deleteUploadedImage($path)
{
    if (empty($path)) {
        return;
    }
    $fullPath = __DIR__ . '/../assets/' . $path;
    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
}
