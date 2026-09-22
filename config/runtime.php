<?php

declare(strict_types=1);

const APPLICATION_PHP_TARGET = '8.3';
const APPLICATION_MIN_PHP_VERSION_ID = 80300;

if (PHP_VERSION_ID < APPLICATION_MIN_PHP_VERSION_ID) {
    http_response_code(503);
    error_log(sprintf(
        'Unsupported PHP version %s. This application requires PHP %s or newer.',
        PHP_VERSION,
        APPLICATION_PHP_TARGET
    ));
    exit('ระบบต้องใช้ PHP 8.3 ขึ้นไป กรุณาติดต่อผู้ดูแลเซิร์ฟเวอร์');
}
