<?php
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';
require_once __DIR__ . '/../../backend/application/api/controller/Index.php';

use yuzhou\Database;
use yuzhou\ApiError;
use yuzhou\Catalog;
use yuzhou\Orders;
use yuzhou\Finance;
use yuzhou\Community;
use yuzhou\Auth;
use yuzhou\Admin;

$checks = 0;
function check($condition, $label) { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function blocked($callback, $status, $label) { try { $callback(); } catch (ApiError $e) { check($e->status === $status, $label . ' (expected HTTP ' . $status . ')'); return; } throw new RuntimeException($label . ' was allowed'); }
function schema(Database $db) {
    $db->pdo->exec(file_get_contents(__DIR__ . '/../../database/sqlite.sql'));
    $db->pdo->exec(file_get_contents(__DIR__ . '/../../database/communication-sqlite.sql'));
    foreach (glob(__DIR__ . '/../../database/migrations/*-sqlite.sql') as $file) $db->pdo->exec(file_get_contents($file));
}
function fixture(Database $db) {
    $now = time();
    foreach (['merchant', 'agency', 'agency'] as $i => $kind) $db->insert('organizations', ['name' => 'Synthetic test org ' . $i, 'kind' => $kind, 'status' => 'approved', 'payload' => '{}', 'created_at' => $now]);
    $users = [];
    foreach (['platform' => null, 'consumer' => null, 'broker' => 2, 'merchant' => 1, 'agency' => 2, 'outsider' => null, 'otherbroker' => 3] as $role => $org) {
        $id = $db->insert('users', ['username' => 'test_' . $role, 'password_hash' => password_hash('Synthetic-test-1234', PASSWORD_DEFAULT), 'nickname' => $role, 'role' => in_array($role, ['outsider', 'otherbroker']) ? ($role === 'outsider' ? 'consumer' : 'broker') : $role,
            'org_id' => $org, 'member_until' => in_array($role,['consumer','outsider'],true) ? $now + 86400 * 180 : 0, 'identity_status'=>in_array($role,['consumer','outsider'],true)?'verified':'unverified', 'created_at' => $now, 'updated_at' => $now]);
        $users[$role] = $db->one('SELECT * FROM users WHERE id=?', [$id]);
    }
    $file = $db->insert('uploads', ['owner_id' => $users['broker']['id'], 'org_id' => 2, 'storage_name' => 'synthetic.pdf', 'original_name' => 'Synthetic contract.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'is_private' => 1, 'created_at' => $now]);
    $db->insert('merchants', ['credit_code' => '91370200MA3YY0001D', 'organization_id' => 1, 'legal_name' => 'Synthetic merchant', 'license_file_id' => $file, 'status' => 'approved', 'created_at' => $now]);
    $db->insert('customers', ['user_id' => $users['consumer']['id'], 'broker_id' => $users['broker']['id'], 'org_id' => 2, 'name' => 'Test customer', 'notes' => '', 'stage' => 'new', 'created_at' => $now, 'updated_at' => $now]);
    return [$users, $file];
}
function publish(Catalog $catalog, $kind, array $body, array $merchant, array $platform) {
    $r = $catalog->save($kind, $body, $merchant);
    return $catalog->save($kind, ['version' => $r['version'], 'status' => 'published'], $platform, $r['id']);
}
function rawOrder(Database $db, $id) { return $db->one('SELECT * FROM orders WHERE id=?', [$id]); }
function paidOrder(Database $db, $resource, array $users, $suffix, $complete = true) {
    $now = time();
    return $db->insert('orders', ['order_no' => 'TEST-' . $suffix, 'type' => 'maintenance', 'customer_id' => $users['consumer']['id'], 'creator_id' => $users['broker']['id'], 'broker_id' => $users['broker']['id'], 'org_id' => 2,
        'resource_id' => $resource, 'status' => $complete ? 'completed' : 'paid', 'amount_cents' => 10000, 'original_amount_cents' => 10000, 'paid_cents' => 10000, 'items' => '[]', 'notes' => '', 'created_at' => $now, 'updated_at' => $now, 'completed_at' => $complete ? $now - 10 : null]);
}

putenv('APP_ENV=test'); putenv('ALLOW_TEST_PAYMENTS=1');
$db = new Database(new PDO('sqlite::memory:')); schema($db); list($u, $fileId) = fixture($db);
$cat = new Catalog($db); $orders = new Orders($db); $finance = new Finance($db); $community = new Community($db);
$platform = $u['platform']; $consumer = $u['consumer']; $broker = $u['broker']; $merchant = $u['merchant'];
$finance->saveSettings(['distribution' => ['broker_bps' => 3000, 'member_bps' => 2000, 'settlement_days' => 0], 'growth' => ['invitation_points' => 7, 'rental_points_per_day' => 3, 'lesson_points' => 10, 'exam_points' => 20]], $platform);
blocked(function () use ($finance, $merchant) { $finance->saveSettings([], $merchant); }, 403, 'Only platform changes settings');
blocked(function () use ($finance, $platform) { $finance->saveSettings(['distribution' => ['broker_bps' => 8000, 'member_bps' => 3000]], $platform); }, 422, 'Total ratio cannot exceed profit');
check(Finance::proportional(100000000000, 3333, 10000) === 33330000000, 'Large money uses integer-safe arithmetic');
$type = publish($cat, 'boat_types', ['title' => 'Synthetic rental type', 'payload' => ['daily_price_cents' => 1000, 'capacity' => 4, 'parameters' => ['length_m' => '8.5']]], $merchant, $platform);
$boats = [];
for ($i=0; $i<2; $i++) $boats[] = publish($cat, 'boats', ['title' => 'Boat ' . $i, 'price_cents' => 10000, 'payload' => ['boat_type_id' => $type['id'], 'serial_number' => 'TEST-BOAT-' . $i]], $merchant, $platform);
$maintenance = publish($cat, 'maintenance', ['title' => 'Synthetic maintenance', 'price_cents' => 10000, 'payload' => ['boat_type_id' => $type['id']]], $merchant, $platform);
check($maintenance['merchant_credit_code'] === '91370200MA3YY0001D', 'Merchant credit code retained across approval');
$dates = ['start_date' => date('Y-m-d', time()+86400), 'end_date' => date('Y-m-d', time()+86400*3)];
$rental = $orders->create(['type' => 'boat_rental', 'resource_id' => $type['id'], 'quantity' => 2] + $dates, $consumer);
check($rental['amount_cents'] === 4000 && count($rental['items'][0]['boat_ids']) === 2, 'Daily rental allocates two instances and server prices');
blocked(function () use ($orders, $type, $dates, $consumer) { $orders->create(['type' => 'boat_rental', 'resource_id' => $type['id']] + $dates, $consumer); }, 409, 'Daily unique inventory prevents overlap');
$orders->action($rental['id'], 'pay', ['channel' => 'test', 'idempotency_key' => 'rental-pay-001'], $consumer);
$orders->action($rental['id'], 'pay', ['channel' => 'test', 'idempotency_key' => 'rental-pay-001'], $consumer);
check((int)$db->scalar('SELECT growth_points FROM users WHERE id=?', [$consumer['id']]) === 6, 'Rental growth granted once');
$orders->action($rental['id'], 'refund', ['channel' => 'test', 'idempotency_key' => 'rental-refund-001', 'amount_cents' => 4000, 'reason' => 'Test refund'], $platform);
check($orders->inventory(['boat_type_id' => $type['id']] + $dates)['available_count'] === 2, 'Full refund releases daily inventory');

$purchase = $orders->create(['type' => 'boat_purchase', 'resource_id' => $boats[0]['id'], 'customer_id' => $consumer['id']], $broker);
blocked(function () use ($orders, $purchase, $merchant) { $orders->get($purchase['id'], $merchant); }, 404, 'Supplier merchant cannot see broker order');
blocked(function () use ($orders, $purchase, $u) { $orders->get($purchase['id'], $u['outsider']); }, 404, 'Other customer cannot see order');
$orders->action($purchase['id'], 'quote', ['amount_cents' => 10000], $broker);
$orders->action($purchase['id'], 'accept-quote', [], $consumer);
blocked(function () use ($orders, $purchase, $consumer) { $orders->action($purchase['id'], 'pay', ['channel' => 'test', 'idempotency_key' => 'purchase-pay-001'], $consumer); }, 409, 'Purchase requires reviewed contract');
$contract = $orders->action($purchase['id'], 'contract', ['file_id' => $fileId, 'title' => 'Synthetic agreement'], $broker);
$orders->action($purchase['id'], 'contract-review', ['version' => 1, 'status' => 'approved'], $platform);
$orders->action($purchase['id'], 'sign', ['version' => 1, 'accepted' => true], $consumer);
$memberBefore = (int)$db->scalar('SELECT member_until FROM users WHERE id=?', [$consumer['id']]);
$orders->action($purchase['id'], 'pay', ['channel' => 'test', 'idempotency_key' => 'purchase-pay-001'], $consumer);
$memberAfter = (int)$db->scalar('SELECT member_until FROM users WHERE id=?', [$consumer['id']]);
$orders->action($purchase['id'], 'pay', ['channel' => 'test', 'idempotency_key' => 'purchase-pay-001'], $consumer);
check($memberAfter > $memberBefore && (int)$db->scalar('SELECT member_until FROM users WHERE id=?', [$consumer['id']]) === $memberAfter, 'Purchase adds six-month membership once');
$legacyCandidate=rawOrder($db,$purchase['id']);unset($legacyCandidate['id']);$legacyCandidate['order_no']='TEST-LEGACY-DUPLICATE-PURCHASE';$legacyCandidate['paid_cents']=0;$legacyCandidate['status']='awaiting_payment';$legacyCandidateId=$db->insert('orders',$legacyCandidate);
$db->update('orders',$purchase['id'],['status'=>'cancelled']); // Synthetic inconsistent historical status, with money still received.
try{$orders->action($legacyCandidateId,'pay',['channel'=>'test','idempotency_key'=>'legacy-duplicate-pay-001'],$consumer);throw new RuntimeException('Duplicate physical purchase payment was allowed');}
catch(ApiError $error){check($error->status===409&&$error->getCode()===40921,'Another paid purchase blocks receipt even with inconsistent historical status');}
check((int)$db->scalar('SELECT COUNT(*) FROM payments WHERE order_id=?',[$legacyCandidateId])===0,'Duplicate physical payment creates no successful receipt');
$db->update('orders',$purchase['id'],['status'=>'paid']);$db->update('orders',$legacyCandidateId,['status'=>'cancelled']);
check($orders->inventory(['boat_type_id' => $type['id']] + $dates)['available_count'] === 1, 'Paid purchase removes the physical boat from rental inventory');
$remainingRental = $orders->create(['type' => 'boat_rental', 'resource_id' => $type['id']] + $dates, $consumer);
check($remainingRental['items'][0]['boat_ids'] === [$boats[1]['id']], 'Rental allocation cannot use the purchased boat');
blocked(function () use ($orders, $boats, $consumer) { $orders->create(['type' => 'boat_purchase', 'resource_id' => $boats[1]['id']], $consumer); }, 409, 'A current or future rented boat cannot be purchased');
$orders->action($remainingRental['id'], 'cancel', [], $consumer);
putenv('APP_ENV=production');
blocked(function () use ($orders, $purchase, $consumer) { $orders->action($purchase['id'], 'pay', ['channel' => 'test', 'idempotency_key' => 'purchase-pay-001'], $consumer); }, 403, 'Old test-payment key cannot report success in production');
putenv('APP_ENV=test');
blocked(function () use ($orders, $boats, $consumer) { $orders->create(['type' => 'boat_purchase', 'resource_id' => $boats[0]['id']], $consumer); }, 409, 'Specific boat cannot be sold twice');

$id = paidOrder($db, $maintenance['id'], $u, 'FINANCE');
$beneficiaries = [['user_id' => $broker['id'], 'role' => 'broker'], ['user_id' => $consumer['id'], 'role' => 'member']];
$confirm = ['profit_cents' => 4000, 'notes' => 'Manual available profit', 'beneficiaries' => $beneficiaries];
$orders->action($id, 'profit-confirm', $confirm, $platform);
check($finance->balance($broker['id'])['available_cents'] === 1200, 'Commission is based on manually confirmed profit');
blocked(function () use ($orders, $id, $confirm, $platform) { $orders->action($id, 'profit-confirm', $confirm, $platform); }, 409, 'Profit confirmation revision cannot repeat');
$withdrawal = $finance->withdraw(['amount_cents' => 1000, 'idempotency_key'=>'withdraw-first-001'], $broker);
check($finance->withdraw(['amount_cents'=>1000,'idempotency_key'=>'withdraw-first-001'],$broker)['id']===$withdrawal['id'],'Withdrawal retry returns original reservation');
blocked(function()use($finance,$broker){$finance->withdraw(['amount_cents'=>999,'idempotency_key'=>'withdraw-first-001'],$broker);},409,'Withdrawal idempotency amount cannot change');
blocked(function()use($finance,$broker){$finance->withdraw(['amount_cents'=>1],$broker);},422,'Withdrawal request requires idempotency key');
$db->update('withdrawals', $withdrawal['id'], ['status' => 'paid']); // Synthetic already-paid fixture, never calls a payout provider.
$request = $orders->action($id, 'refund-request', ['amount_cents' => 2000, 'reason' => 'Customer refund request'], $consumer);
check(rawOrder($db, $id)['profit_status'] === 'confirmed' && $finance->balance($broker['id'])['available_cents'] === 200, 'Refund request does not change money');
blocked(function () use ($orders, $id, $request, $platform) { $orders->action($id, 'refund', ['request_id' => $request['id'], 'channel' => 'wechat', 'idempotency_key' => 'money-refund-001', 'amount_cents' => 2000, 'reason' => 'Test'], $platform); }, 503, 'Missing real refund adapter rejects');
check($db->scalar('SELECT status FROM refund_requests WHERE id=?', [$request['id']]) === 'pending', 'Unconfigured refund retains pending request');
$refundBody = ['request_id' => $request['id'], 'channel' => 'test', 'idempotency_key' => 'money-refund-001', 'amount_cents' => 2000, 'reason' => 'Successful simulated refund'];
$orders->action($id, 'refund', $refundBody, $platform);
check(rawOrder($db, $id)['profit_status'] === 'needs_reconfirmation' && rawOrder($db, $id)['profit_cents'] === null, 'Partial refund clears profit for manual reconfirmation');
$finance->settle();
check((int)$db->scalar('SELECT COUNT(*) FROM ledger WHERE order_id=? AND state=?', [$id, 'frozen']) === 2 && $finance->balance($broker['id'])['available_cents'] === 0, 'Settlement cannot unfreeze refund ledger');
blocked(function () use ($finance, $broker) { $finance->withdraw(['amount_cents' => 1, 'idempotency_key'=>'withdraw-frozen-001'], $broker); }, 409, 'Frozen order cannot fund a withdrawal');
$finance->saveSettings(['distribution' => ['broker_bps' => 1000, 'member_bps' => 1000, 'settlement_days' => 20]], $platform);
$orders->action($id, 'profit-confirm', ['profit_cents' => 2500, 'notes' => 'Reconfirmed profit is not proportional to refund'], $platform);
check((int)$db->scalar('SELECT SUM(amount_cents) FROM ledger WHERE order_id=? AND user_id=? AND state<>?', [$id, $broker['id'], 'cancelled']) === 750, 'Reconfirmed non-proportional profit uses original 30 percent snapshot');
check($finance->balance($broker['id'])['debt_cents'] === 250, 'Already withdrawn shortage becomes debt');
$ledgerCount = (int)$db->scalar('SELECT COUNT(*) FROM ledger WHERE order_id=?', [$id]);
$orders->action($id, 'refund', $refundBody, $platform);
check(rawOrder($db, $id)['profit_status'] === 'confirmed' && (int)$db->scalar('SELECT COUNT(*) FROM ledger WHERE order_id=?', [$id]) === $ledgerCount, 'Refund replay neither refreezes nor duplicates differences');
$second = ['channel' => 'test', 'idempotency_key' => 'money-refund-002', 'amount_cents' => 2000, 'reason' => 'Second partial refund'];
$orders->action($id, 'refund', $second, $platform);
blocked(function () use ($orders, $id, $platform, $u) { $orders->action($id, 'profit-confirm', ['profit_cents' => 1200, 'notes' => 'Cannot change beneficiary', 'beneficiaries' => [['user_id' => $u['outsider']['id'], 'role' => 'member']]], $platform); }, 409, 'Reconfirmation retains beneficiaries');
$orders->action($id, 'profit-confirm', ['profit_cents' => 1200, 'notes' => 'Second manual remaining profit'], $platform);
check((int)$db->scalar('SELECT SUM(amount_cents) FROM ledger WHERE order_id=? AND user_id=? AND state<>?', [$id, $broker['id'], 'cancelled']) === 360, 'Multiple partial refunds use cumulative target differences');
$full = ['channel' => 'test', 'idempotency_key' => 'money-refund-003', 'amount_cents' => 6000, 'reason' => 'Full remaining refund'];
$orders->action($id, 'refund', $full, $platform); $orders->action($id, 'refund', $full, $platform);
check((int)$db->scalar('SELECT SUM(amount_cents) FROM ledger WHERE order_id=? AND user_id=? AND state<>?', [$id, $broker['id'], 'cancelled']) === 0 && $finance->balance($broker['id'])['debt_cents'] === 1000, 'Full refund reverses cumulative awards once and records withdrawn debt');
blocked(function () use ($orders, $id, $full, $platform) { $full['amount_cents']=1; $orders->action($id, 'refund', $full, $platform); }, 409, 'Idempotency key cannot change refund amount');
$pendingId = paidOrder($db, $maintenance['id'], $u, 'PENDING', false);
$orders->action($pendingId, 'profit-confirm', ['profit_cents' => 2000, 'notes' => 'Pending commission', 'beneficiaries' => $beneficiaries], $platform);
$orders->action($pendingId, 'refund', ['channel' => 'test', 'idempotency_key' => 'pending-refund-001', 'amount_cents' => 10000, 'reason' => 'Full refund before settlement'], $platform);
check((int)$db->scalar('SELECT COUNT(*) FROM ledger WHERE order_id=? AND state<>?', [$pendingId, 'cancelled']) === 0, 'Full refund cancels all unsettled ledger entries');
check($finance->ledger($platform, [])['total'] > $finance->ledger($u['agency'], [])['total'], 'Platform sees all ledger while agency sees its own brokers');
blocked(function () use ($finance, $merchant, $broker) { $finance->ledger($merchant, ['user_id' => $broker['id']]); }, 403, 'Merchant cannot request another user ledger');
$admin = new Admin($db);
$admin->updateUser($broker['id'], ['nickname' => 'Agency editable broker'], $u['agency']);
blocked(function () use ($admin, $broker, $u) { $admin->updateUser($broker['id'], ['role' => 'platform'], $u['agency']); }, 403, 'Agency cannot elevate a sales account');
blocked(function () use ($admin, $u) { $admin->updateUser($u['otherbroker']['id'], ['nickname' => 'Out of scope'], $u['agency']); }, 403, 'Agency cannot edit another company broker');

$product1 = $cat->save('products', ['title' => 'Product one', 'price_cents' => 800, 'payload' => ['stock' => 3]], $platform);
$product2 = $cat->save('products', ['title' => 'Product two', 'price_cents' => 1200, 'payload' => ['stock' => 2]], $platform);
$community->cartSave(['product_id' => $product1['id'], 'quantity' => 2], $consumer);
$mall = $orders->create(['type' => 'mall', 'items' => [['resource_id' => $product1['id'], 'quantity' => 2], ['resource_id' => $product2['id'], 'quantity' => 1]], 'amount_cents' => 1], $consumer);
check($mall['amount_cents'] === 2800 && count($mall['items']) === 2 && $community->cart([], $consumer)['total'] === 0, 'Atomic multi-product checkout ignores client price and removes actual cart items');
$beforeStock = $cat->get($product1['id'])['payload']['stock'];
blocked(function () use ($orders, $product1, $product2, $consumer) { $orders->create(['type' => 'mall', 'items' => [['resource_id' => $product1['id'], 'quantity' => 1], ['resource_id' => $product2['id'], 'quantity' => 50]]], $consumer); }, 409, 'Checkout rejects insufficient stock');
check($cat->get($product1['id'])['payload']['stock'] === $beforeStock, 'Checkout failure rolls back earlier stock deduction');
$orders->action($mall['id'], 'cancel', [], $consumer);
check($cat->get($product1['id'])['payload']['stock'] === 3, 'Cancellation restores typed product stock');

$event = $cat->save('events', ['title' => 'Joint test event', 'payload' => ['capacity' => 1, 'starts_at' => date('Y-m-d H:i:s', time()+86400), 'joint_merchant_codes' => ['91370200MA3YY0001D']]], $platform);
check($event['status'] === 'pending', 'Joint platform event waits for named merchant approval');
blocked(function () use ($community, $event, $platform) { $community->reviewEvent($event['id'], ['status' => 'approved'], $platform); }, 403, 'Joint approval is restricted to designated merchants');
$community->reviewEvent($event['id'], ['status' => 'approved'], $merchant);
$enrollment = $community->enroll($event['id'], $consumer)['enrollment'];
blocked(function () use ($community, $event, $u) { $community->enroll($event['id'], $u['outsider']); }, 409, 'Event capacity is enforced');
$community->checkIn($enrollment['id'], ['ticket_code' => $enrollment['ticket_code']], $merchant);
blocked(function () use ($community, $enrollment, $merchant) { $community->checkIn($enrollment['id'], ['ticket_code' => $enrollment['ticket_code']], $merchant); }, 409, 'Ticket can only be checked in once');

$coupon = $cat->save('coupons', ['title' => 'Maintenance reward', 'payload' => ['discount_cents' => 200, 'stock' => 5, 'expires_at' => date('Y-m-d H:i:s', time()+86400*10), 'order_types' => ['maintenance']]], $platform);
$course = $cat->save('courses', ['title' => 'Free test course', 'price_cents' => 999, 'payload' => ['lessons' => [['id' => 'L1', 'title' => 'Lesson', 'duration_seconds' => 100]], 'questions' => [['question' => 'Test?', 'options' => ['Correct', 'Wrong'], 'answer' => 0]], 'rewards' => [
    ['id' => 'badge', 'type' => 'badge', 'title' => 'Course badge'], ['id' => 'growth', 'type' => 'growth', 'title' => 'Course growth', 'points' => 12], ['id' => 'maint', 'type' => 'maintenance_coupon', 'title' => 'Maintenance coupon', 'coupon_id' => $coupon['id']], ['id' => 'gift', 'type' => 'physical_gift', 'title' => 'Physical gift'], ['id' => 'invite', 'type' => 'event_invitation', 'title' => 'Activity invitation', 'event_id' => $event['id']]]]], $platform);
check($course['price_cents'] === 0 && !isset($cat->get($course['id'])['payload']['questions'][0]['answer']), 'Course remains free and public API hides answers');
$progress = $community->progress(['course_id' => $course['id'], 'lesson_id' => 'L1', 'position_seconds' => 90], $consumer);
check(count($progress['rewards']) === 0, 'Reward waits for required exam');
$exam = $community->exam(['course_id' => $course['id'], 'answers' => [0]], $consumer);
check((int)$exam['passed'] === 1 && count($exam['rewards']) === 5, 'Completed free course issues all configured reward types');
$rewardCount = $community->rewardList([], $consumer)['total']; $stock = $cat->get($coupon['id'])['payload']['stock'];
$community->exam(['course_id' => $course['id'], 'answers' => [0]], $consumer);
$community->progress(['course_id' => $course['id'], 'lesson_id' => 'L1', 'position_seconds' => 95], $consumer);
check($community->rewardList([], $consumer)['total'] === $rewardCount && $cat->get($coupon['id'])['payload']['stock'] === $stock, 'Learning reward retries do not duplicate grants or consume extra coupon stock');
$gift = $db->one('SELECT * FROM course_reward_claims WHERE user_id=? AND status=?', [$consumer['id'], 'pending_fulfillment']);
$invite = $db->one('SELECT * FROM course_reward_claims WHERE user_id=? AND status=?', [$consumer['id'], 'eligible']);
check($gift && $invite && (int)$db->scalar('SELECT COUNT(*) FROM enrollments') === 1, 'Physical gift is pending and activity invitation does not enroll automatically');
blocked(function () use ($community, $gift, $consumer) { $community->fulfillReward($gift['id'], ['notes' => 'Not authorized'], $consumer); }, 403, 'Only platform confirms real gift fulfillment');
$community->fulfillReward($gift['id'], ['notes' => 'Synthetic shipment confirmation'], $platform);
blocked(function () use ($community, $gift, $platform) { $community->fulfillReward($gift['id'], ['notes' => 'Duplicate'], $platform); }, 409, 'Gift fulfillment cannot repeat');
$auth = new Auth($db); $code = $auth->invitation($consumer)['invitation_code'];
$growthBefore = (int)$db->scalar('SELECT growth_points FROM users WHERE id=?', [$consumer['id']]);
$auth->register(['username' => 'new_invited', 'password' => 'Synthetic-test-1234', 'invitation_code' => $code], 'fixture-invited');
$uninvited = $auth->register(['username' => 'new_uninvited', 'password' => 'Synthetic-test-1234'], 'fixture-uninvited');
check((int)$db->scalar('SELECT growth_points FROM users WHERE id=?', [$consumer['id']]) === $growthBefore + 7 && $db->scalar('SELECT referrer_id FROM invitations WHERE user_id=?', [$uninvited['user']['id']]) === null, 'Only an explicitly filled valid invitation adds inviter growth');
blocked(function () use ($auth) { $auth->register(['username' => 'invalid_invite', 'password' => 'Synthetic-test-1234', 'invitation_code' => 'BADCODE'], 'fixture-bad-code'); }, 422, 'Invalid invitation does not silently register');
check(!$db->one('SELECT id FROM users WHERE username=?', ['invalid_invite']), 'Invalid invitation transaction creates no account');

check(app\api\controller\Index::parseJsonBody('', 'GET') === [] && app\api\controller\Index::parseJsonBody('', 'DELETE') === [], 'Empty GET/DELETE accepts JSON header');
blocked(function () { app\api\controller\Index::parseJsonBody('', 'POST'); }, 422, 'Empty POST JSON remains invalid');
blocked(function () { app\api\controller\Index::parseJsonBody('{bad', 'GET'); }, 422, 'Malformed nonempty JSON remains invalid');
check(count($db->all('PRAGMA foreign_key_check')) === 0, 'All fixture domain relationships satisfy foreign keys');
check($orders->listing($consumer, ['search' => 'THIS-ORDER-DOES-NOT-EXIST'])['total'] === 0, 'Order search is actually applied within customer scope');

if (function_exists('pcntl_fork')) {
    $path = tempnam(sys_get_temp_dir(), 'yuzhou-withdraw-'); $parallel = new Database(new PDO('sqlite:' . $path)); schema($parallel); list($pu, $pf) = fixture($parallel);
    $res = (new Catalog($parallel))->save('products', ['title' => 'Parallel fixture', 'price_cents' => 1000, 'payload' => ['stock' => 1]], $pu['platform']);
    $orderId = paidOrder($parallel, $res['id'], $pu, 'PARALLEL');
    $parallel->insert('ledger', ['user_id' => $pu['broker']['id'], 'order_id' => $orderId, 'beneficiary_role' => 'broker', 'entry_key' => 'parallel-balance', 'amount_cents' => 1000, 'state' => 'available', 'available_at' => time()-1, 'reversal_of' => null, 'created_at' => time()]);
    $children=[];
    for ($i=0; $i<2; $i++) {
        $pid=pcntl_fork();
        if ($pid===0) { try { $childDb=new Database(new PDO('sqlite:' . $path)); (new Finance($childDb))->withdraw(['amount_cents'=>600,'idempotency_key'=>'parallel-withdraw-'.$i], $pu['broker']); exit(0); } catch (ApiError $e) { exit($e->status===409 ? 2 : 3); } catch (Throwable $e) { exit(4); } }
        $children[]=$pid;
    }
    $results=[]; foreach ($children as $pid) { pcntl_waitpid($pid, $status); $results[]=pcntl_wexitstatus($status); }
    sort($results);
    check($results === [0,2] && (int)$parallel->scalar('SELECT SUM(amount_cents) FROM withdrawals')===600, 'Concurrent withdrawals reserve once and cannot overdraw');
    $parallel->insert('ledger',['user_id'=>$pu['broker']['id'],'order_id'=>$orderId,'beneficiary_role'=>'broker','entry_key'=>'parallel-same-key-balance','amount_cents'=>1000,'state'=>'available','available_at'=>time()-1,'reversal_of'=>null,'created_at'=>time()]);
    $children=[];
    for($i=0;$i<2;$i++) {
        $pid=pcntl_fork();
        if($pid===0) {try {$childDb=new Database(new PDO('sqlite:'.$path));(new Finance($childDb))->withdraw(['amount_cents'=>600,'idempotency_key'=>'parallel-withdraw-same'],$pu['broker']);exit(0);}catch(Throwable $e){exit(4);}}
        $children[]=$pid;
    }
    $results=[];foreach($children as $pid){pcntl_waitpid($pid,$status);$results[]=pcntl_wexitstatus($status);}sort($results);
    check($results===[0,0]&&(int)$parallel->scalar('SELECT COUNT(*) FROM withdrawals')===2&&(new Finance($parallel))->balance($pu['broker']['id'])['available_cents']===800,'Concurrent same-key withdrawal reserves once');
    unset($parallel); unlink($path);
} else throw new RuntimeException('Concurrent withdrawal test requires pcntl.');
echo 'Core backend checks: ' . $checks . ' passed.' . PHP_EOL;
