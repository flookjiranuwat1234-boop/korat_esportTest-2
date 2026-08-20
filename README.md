# Korat Esport - วิธีติดตั้ง

## 1. วางไฟล์
คัดลอกทุกโฟลเดอร์ (config, includes, admin, pages, auth, assets) ไปไว้ที่
C:\xampp\htdocs\koratesport\

## 2. สร้างฐานข้อมูล
เปิด phpMyAdmin สร้างฐานข้อมูลชื่อ korat_esport (collation: utf8mb4_unicode_ci)
แล้ว import ไฟล์ตามลำดับนี้ (สำคัญ ต้องเรียงลำดับ):

1. sql/korat_esport_schema.sql        (สร้างตารางหลักทั้งหมด)
2. sql/seed_rov_teams.sql             (ข้อมูลทีม RoV 93 ทีม)
3. sql/migration_news_gallery.sql     (เพิ่มตาราง news, gallery)
4. sql/migration_password_reset.sql   (เพิ่มคอลัมน์คำถามกันลืมรหัสผ่าน)

## 3. ตั้งค่าเชื่อมต่อฐานข้อมูล
เช็คไฟล์ config/db.php ว่าตรงกับ XAMPP ของเครื่อง (ปกติไม่ต้องแก้อะไร ถ้าใช้ค่า default)

## 4. สร้างบัญชี Admin คนแรก
สมัครผ่านหน้าเว็บได้แค่ role athlete เท่านั้น ต้องสร้าง admin ตรงผ่าน phpMyAdmin:
1. เข้า localhost/koratesport/pages/index.php แล้วลองสมัครสมาชิก 1 บัญชีปกติก่อน (จะได้ password_hash ที่ถูกต้อง)
2. เปิด phpMyAdmin ไปที่ตาราง users แก้ค่า role ของบัญชีนั้นจาก athlete เป็น admin
(หรือดูขั้นตอน gen_hash.php แบบเดิมที่เคยแนะนำไว้ก่อนหน้าในบทสนทนา ถ้าอยากสร้างแยกจากบัญชีทดสอบ)

## หมายเหตุ
- โฟลเดอร์ assets/uploads/ จะถูกสร้างอัตโนมัติตอนอัปโหลดรูปครั้งแรก ถ้าเซิร์ฟเวอร์ไม่ยอมสร้างเอง
  ให้สร้างโฟลเดอร์ assets/uploads/ เตรียมไว้เอง (permission เขียนได้)
- ไฟล์รายงาน (บทที่ 1, บทที่ 3) และไดอะแกรม .drawio อยู่ในโฟลเดอร์ reports/
