<?php
session_start();

define('DB_FILE', __DIR__ . '/chat.db');
define('POLL_INTERVAL', 2000);

try {
    $db = new SQLite3(DB_FILE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nick TEXT UNIQUE NOT NULL,
        color TEXT NOT NULL,
        last_active INTEGER NOT NULL
    )');
    
    $db->exec('CREATE TABLE IF NOT EXISTS channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        topic TEXT DEFAULT "",
        created_by INTEGER,
        created_at INTEGER NOT NULL
    )');
    
    $db->exec('CREATE TABLE IF NOT EXISTS channel_members (
        channel_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        mode TEXT DEFAULT "",
        joined_at INTEGER NOT NULL,
        PRIMARY KEY (channel_id, user_id)
    )');
    
    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_id INTEGER,
        from_user INTEGER,
        to_user INTEGER,
        msg_type TEXT NOT NULL DEFAULT "msg",
        content TEXT NOT NULL,
        timestamp INTEGER NOT NULL
    )');
    
    $stmt = $db->prepare('SELECT id FROM channels WHERE name = ?');
    $stmt->bindValue(1, '#lobby', SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result->fetchArray()) {
        $stmt = $db->prepare('INSERT INTO channels (name, topic, created_at) VALUES (?, ?, ?)');
        $stmt->bindValue(1, '#lobby', SQLITE3_TEXT);
        $stmt->bindValue(2, 'Welcome to the lobby', SQLITE3_TEXT);
        $stmt->bindValue(3, time(), SQLITE3_INTEGER);
        $stmt->execute();
    }
} catch (Exception $e) {
    die(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
}

function respond($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function generateColor($nick) {
    $hash = crc32(strtolower($nick));
    $hue = abs($hash) % 360;
    return "hsl($hue, 65%, 60%)";
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getUser($userId) {
    global $db;
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

function getUserByNick($nick) {
    global $db;
    $stmt = $db->prepare('SELECT * FROM users WHERE nick = ?');
    $stmt->bindValue(1, $nick, SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

function getChannel($name) {
    global $db;
    $stmt = $db->prepare('SELECT * FROM channels WHERE name = ?');
    $stmt->bindValue(1, strtolower($name), SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

function isMember($channelId, $userId) {
    global $db;
    $stmt = $db->prepare('SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ?');
    $stmt->bindValue(1, $channelId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
    return (bool)$stmt->execute()->fetchArray();
}

function getMemberMode($channelId, $userId) {
    global $db;
    $stmt = $db->prepare('SELECT mode FROM channel_members WHERE channel_id = ? AND user_id = ?');
    $stmt->bindValue(1, $channelId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['mode'] : '';
}

function addMessage($channelId, $fromUser, $toUser, $type, $content) {
    global $db;
    $stmt = $db->prepare('INSERT INTO messages (channel_id, from_user, to_user, msg_type, content, timestamp) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bindValue(1, $channelId, SQLITE3_INTEGER);
    $stmt->bindValue(2, $fromUser, SQLITE3_INTEGER);
    $stmt->bindValue(3, $toUser, SQLITE3_INTEGER);
    $stmt->bindValue(4, $type, SQLITE3_TEXT);
    $stmt->bindValue(5, $content, SQLITE3_TEXT);
    $stmt->bindValue(6, time(), SQLITE3_INTEGER);
    $stmt->execute();
    return $db->lastInsertRowID();
}
function cleanupStaleUsers($db, $timeoutSeconds = 60) {
    $cutoff = time() - $timeoutSeconds;
    
    $stmt = $db->prepare('SELECT id, nick FROM users WHERE last_active < ? AND last_active > 0');
    $stmt->bindValue(1, $cutoff, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $userId = $row['id'];
        $nick = $row['nick'];
        
        // Send timeout message to all channels they were in
        $stmt2 = $db->prepare('SELECT channel_id FROM channel_members WHERE user_id = ?');
        $stmt2->bindValue(1, $userId, SQLITE3_INTEGER);
        $result2 = $stmt2->execute();
        while ($chanRow = $result2->fetchArray(SQLITE3_ASSOC)) {
            systemMessage($chanRow['channel_id'], "$nick has timed out");
        }
        
        // Remove from all channels
        $stmt3 = $db->prepare('DELETE FROM channel_members WHERE user_id = ?');
        $stmt3->bindValue(1, $userId, SQLITE3_INTEGER);
        $stmt3->execute();
        
        // Mark user as fully inactive (set last_active to 0 so we don't re-process)
        $stmt4 = $db->prepare('UPDATE users SET last_active = 0 WHERE id = ?');
        $stmt4->bindValue(1, $userId, SQLITE3_INTEGER);
        $stmt4->execute();
    }
}

function systemMessage($channelId, $content) {
    return addMessage($channelId, null, null, 'system', $content);
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        $nick = trim($_POST['nick'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_\-\[\]\\^{}|`]{1,20}$/', $nick)) {
            respond(['error' => 'Invalid nickname. Use 1-20 characters.']);
        }
        
        // Clean up timed-out users first
        cleanupStaleUsers($db, 60);
        
        $existingUser = getUserByNick($nick);
        
        if ($existingUser) {
            // Check if user is still actively connected (active within last 60 seconds)
            if ($existingUser['last_active'] > time() - 60) {
                respond(['error' => 'Nickname is currently in use']);
            }
            // User timed out or quit — reactivate their account
            $userId = $existingUser['id'];
            $stmt = $db->prepare('UPDATE users SET last_active = ? WHERE id = ?');
            $stmt->bindValue(1, time(), SQLITE3_INTEGER);
            $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
            $stmt->execute();
        } else {
            // Brand new user — create them
            $stmt = $db->prepare('INSERT INTO users (nick, color, last_active) VALUES (?, ?, ?)');
            $stmt->bindValue(1, $nick, SQLITE3_TEXT);
            $stmt->bindValue(2, generateColor($nick), SQLITE3_TEXT);
            $stmt->bindValue(3, time(), SQLITE3_INTEGER);
            $stmt->execute();
            $userId = $db->lastInsertRowID();
        }
        
        $_SESSION['user_id'] = $userId;
        
        // Join lobby
        $lobby = getChannel('#lobby');
        $stmt = $db->prepare('INSERT OR IGNORE INTO channel_members (channel_id, user_id, joined_at) VALUES (?, ?, ?)');
        $stmt->bindValue(1, $lobby['id'], SQLITE3_INTEGER);
        $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
        $stmt->bindValue(3, time(), SQLITE3_INTEGER);
        $stmt->execute();
        
        systemMessage($lobby['id'], "$nick has joined #lobby");
        respond(['success' => true, 'user_id' => $userId, 'nick' => $nick]);
        break;
    
    case 'logout':
        $userId = getCurrentUserId();
        if ($userId) {
            $user = getUser($userId);
            $stmt = $db->prepare('SELECT channel_id FROM channel_members WHERE user_id = ?');
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                systemMessage($row['channel_id'], $user['nick'] . ' has quit');
            }
            $db->exec("DELETE FROM channel_members WHERE user_id = $userId");
        }
        session_destroy();
        respond(['success' => true]);
        break;
    
    case 'state':
        $userId = getCurrentUserId();
        if (!$userId) respond(['error' => 'Not logged in']);
        
        // Clean up stale/timed-out users on every poll
        cleanupStaleUsers($db, 60);
        
        $user = getUser($userId);
        $db->exec("UPDATE users SET last_active = " . time() . " WHERE id = $userId");        
        $stmt = $db->prepare('SELECT c.id, c.name, c.topic FROM channels c JOIN channel_members cm ON cm.channel_id = c.id WHERE cm.user_id = ? ORDER BY c.name');
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $channels = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) $channels[] = $row;
        
        $lastSeenPm = $_SESSION['last_seen_pm'] ?? [];
        $pms = [];
        $stmt = $db->prepare('SELECT DISTINCT m.from_user, u.nick FROM messages m JOIN users u ON u.id = m.from_user WHERE m.to_user = ? AND m.msg_type = "pm"');
        $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
        $res = $stmt->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $senderNick = $row['nick'];
            $senderId = $row['from_user'];
            $lastSeenId = $lastSeenPm[$senderNick] ?? 0;
            
            $stmt2 = $db->prepare('SELECT COUNT(*) as cnt, MAX(id) as max_id FROM messages WHERE from_user = ? AND to_user = ? AND msg_type = "pm" AND id > ?');
            $stmt2->bindValue(1, $senderId, SQLITE3_INTEGER);
            $stmt2->bindValue(2, $userId, SQLITE3_INTEGER);
            $stmt2->bindValue(3, $lastSeenId, SQLITE3_INTEGER);
            $row2 = $stmt2->execute()->fetchArray(SQLITE3_ASSOC);
            $cnt = $row2['cnt'] ?? 0;
            $maxId = $row2['max_id'] ?? 0;
            
            if ($cnt > 0) {
                $pms[] = ['nick' => $senderNick, 'cnt' => $cnt, 'max_id' => $maxId];
            }
        }
        
        respond([
            'user' => $user,
            'channels' => $channels,
            'active_channel' => $_SESSION['active_channel'] ?? ($channels[0]['name'] ?? '#lobby'),
            'unread_pms' => $pms
        ]);
        break;
    
    case 'set_active':
        $userId = getCurrentUserId();
        if (!$userId) respond(['error' => 'Not logged in']);
        
        $channelName = $_POST['channel'] ?? '';
        $_SESSION['active_channel'] = $channelName;
        
        if (strpos($channelName, '@pm_') === 0) {
            $targetNick = substr($channelName, 4);
            if (!isset($_SESSION['last_seen_pm'])) $_SESSION['last_seen_pm'] = [];
            $target = getUserByNick($targetNick);
            if ($target) {
                $stmt = $db->prepare('SELECT MAX(id) as max_id FROM messages WHERE msg_type = "pm" AND ((from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?))');
                $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
                $stmt->bindValue(2, $target['id'], SQLITE3_INTEGER);
                $stmt->bindValue(3, $target['id'], SQLITE3_INTEGER);
                $stmt->bindValue(4, $userId, SQLITE3_INTEGER);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                if ($row['max_id']) {
                    $_SESSION['last_seen_pm'][$targetNick] = $row['max_id'];
                }
            }
        }
        respond(['success' => true]);
        break;
    
    case 'messages':
        $userId = getCurrentUserId();
        if (!$userId) respond(['error' => 'Not logged in']);
        
        $channelName = $_POST['channel'] ?? '';
        $sinceId = (int)($_POST['since'] ?? 0);
        
        if (strpos($channelName, '@pm_') === 0) {
            $targetNick = substr($channelName, 4);
            $target = getUserByNick($targetNick);
            if (!$target) respond(['error' => 'User not found']);
            
            $stmt = $db->prepare('SELECT m.*, u.nick as from_nick, u.color as from_color, t.nick as to_nick 
                FROM messages m 
                LEFT JOIN users u ON u.id = m.from_user 
                LEFT JOIN users t ON t.id = m.to_user 
                WHERE ((m.from_user = ? AND m.to_user = ?) OR (m.from_user = ? AND m.to_user = ?)) AND m.msg_type = "pm" AND m.id > ? 
                ORDER BY m.id ASC LIMIT 100');
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $stmt->bindValue(2, $target['id'], SQLITE3_INTEGER);
            $stmt->bindValue(3, $target['id'], SQLITE3_INTEGER);
            $stmt->bindValue(4, $userId, SQLITE3_INTEGER);
            $stmt->bindValue(5, $sinceId, SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            $messages = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $messages[] = $row;
            
            if (count($messages) > 0) {
                $maxId = $messages[count($messages) - 1]['id'];
                if (!isset($_SESSION['last_seen_pm'])) $_SESSION['last_seen_pm'] = [];
                if (!isset($_SESSION['last_seen_pm'][$targetNick]) || $maxId > $_SESSION['last_seen_pm'][$targetNick]) {
                    $_SESSION['last_seen_pm'][$targetNick] = $maxId;
                }
            }
            respond(['messages' => $messages]);
        } else {
            $channel = getChannel($channelName);
            if (!$channel) respond(['error' => 'Channel not found']);
            
            $stmt = $db->prepare('SELECT m.*, u.nick as from_nick, u.color as from_color 
                FROM messages m 
                LEFT JOIN users u ON u.id = m.from_user 
                WHERE m.channel_id = ? AND m.id > ? 
                ORDER BY m.id ASC LIMIT 100');
            $stmt->bindValue(1, $channel['id'], SQLITE3_INTEGER);
            $stmt->bindValue(2, $sinceId, SQLITE3_INTEGER);
            
            $result = $stmt->execute();
            $messages = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $messages[] = $row;
            respond(['messages' => $messages]);
        }
        break;
    
    case 'users':
        $userId = getCurrentUserId();
        if (!$userId) respond(['error' => 'Not logged in']);
        
        $channelName = $_POST['channel'] ?? '';
        $channel = getChannel($channelName);
        if (!$channel) respond(['error' => 'Channel not found']);
        
        $stmt = $db->prepare('SELECT u.id, u.nick, u.color, cm.mode FROM users u JOIN channel_members cm ON cm.user_id = u.id WHERE cm.channel_id = ? ORDER BY u.nick');
        $stmt->bindValue(1, $channel['id'], SQLITE3_INTEGER);
        $result = $stmt->execute();
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) $users[] = $row;
        respond(['users' => $users, 'topic' => $channel['topic']]);
        break;
    
    case 'send':
        $userId = getCurrentUserId();
        if (!$userId) respond(['error' => 'Not logged in']);
        
        $text = trim($_POST['text'] ?? '');
        if ($text === '') respond(['success' => true]);
        
        if (substr($text, 0, 1) === '/') {
            respond(handleCommand($userId, $text));
        }
        
        $activeChannel = $_SESSION['active_channel'] ?? '#lobby';
        if (strpos($activeChannel, '@pm_') === 0) {
            $targetNick = substr($activeChannel, 4);
            $target = getUserByNick($targetNick);
            if (!$target) respond(['error' => 'User not found']);
            addMessage(null, $userId, $target['id'], 'pm', $text);
            respond(['success' => true]);
        } else {
            $channel = getChannel($activeChannel);
            if (!$channel) respond(['error' => 'Channel not found']);
            if (!isMember($channel['id'], $userId)) respond(['error' => 'You are not in this channel']);
            addMessage($channel['id'], $userId, null, 'msg', $text);
            respond(['success' => true]);
        }
        break;
}

function handleCommand($userId, $text) {
    global $db;
    $parts = preg_split('/\s+/', trim($text));
    $cmd = strtolower(substr($parts[0], 1));
    $args = array_slice($parts, 1);
    $arg1 = $args[0] ?? '';
    $rest = implode(' ', array_slice($args, 1));
    $user = getUser($userId);
    $activeChannel = $_SESSION['active_channel'] ?? '#lobby';
    
    switch ($cmd) {
        case 'join':
            if (!$arg1) return ['error' => 'Usage: /join #channel'];
            $name = strtolower($arg1);
            if (substr($name, 0, 1) !== '#') $name = '#' . $name;
            $channel = getChannel($name);
            if (!$channel) {
                $stmt = $db->prepare('INSERT INTO channels (name, topic, created_by, created_at) VALUES (?, "", ?, ?)');
                $stmt->bindValue(1, $name, SQLITE3_TEXT);
                $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
                $stmt->bindValue(3, time(), SQLITE3_INTEGER);
                $stmt->execute();
                $channel = getChannel($name);
                systemMessage($channel['id'], "Channel $name created by " . $user['nick']);
            }
            if (!isMember($channel['id'], $userId)) {
                $stmt = $db->prepare('INSERT INTO channel_members (channel_id, user_id, joined_at) VALUES (?, ?, ?)');
                $stmt->bindValue(1, $channel['id'], SQLITE3_INTEGER);
                $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
                $stmt->bindValue(3, time(), SQLITE3_INTEGER);
                $stmt->execute();
                systemMessage($channel['id'], $user['nick'] . " has joined " . $channel['name']);
            }
            $_SESSION['active_channel'] = $channel['name'];
            return ['success' => true, 'switch_channel' => $channel['name']];
        case 'part':
            $channelName = $arg1 ? strtolower($arg1) : $activeChannel;
            if (strpos($channelName, '@pm_') === 0) return ['error' => 'Cannot part a PM tab, just close it.'];
            $channel = getChannel($channelName);
            if (!$channel) return ['error' => 'Channel not found'];
            if (!isMember($channel['id'], $userId)) return ['error' => 'You are not in this channel'];
            $stmt = $db->prepare('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?');
            $stmt->bindValue(1, $channel['id'], SQLITE3_INTEGER);
            $stmt->bindValue(2, $userId, SQLITE3_INTEGER);
            $stmt->execute();
            systemMessage($channel['id'], $user['nick'] . " has left " . $channel['name']);
            if ($activeChannel === $channel['name']) {
                $stmt = $db->prepare('SELECT name FROM channels c JOIN channel_members cm ON cm.channel_id = c.id WHERE cm.user_id = ? LIMIT 1');
                $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                $_SESSION['active_channel'] = $row ? $row['name'] : '#lobby';
            }
            return ['success' => true, 'switch_channel' => $_SESSION['active_channel']];
        case 'nick':
            if (!$arg1) return ['error' => 'Usage: /nick newname'];
            if (!preg_match('/^[A-Za-z0-9_\-\[\]\\^{}|`]{1,20}$/', $arg1)) return ['error' => 'Invalid nickname'];
            $existing = getUserByNick($arg1);
            if ($existing && $existing['id'] != $userId) return ['error' => 'Nickname already taken'];
            $oldNick = $user['nick'];
            $newColor = generateColor($arg1);
            $stmt = $db->prepare('UPDATE users SET nick = ?, color = ? WHERE id = ?');
            $stmt->bindValue(1, $arg1, SQLITE3_TEXT);
            $stmt->bindValue(2, $newColor, SQLITE3_TEXT);
            $stmt->bindValue(3, $userId, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt = $db->prepare('SELECT channel_id FROM channel_members WHERE user_id = ?');
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) systemMessage($row['channel_id'], "$oldNick is now known as $arg1");
            return ['success' => true, 'nick' => $arg1];
        case 'msg':
        case 'query':
            if (count($args) < 2) return ['error' => 'Usage: /msg user message'];
            $toNick = $args[0];
            $body = implode(' ', array_slice($args, 1));
            $toUser = getUserByNick($toNick);
            if (!$toUser) return ['error' => 'User not found'];
            if ($toUser['id'] == $userId) return ['error' => 'Cannot message yourself'];
            addMessage(null, $userId, $toUser['id'], 'pm', $body);
            $pmChannel = '@pm_' . $toUser['nick'];
            $_SESSION['active_channel'] = $pmChannel;
            return ['success' => true, 'switch_channel' => $pmChannel];
        case 'topic':
            if (strpos($activeChannel, '@pm_') === 0) return ['error' => 'No topic in PMs'];
            $channel = getChannel($activeChannel);
            if (!$channel) return ['error' => 'Not in a channel'];
            if ($rest === '') return ['success' => true, 'topic' => $channel['topic']];
            if (getMemberMode($channel['id'], $userId) !== 'o') return ['error' => 'Only operators can change topic'];
            $stmt = $db->prepare('UPDATE channels SET topic = ? WHERE id = ?');
            $stmt->bindValue(1, $rest, SQLITE3_TEXT);
            $stmt->bindValue(2, $channel['id'], SQLITE3_INTEGER);
            $stmt->execute();
            systemMessage($channel['id'], $user['nick'] . " changed topic to: $rest");
            return ['success' => true];
        case 'kick':
            if (!$arg1) return ['error' => 'Usage: /kick user [reason]'];
            if (strpos($activeChannel, '@pm_') === 0) return ['error' => 'Cannot kick in PMs'];
            $channel = getChannel($activeChannel);
            if (!$channel || getMemberMode($channel['id'], $userId) !== 'o') return ['error' => 'Only operators can kick users'];
            $victim = getUserByNick($arg1);
            if (!$victim || !isMember($channel['id'], $victim['id'])) return ['error' => 'User not in channel'];
            if ($victim['id'] == $userId) return ['error' => 'Cannot kick yourself'];
            $stmt = $db->prepare('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?');
            $stmt->bindValue(1, $channel['id'], SQLITE3_INTEGER);
            $stmt->bindValue(2, $victim['id'], SQLITE3_INTEGER);
            $stmt->execute();
            systemMessage($channel['id'], $victim['nick'] . " was kicked by " . $user['nick'] . ($rest ? " ($rest)" : ''));
            return ['success' => true];
        case 'op': case 'deop': case 'voice': case 'devoice':
            if (!$arg1) return ['error' => "Usage: /$cmd user"];
            $channel = getChannel($activeChannel);
            if (!$channel || getMemberMode($channel['id'], $userId) !== 'o') return ['error' => 'Only operators can change modes'];
            $target = getUserByNick($arg1);
            if (!$target || !isMember($channel['id'], $target['id'])) return ['error' => 'User not in channel'];
            $newMode = '';
            if ($cmd === 'op') $newMode = 'o';
            elseif ($cmd === 'voice') $newMode = 'v';
            $stmt = $db->prepare('UPDATE channel_members SET mode = ? WHERE channel_id = ? AND user_id = ?');
            $stmt->bindValue(1, $newMode, SQLITE3_TEXT);
            $stmt->bindValue(2, $channel['id'], SQLITE3_INTEGER);
            $stmt->bindValue(3, $target['id'], SQLITE3_INTEGER);
            $stmt->execute();
            systemMessage($channel['id'], $user['nick'] . " sets mode " . ($cmd[0] === 'd' ? '-' : '+') . ($cmd === 'op' || $cmd === 'deop' ? 'o' : 'v') . " on " . $target['nick']);
            return ['success' => true];
        case 'me':
            if (!$rest) return ['error' => 'Usage: /me action'];
            $channel = getChannel($activeChannel);
            if (!$channel) return ['error' => 'Not in a channel'];
            addMessage($channel['id'], $userId, null, 'action', $rest);
            return ['success' => true];
        case 'list':
            $result = $db->query('SELECT name, topic FROM channels ORDER BY name');
            $lines = ['Channel List:'];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $lines[] = $row['name'] . " - " . $row['topic'];
            return ['success' => true, 'list' => $lines];
        case 'who':
            $channel = getChannel($activeChannel);
            if (!$channel) return ['error' => 'Not in a channel'];
            $stmt = $db->prepare('SELECT u.nick, cm.mode FROM users u JOIN channel_members cm ON cm.user_id = u.id WHERE cm.channel_id = ? ORDER BY u.nick');
            $stmt->bindValue(1, $channel['id'], SQLITE3_INTEGER);
            $result = $stmt->execute();
            $lines = ['Users in ' . $channel['name'] . ':'];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $prefix = $row['mode'] === 'o' ? '@' : ($row['mode'] === 'v' ? '+' : '');
                $lines[] = $prefix . $row['nick'];
            }
            return ['success' => true, 'list' => $lines];
        case 'quit':
            $reason = $rest ?: 'Client quit';
            $stmt = $db->prepare('SELECT channel_id FROM channel_members WHERE user_id = ?');
            $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) systemMessage($row['channel_id'], $user['nick'] . " has quit ($reason)");
            $db->exec("DELETE FROM channel_members WHERE user_id = $userId");
            session_destroy();
            return ['success' => true, 'quit' => true];
        case 'help':
            return ['success' => true, 'list' => [
                'Available Commands:',
                '/join #channel       - Join or create a channel',
                '/part [#channel]     - Leave a channel',
                '/nick newname        - Change nickname',
                '/msg user message    - Private message (opens new tab)',
                '/topic [new topic]   - Change topic (ops only)',
                '/kick user [reason]  - Kick user (ops only)',
                '/op user             - Grant op (ops only)',
                '/deop user           - Remove op (ops only)',
                '/voice user          - Grant voice (ops only)',
                '/devoice user        - Remove voice (ops only)',
                '/me action           - Action message',
                '/list                - List channels',
                '/who                 - List users in channel',
                '/quit [reason]       - Disconnect'
            ]];
        default:
            return ['error' => 'Unknown command: /' . $cmd];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IRC Chat</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Courier New', monospace; background: #1a1a2e; color: #eee; height: 100vh; overflow: hidden; }
    #login-screen { position: fixed; inset: 0; background: #1a1a2e; display: flex; align-items: center; justify-content: center; z-index: 1000; }
    #login-screen.hidden { display: none; }
    .login-box { background: #16213e; padding: 40px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.5); width: 400px; }
    .login-box h1 { color: #4ecca3; margin-bottom: 24px; font-size: 28px; }
    .login-box input { width: 100%; padding: 14px; background: #0f3460; border: 2px solid #4ecca3; border-radius: 6px; color: #eee; font-size: 16px; margin-bottom: 16px; }
    .login-box button { width: 100%; padding: 14px; background: #4ecca3; border: none; border-radius: 6px; color: #1a1a2e; font-size: 16px; font-weight: bold; cursor: pointer; }
    .login-box button:hover { background: #45b393; }
    .error { color: #e74c3c; font-size: 14px; margin-top: 8px; min-height: 20px; }
    #chat-app { display: none; height: 100vh; }
    #chat-app.active { display: flex; }
    #sidebar { width: 240px; background: #16213e; border-right: 2px solid #0f3460; display: flex; flex-direction: column; }
    #sidebar-header { padding: 16px; background: #0f3460; border-bottom: 2px solid #4ecca3; font-weight: bold; color: #4ecca3; }
    #channel-list { flex: 1; overflow-y: auto; padding: 8px; }
    .channel-item { padding: 10px 12px; margin-bottom: 4px; background: #0f3460; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; }
    .channel-item:hover { background: #1a4d7a; }
    .channel-item.active { background: #4ecca3; color: #1a1a2e; font-weight: bold; }
    .channel-item .label { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .channel-item .close-btn { margin-left: 8px; font-weight: bold; color: #e74c3c; cursor: pointer; }
    .unread-badge { background: #e74c3c; color: white; border-radius: 12px; padding: 2px 8px; font-size: 12px; font-weight: bold; margin-left: 8px; }
    #main-area { flex: 1; display: flex; flex-direction: column; }
    #header { padding: 16px; background: #16213e; border-bottom: 2px solid #0f3460; display: flex; justify-content: space-between; align-items: center; }
    #channel-name { font-size: 20px; font-weight: bold; color: #4ecca3; }
    #channel-topic { color: #888; margin-left: 16px; font-size: 14px; }
    #user-info { color: #888; font-size: 14px; }
    #logout-btn { color: #e74c3c; cursor: pointer; margin-left: 16px; text-decoration: underline; }
    #content-area { flex: 1; display: flex; min-height: 0; }
    #messages-container { flex: 1; overflow-y: auto; padding: 16px; background: #1a1a2e; }
    .message { margin-bottom: 4px; line-height: 1.4; word-wrap: break-word; }
    .message-time { color: #666; font-size: 12px; margin-right: 8px; }
    .message-nick { font-weight: bold; margin-right: 8px; cursor: pointer; }
    .message-system { color: #4ecca3; font-style: italic; }
    .message-action { color: #f39c12; font-style: italic; }
    .message-pm { color: #e74c3c; }
    #user-list { width: 200px; background: #16213e; border-left: 2px solid #0f3460; overflow-y: auto; padding: 8px; }
    .user-item { padding: 6px 10px; margin-bottom: 4px; background: #0f3460; border-radius: 4px; cursor: pointer; transition: background 0.2s; }
    .user-item:hover { background: #1a4d7a; }
    .user-prefix { color: #4ecca3; margin-right: 6px; }
    #input-area { padding: 16px; background: #16213e; border-top: 2px solid #0f3460; }
    #message-input { width: 100%; padding: 12px; background: #0f3460; border: 2px solid #4ecca3; border-radius: 6px; color: #eee; font-family: inherit; font-size: 14px; }
    #message-input:focus { outline: none; border-color: #45b393; }
</style>
</head>
<body>
    <div id="login-screen">
        <div class="login-box">
            <h1>IRC Chat</h1>
            <input type="text" id="nick-input" placeholder="Enter your nickname" maxlength="20" autofocus>
            <button id="login-btn">Connect</button>
            <div class="error" id="login-error"></div>
        </div>
    </div>
    
    <div id="chat-app">
        <div id="sidebar">
            <div id="sidebar-header">Channels</div>
            <div id="channel-list"></div>
        </div>
        <div id="main-area">
            <div id="header">
                <div>
                    <span id="channel-name">#lobby</span>
                    <span id="channel-topic"></span>
                </div>
                <div id="user-info">
                    <span id="current-nick"></span>
                    <span id="logout-btn">Logout</span>
                </div>
            </div>
            <div id="content-area">
                <div id="messages-container"></div>
                <div id="user-list"></div>
            </div>
            <div id="input-area">
                <input type="text" id="message-input" placeholder="Type a message or /help" autocomplete="off">
            </div>
        </div>
    </div>
    
    <script>
        const POLL_INTERVAL = <?= POLL_INTERVAL ?>;
        let currentUser = null;
        let activeChannel = '#lobby';
        let channels = [];
        
        let pmTabs = [];
        let unreadPms = {};
        let channelLastIds = {};
        let pmLastIds = {};
        
        async function apiRequest(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            for (const key in data) formData.append(key, data[key]);
            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    return { error: 'Server error: ' + text.substring(0, 200) };
                }
                return await response.json();
            } catch (error) {
                return { error: 'Network error: ' + error.message };
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function formatTime(timestamp) {
            const date = new Date(timestamp * 1000);
            return date.toTimeString().substring(0, 5);
        }
        
        document.getElementById('login-btn').addEventListener('click', doLogin);
        document.getElementById('nick-input').addEventListener('keypress', (e) => { if (e.key === 'Enter') doLogin(); });
        
        async function doLogin() {
            const nick = document.getElementById('nick-input').value.trim();
            const errorEl = document.getElementById('login-error');
            errorEl.textContent = '';
            if (!nick) return;
            const result = await apiRequest('login', { nick });
            if (result.error) { errorEl.textContent = result.error; return; }
            currentUser = result;
            document.getElementById('login-screen').classList.add('hidden');
            document.getElementById('chat-app').classList.add('active');
            document.getElementById('current-nick').textContent = result.nick;
            startChat();
        }
        
        document.getElementById('logout-btn').addEventListener('click', async () => {
            await apiRequest('logout');
            location.reload();
        });
        
        async function startChat() {
            await refreshState();
            pollLoop();
        }
        
        async function refreshState() {
            const state = await apiRequest('state');
            if (state.error) return;
            currentUser = state.user;
            channels = state.channels;
            if (!activeChannel) activeChannel = state.active_channel;
            document.getElementById('current-nick').textContent = state.user.nick;
            updatePmBadges(state.unread_pms || []);
            renderChannelList();
            await renderActiveChannel();
        }
        
        function updatePmBadges(unread_pms) {
            unread_pms.forEach(pm => {
                if (!pmTabs.includes(pm.nick)) {
                    pmTabs.push(pm.nick);
                    pmLastIds[pm.nick] = pm.max_id;
                }
                unreadPms[pm.nick] = parseInt(pm.cnt);
            });
        }
        
        function renderChannelList() {
            const list = document.getElementById('channel-list');
            list.innerHTML = '';
            
            channels.forEach(channel => {
                const item = document.createElement('div');
                item.className = 'channel-item' + (activeChannel === channel.name ? ' active' : '');
                item.innerHTML = `<span class="label">${escapeHtml(channel.name)}</span>`;
                item.addEventListener('click', () => setActiveChannel(channel.name));
                list.appendChild(item);
            });
            
            pmTabs.forEach(nick => {
                const item = document.createElement('div');
                item.className = 'channel-item' + (activeChannel === '@pm_' + nick ? ' active' : '');
                const label = document.createElement('span');
                label.className = 'label';
                label.textContent = '@' + nick;
                item.appendChild(label);
                
                if (unreadPms[nick] > 0) {
                    const badge = document.createElement('span');
                    badge.className = 'unread-badge';
                    badge.textContent = unreadPms[nick];
                    item.appendChild(badge);
                }
                
                const closeBtn = document.createElement('span');
                closeBtn.className = 'close-btn';
                closeBtn.textContent = '×';
                closeBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    closePmTab(nick);
                });
                item.appendChild(closeBtn);
                
                item.addEventListener('click', () => setActiveChannel('@pm_' + nick));
                list.appendChild(item);
            });
        }
        
        function closePmTab(nick) {
            pmTabs = pmTabs.filter(n => n !== nick);
            delete unreadPms[nick];
            delete pmLastIds[nick];
            if (activeChannel === '@pm_' + nick) {
                setActiveChannel(channels.length > 0 ? channels[0].name : '#lobby');
            } else {
                renderChannelList();
            }
        }
        
        async function setActiveChannel(channelName) {
            activeChannel = channelName;
            document.getElementById('messages-container').innerHTML = '';
            
            if (channelName.startsWith('@pm_')) {
                const nick = channelName.substring(4);
                unreadPms[nick] = 0;
                pmLastIds[nick] = 0;
            } else {
                channelLastIds[channelName] = 0;
            }
            
            await apiRequest('set_active', { channel: channelName });
            await refreshState();
        }
        
        async function renderActiveChannel() {
            const nameEl = document.getElementById('channel-name');
            const topicEl = document.getElementById('channel-topic');
            const userList = document.getElementById('user-list');
            
            nameEl.textContent = activeChannel;
            topicEl.textContent = '';
            userList.innerHTML = '';
            
            if (activeChannel.startsWith('@pm_')) {
                const nick = activeChannel.substring(4);
                nameEl.textContent = 'PM with ' + nick;
                userList.style.display = 'none';
                
                const sinceId = pmLastIds[nick] || 0;
                const result = await apiRequest('messages', { channel: activeChannel, since: sinceId });
                if (!result.error) {
                    const container = document.getElementById('messages-container');
                    if (sinceId === 0) container.innerHTML = '';
                    result.messages.forEach(msg => container.appendChild(renderMessage(msg)));
                    container.scrollTop = container.scrollHeight;
                    if (result.messages.length > 0) {
                        pmLastIds[nick] = result.messages[result.messages.length - 1].id;
                    }
                }
            } else {
                userList.style.display = 'block';
                const userData = await apiRequest('users', { channel: activeChannel });
                if (!userData.error) {
                    topicEl.textContent = userData.topic ? '— ' + userData.topic : '';
                    const sorted = userData.users.sort((a, b) => {
                        const p = { o: 0, v: 1, '': 2 };
                        return (p[a.mode] || 2) - (p[b.mode] || 2) || a.nick.localeCompare(b.nick);
                    });
                    sorted.forEach(user => {
                        const item = document.createElement('div');
                        item.className = 'user-item';
                        const prefix = user.mode === 'o' ? '@' : (user.mode === 'v' ? '+' : '');
                        item.innerHTML = `<span class="user-prefix">${prefix}</span><span style="color: ${user.color}">${escapeHtml(user.nick)}</span>`;
                        item.addEventListener('click', () => {
                            document.getElementById('message-input').value = '/msg ' + user.nick + ' ';
                            document.getElementById('message-input').focus();
                        });
                        item.addEventListener('dblclick', () => {
                            if (!pmTabs.includes(user.nick)) pmTabs.push(user.nick);
                            setActiveChannel('@pm_' + user.nick);
                        });
                        userList.appendChild(item);
                    });
                }
                
                const sinceId = channelLastIds[activeChannel] || 0;
                const result = await apiRequest('messages', { channel: activeChannel, since: sinceId });
                if (!result.error) {
                    const container = document.getElementById('messages-container');
                    if (sinceId === 0) container.innerHTML = '';
                    result.messages.forEach(msg => container.appendChild(renderMessage(msg)));
                    container.scrollTop = container.scrollHeight;
                    if (result.messages.length > 0) {
                        channelLastIds[activeChannel] = result.messages[result.messages.length - 1].id;
                    }
                }
            }
        }
        
        async function fetchActiveMessages() {
            const sinceId = activeChannel.startsWith('@pm_') 
                ? (pmLastIds[activeChannel.substring(4)] || 0)
                : (channelLastIds[activeChannel] || 0);
                
            const result = await apiRequest('messages', { channel: activeChannel, since: sinceId });
            if (!result.error && result.messages.length > 0) {
                const container = document.getElementById('messages-container');
                result.messages.forEach(msg => container.appendChild(renderMessage(msg)));
                container.scrollTop = container.scrollHeight;
                const lastId = result.messages[result.messages.length - 1].id;
                if (activeChannel.startsWith('@pm_')) {
                    pmLastIds[activeChannel.substring(4)] = lastId;
                } else {
                    channelLastIds[activeChannel] = lastId;
                }
            }
        }
        
        function renderMessage(msg) {
            const div = document.createElement('div');
            div.className = 'message';
            const time = `<span class="message-time">[${formatTime(msg.timestamp)}]</span>`;
            
            if (msg.msg_type === 'system') {
                div.classList.add('message-system');
                div.innerHTML = time + ' ' + escapeHtml(msg.content);
            } else if (msg.msg_type === 'action') {
                div.classList.add('message-action');
                div.innerHTML = `${time} * <span class="message-nick" style="color: ${msg.from_color}" data-nick="${escapeHtml(msg.from_nick)}">${escapeHtml(msg.from_nick)}</span> ${escapeHtml(msg.content)}`;
            } else if (msg.msg_type === 'pm') {
                div.classList.add('message-pm');
                div.innerHTML = `${time} <span class="message-nick" style="color: ${msg.from_color}" data-nick="${escapeHtml(msg.from_nick)}">&lt;${escapeHtml(msg.from_nick)}&gt;</span> ${escapeHtml(msg.content)}`;
            } else {
                div.innerHTML = `${time} <span class="message-nick" style="color: ${msg.from_color}" data-nick="${escapeHtml(msg.from_nick)}">&lt;${escapeHtml(msg.from_nick)}&gt;</span> ${escapeHtml(msg.content)}`;
            }
            
            const nickEl = div.querySelector('.message-nick');
            if (nickEl) {
                nickEl.addEventListener('click', () => {
                    document.getElementById('message-input').value = '/msg ' + nickEl.dataset.nick + ' ';
                    document.getElementById('message-input').focus();
                });
            }
            return div;
        }
        
        document.getElementById('message-input').addEventListener('keypress', async (e) => {
            if (e.key === 'Enter') {
                const input = document.getElementById('message-input');
                const text = input.value.trim();
                input.value = '';
                if (!text) return;
                
                const result = await apiRequest('send', { text });
                if (result.error) {
                    const container = document.getElementById('messages-container');
                    const div = document.createElement('div');
                    div.className = 'message message-system';
                    div.textContent = '* Error: ' + result.error;
                    container.appendChild(div);
                    container.scrollTop = container.scrollHeight;
                    return;
                }
                
                if (result.list) {
                    const container = document.getElementById('messages-container');
                    result.list.forEach(line => {
                        const div = document.createElement('div');
                        div.className = 'message message-system';
                        div.textContent = line;
                        container.appendChild(div);
                    });
                    container.scrollTop = container.scrollHeight;
                }
                if (result.topic) {
                    document.getElementById('channel-topic').textContent = '— ' + result.topic;
                }
                
                if (result.switch_channel) {
                    if (result.switch_channel.startsWith('@pm_')) {
                        const nick = result.switch_channel.substring(4);
                        if (!pmTabs.includes(nick)) pmTabs.push(nick);
                    }
                    activeChannel = result.switch_channel;
                    document.getElementById('messages-container').innerHTML = '';
                    if (activeChannel.startsWith('@pm_')) pmLastIds[activeChannel.substring(4)] = 0;
                    else channelLastIds[activeChannel] = 0;
                    await refreshState();
                } else if (result.nick) {
                    currentUser.nick = result.nick;
                    document.getElementById('current-nick').textContent = result.nick;
                } else if (result.quit) {
                    location.reload();
                } else {
                    await fetchActiveMessages();
                }
            }
        });
        
        async function pollLoop() {
            while (true) {
                await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
                await fetchActiveMessages();
                const state = await apiRequest('state');
                if (!state.error && state.unread_pms) {
                    updatePmBadges(state.unread_pms);
                    renderChannelList();
                }
            }
        }
    </script>
</body>
</html>