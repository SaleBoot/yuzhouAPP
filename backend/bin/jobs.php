<?php
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/extend/yuzhou/bootstrap.php';
try {
    $db = yuzhou\Database::connect();
    $expired = (new yuzhou\Orders($db))->expireReservations();
    $settled = (new yuzhou\Finance($db))->settle();
    $reminders = class_exists('yuzhou\Communication') ? (new yuzhou\Communication($db))->sendDueReminders() : 0;
    $smsPurged = (new yuzhou\Auth($db))->purgeSms();
    echo json_encode(['expired_reservations' => $expired, 'settled_entries' => $settled, 'reminder_notifications' => $reminders, 'sms_purged' => $smsPurged, 'external_payments_performed' => false]) . PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, "Scheduled job failed; inspect configuration and database.\n"); exit(1); }
