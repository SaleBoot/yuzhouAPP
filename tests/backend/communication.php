<?php
require_once __DIR__ . '/../../backend/extend/yuzhou/bootstrap.php';

use yuzhou\Database;
use yuzhou\Communication;
use yuzhou\ApiError;

function runCommunicationChecks()
{
    $db = new Database(new PDO('sqlite::memory:'));
    $db->pdo->exec(file_get_contents(__DIR__ . '/../../database/sqlite.sql'));
    $db->pdo->exec(file_get_contents(__DIR__ . '/../../database/communication-sqlite.sql'));
    foreach (glob(__DIR__ . '/../../database/migrations/*-sqlite.sql') as $migration) $db->pdo->exec(file_get_contents($migration));
    $now = time();
    foreach ([['merchant', '示例商家'], ['agency', '示例经纪公司']] as $row) $db->insert('organizations', ['name' => $row[1], 'kind' => $row[0], 'status' => 'approved', 'payload' => '{}', 'created_at' => $now]);
    $users = [];
    foreach ([['platform', null], ['consumer', null], ['broker', 2], ['merchant', 1], ['consumer', null]] as $index => $role) {
        $id = $db->insert('users', ['username' => 'communication_test_' . $index, 'password_hash' => password_hash('unused-test-password', PASSWORD_DEFAULT), 'nickname' => '测试用户' . $index, 'role' => $role[0], 'org_id' => $role[1], 'created_at' => $now, 'updated_at' => $now]);
        $users[] = $db->one('SELECT * FROM users WHERE id=?', [$id]);
    }
    $resource = $db->insert('resources', ['kind' => 'posts', 'org_id' => 1, 'owner_id' => 4, 'title' => '测试咨询资料', 'status' => 'published', 'payload' => '{}', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $order = $db->insert('orders', ['order_no' => 'COMM-TEST', 'type' => 'boat_purchase', 'customer_id' => 2, 'creator_id' => 3, 'broker_id' => 3, 'org_id' => 2, 'resource_id' => $resource, 'status' => 'completed', 'amount_cents' => 10000, 'original_amount_cents' => 10000, 'items' => '[]', 'notes' => '', 'created_at' => $now, 'updated_at' => $now]);
    $service = new Communication($db); $checks = 0;
    $check = function ($condition, $label) use (&$checks) { if (!$condition) throw new RuntimeException($label); $checks++; };
    $denied = function ($callback, $label) use ($check) { try { $callback(); } catch (ApiError $e) { $check(in_array($e->status, [401, 403, 404, 409, 422], true), $label); return; } throw new RuntimeException($label); };
    $call = function ($method, $path, $body, $user) use ($service) { return $service->dispatch($method, $path, $body, [], $user); };
    $conversation = $call('POST', 'conversations', ['order_id' => $order], $users[1]);
    $check((int)$conversation['id'] > 0, '订单参与者创建会话');
    $repeat = $call('POST', 'conversations', ['order_id' => $order], $users[2]);
    $check((int)$repeat['id'] === (int)$conversation['id'], '重复创建复用原会话');
    $mallOrder = $db->insert('orders', ['order_no' => 'COMM-MALL', 'type' => 'mall', 'customer_id' => 2, 'creator_id' => 2, 'org_id' => null, 'resource_id' => $resource, 'status' => 'completed', 'amount_cents' => 10000, 'original_amount_cents' => 10000, 'items' => '[]', 'notes' => '', 'created_at' => $now, 'updated_at' => $now]);
    $mallConversation = $call('POST', 'conversations', ['order_id' => $mallOrder], $users[1]);
    $check($db->scalar('SELECT COUNT(*) FROM conversation_members WHERE conversation_id=? AND user_id=?', [$mallConversation['id'], $users[0]['id']]) == 1, '平台商城客服使用平台账号，无组织占位外键');
    $denied(function () use ($call, $order, $users) { $call('POST', 'conversations', ['order_id' => $order], $users[3]); }, '商家不能创建经纪订单会话');
    $path = 'conversations/' . $conversation['id'] . '/messages';
    $message = $call('POST', $path, ['client_id' => 'message-test-001', 'content' => '带看时间已确认'], $users[1]);
    $same = $call('POST', $path, ['client_id' => 'message-test-001', 'content' => '带看时间已确认'], $users[1]);
    $check($message['id'] === $same['id'], '消息重试幂等');
    $denied(function () use ($call, $path, $users) { $call('POST', $path, ['client_id' => 'message-test-001', 'content' => '变更内容'], $users[1]); }, '幂等键不能换内容');
    $denied(function () use ($call, $path, $users) { $call('GET', $path, [], $users[4]); }, '陌生账号不能读取私聊');
    $denied(function () use ($call, $path) { $call('GET', $path, [], null); }, '匿名不能读取私聊');
    $list = $call('GET', 'conversations', [], $users[2]);
    $check($list['items'][0]['unread_count'] === 1, '接收方未读计数');
    $call('POST', 'conversations/' . $conversation['id'] . '/read', [], $users[2]);
    $check($call('GET', 'conversations', [], $users[2])['items'][0]['unread_count'] === 0, '已读计数归零');
    $check($call('GET', 'notifications', [], $users[2])['total'] === 1, '站内消息持久化');
    $feedback = $call('POST', 'feedback', ['payload' => ['content' => '测试反馈']], $users[1]);
    $check($call('GET', 'feedback', [], $users[4])['total'] === 0, '其他用户不能查看反馈');
    $check($call('GET', 'feedback', [], $users[0])['total'] === 1, '平台可处理反馈');
    $denied(function () use ($call, $feedback, $users) { $call('POST', 'feedback/' . $feedback['id'] . '/reply', ['reply' => '越权回复'], $users[3]); }, '反馈只允许平台回复');
    $reply = $call('POST', 'feedback/' . $feedback['id'] . '/reply', ['reply' => '已确认并处理'], $users[0]);
    $check($reply['status'] === 'handled' && $reply['payload']['reply'] === '已确认并处理', '回复与处理状态持久化');
    $noticeCount = $call('GET', 'notifications', [], $users[1])['total'];
    $call('POST', 'feedback/' . $feedback['id'] . '/reply', ['reply' => '已确认并处理'], $users[0]);
    $check($call('GET', 'notifications', [], $users[1])['total'] === $noticeCount, '相同回复重试不重复通知');
    $call('POST', 'reviews', ['order_id' => $order, 'payload' => ['rating' => 5, 'content' => '测试履约评价']], $users[1]);
    $denied(function () use ($call, $order, $users) { $call('POST', 'reviews', ['order_id' => $order, 'payload' => ['rating' => 5, 'content' => '重复评价']], $users[1]); }, '一单一次评价');
    $favorite = $call('POST', 'favorites', ['resource_id' => $resource], $users[1]);
    $denied(function () use ($call, $favorite, $users) { $call('DELETE', 'favorites/' . $favorite['id'], [], $users[4]); }, '不能删除他人收藏');
    $reminder = $call('POST', 'reminders', ['title' => '演示年检', 'payload' => ['due_date' => date('Y-m-d')]], $users[1]);
    $check($service->sendDueReminders() === 1, '到期提醒生成站内消息');
    $check($service->sendDueReminders() === 0, '定时任务重试不重复提醒');
    $call('PATCH', 'reminders/' . $reminder['id'], ['payload' => ['due_date' => date('Y-m-d')]], $users[1]);
    $check($service->sendDueReminders() === 0, '修改其他内容不重复当天提醒');
    $call('PATCH', 'reminders/' . $reminder['id'], ['payload' => ['due_date' => date('Y-m-d', time() - 86400)]], $users[1]);
    $check($service->sendDueReminders() === 1, '新的到期日单独通知');
    $futureReminder = $call('POST', 'reminders', ['title' => '演示保养', 'payload' => ['due_date' => date('Y-m-d', time() + 86400)]], $users[1]);
    $check($service->sendDueReminders() === 0, '未来日期不提前通知');
    $call('DELETE', 'reminders/' . $futureReminder['id'], [], $users[1]);
    $check($db->scalar('SELECT COUNT(*) FROM reminders WHERE record_id=?', [$futureReminder['id']]) == 1, '删除保留关系记录，状态屏蔽通知');
    $check(count($db->all('PRAGMA foreign_key_check')) === 0, '补充数据表外键有效');
    try { $db->insert('messages', ['conversation_id' => $conversation['id'], 'sender_id' => 999999, 'client_id' => 'invalid-sender', 'content' => '无效', 'created_at' => $now]); throw new RuntimeException('发送者外键缺失'); }
    catch (PDOException $e) { $check(true, '消息发送者必须存在'); }
    echo 'Communication checks: ' . $checks . ' passed.' . PHP_EOL;
    return $checks;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) runCommunicationChecks();
