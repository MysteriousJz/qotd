<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/** Open the SQLite database. */
function qotd_db(): SQLite3
{
    static $db = null;

    if ($db instanceof SQLite3) {
        return $db;
    }

    qotd_ensure_runtime_dirs();
    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(3000);
    $db->enableExceptions(true);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    return $db;
}

/** Bind positional parameters to a prepared statement. */
function qotd_bind_params(SQLite3Stmt $stmt, array $params): void
{
    $index = 1;
    foreach ($params as $value) {
        $type = SQLITE3_TEXT;
        if (is_int($value)) {
            $type = SQLITE3_INTEGER;
        } elseif (is_float($value)) {
            $type = SQLITE3_FLOAT;
        } elseif ($value === null) {
            $type = SQLITE3_NULL;
        } elseif (is_bool($value)) {
            $type = SQLITE3_INTEGER;
            $value = $value ? 1 : 0;
        }
        $stmt->bindValue($index++, $value, $type);
    }
}

/** Fetch all rows from a query. */
function qotd_query_all(SQLite3 $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    qotd_bind_params($stmt, $params);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $result->finalize();
    return $rows;
}

/** Fetch one row from a query. */
function qotd_query_one(SQLite3 $db, string $sql, array $params = []): ?array
{
    $rows = qotd_query_all($db, $sql, $params);
    return $rows[0] ?? null;
}

/** Run a write query and return the statement result. */
function qotd_exec(SQLite3 $db, string $sql, array $params = []): SQLite3Result|bool
{
    $stmt = $db->prepare($sql);
    qotd_bind_params($stmt, $params);
    return $stmt->execute();
}

/** Create database schema if it does not already exist. */
function qotd_init_db(): void
{
    $db = qotd_db();
    $db->exec('BEGIN');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT UNIQUE NOT NULL,
            question_text TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS replies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            display_name TEXT NOT NULL,
            reply_to INTEGER DEFAULT NULL,
            approved INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
            FOREIGN KEY (reply_to) REFERENCES replies(id) ON DELETE CASCADE
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            display_name TEXT NOT NULL,
            reply_to INTEGER DEFAULT NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS bans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            action TEXT NOT NULL,
            content_preview TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_questions_date ON questions(date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_replies_question ON replies(question_id, created_at DESC, id DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_replies_parent ON replies(reply_to, created_at DESC, id DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_queue_question ON queue(question_id, submitted_at ASC, id ASC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_logs_ip_action ON logs(ip_address, action, id DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_bans_ip ON bans(ip_address)');
    $db->exec('COMMIT');

    // Keep the ban cache warm so public cache hits can still reject blocked IPs.
    qotd_refresh_ban_cache($db);
}

/** Return today's question, or null if none exists. */
function qotd_question_for_date(string $date): ?array
{
    $db = qotd_db();
    return qotd_query_one($db, 'SELECT * FROM questions WHERE date = ? LIMIT 1', [$date]);
}

/** Return the question for the current UTC day. */
function qotd_today_question(): ?array
{
    return qotd_question_for_date(qotd_now()->format('Y-m-d'));
}

/** Fetch every question date in a month for the calendar. */
function qotd_question_dates_for_month(DateTimeImmutable $month): array
{
    $start = $month->modify('first day of this month')->format('Y-m-01');
    $end = $month->modify('first day of next month')->format('Y-m-01');
    $rows = qotd_query_all(qotd_db(), 'SELECT date FROM questions WHERE date >= ? AND date < ? ORDER BY date ASC', [$start, $end]);
    return array_map(static fn (array $row): string => (string)$row['date'], $rows);
}

/** Upsert a question for a specific date. */
function qotd_set_question(string $date, string $questionText): array
{
    $db = qotd_db();
    qotd_exec(
        $db,
        'INSERT INTO questions (date, question_text) VALUES (?, ?)
         ON CONFLICT(date) DO UPDATE SET question_text = excluded.question_text',
        [$date, $questionText]
    );
    $row = qotd_query_one($db, 'SELECT * FROM questions WHERE date = ? LIMIT 1', [$date]);
    if ($row === null) {
        throw new RuntimeException('Question could not be saved.');
    }
    qotd_invalidate_month_cache($date);
    return $row;
}

/** Fetch all approved replies for one question. */
function qotd_replies_for_question(int $questionId): array
{
    return qotd_query_all(
        qotd_db(),
        'SELECT * FROM replies WHERE question_id = ? AND approved = 1 ORDER BY created_at DESC, id DESC',
        [$questionId]
    );
}

/** Fetch a reply by ID. */
function qotd_reply_by_id(int $replyId): ?array
{
    return qotd_query_one(qotd_db(), 'SELECT * FROM replies WHERE id = ? LIMIT 1', [$replyId]);
}

/** Fetch a reply with its question date for links and moderation. */
function qotd_reply_with_question_date(int $replyId): ?array
{
    $row = qotd_query_one(
        qotd_db(),
        'SELECT r.*, q.date AS question_date FROM replies r INNER JOIN questions q ON q.id = r.question_id WHERE r.id = ? LIMIT 1',
        [$replyId]
    );
    return $row;
}

/** Fetch the queue for the admin moderation page. */
function qotd_queue_items(): array
{
    return qotd_query_all(
        qotd_db(),
        'SELECT q.*, questions.date AS question_date
         FROM queue q
         INNER JOIN questions ON questions.id = q.question_id
         ORDER BY q.submitted_at ASC, q.id ASC'
    );
}

/** Fetch every approved reply for the admin dashboard. */
function qotd_all_approved_replies(): array
{
    return qotd_query_all(
        qotd_db(),
        'SELECT r.*, questions.date AS question_date
         FROM replies r
         INNER JOIN questions ON questions.id = r.question_id
         WHERE r.approved = 1
         ORDER BY r.created_at DESC, r.id DESC'
    );
}

/** Fetch the activity log. */
function qotd_logs(): array
{
    return qotd_query_all(qotd_db(), 'SELECT * FROM logs ORDER BY id DESC LIMIT ?', [LOG_LIMIT]);
}

/** Add an activity log row. */
function qotd_add_log(string $ipAddress, string $action, string $contentPreview = ''): void
{
    qotd_exec(qotd_db(), 'INSERT INTO logs (ip_address, action, content_preview) VALUES (?, ?, ?)', [$ipAddress, $action, $contentPreview]);
}

/** Check whether an IP is banned. */
function qotd_is_banned(string $ipAddress): bool
{
    return qotd_query_one(qotd_db(), 'SELECT 1 FROM bans WHERE ip_address = ? LIMIT 1', [$ipAddress]) !== null;
}

/** Add a ban and refresh the ban cache. */
function qotd_ban_ip(string $ipAddress): void
{
    $db = qotd_db();
    qotd_exec($db, 'INSERT OR IGNORE INTO bans (ip_address) VALUES (?)', [$ipAddress]);
    qotd_add_log($ipAddress, 'banned', 'ban added');
    qotd_refresh_ban_cache($db);
}

/** Return the minutes/seconds remaining on the rate limit. */
function qotd_rate_limit_remaining(string $ipAddress): int
{
    $row = qotd_query_one(
        qotd_db(),
        "SELECT created_at FROM logs WHERE ip_address = ? AND action = 'submitted' ORDER BY id DESC LIMIT 1",
        [$ipAddress]
    );
    if ($row === null) {
        return 0;
    }

    $last = new DateTimeImmutable((string)$row['created_at'], new DateTimeZone('UTC'));
    $elapsed = qotd_now()->getTimestamp() - $last->getTimestamp();
    $remaining = RATE_LIMIT_SECONDS - $elapsed;
    return $remaining > 0 ? $remaining : 0;
}

/** Queue a new reply for moderation. */
function qotd_queue_reply(int $questionId, string $content, string $ipAddress, string $displayName, ?int $replyTo): int
{
    $db = qotd_db();
    qotd_exec(
        $db,
        'INSERT INTO queue (question_id, content, ip_address, display_name, reply_to) VALUES (?, ?, ?, ?, ?)',
        [$questionId, $content, $ipAddress, $displayName, $replyTo]
    );
    return (int)$db->lastInsertRowID();
}

/** Approve a queue item and move it into the replies table. */
function qotd_approve_queue_item(int $queueId): array
{
    $db = qotd_db();
    $item = qotd_query_one($db, 'SELECT * FROM queue WHERE id = ? LIMIT 1', [$queueId]);
    if ($item === null) {
        throw new RuntimeException('Queue item not found.');
    }

    $replyTo = $item['reply_to'] !== null ? (int)$item['reply_to'] : null;
    if ($replyTo !== null) {
        $target = qotd_query_one($db, 'SELECT id FROM replies WHERE id = ? AND question_id = ? LIMIT 1', [$replyTo, (int)$item['question_id']]);
        if ($target === null) {
            throw new RuntimeException('Reply target no longer exists.');
        }
    }

    $db->exec('BEGIN');
    qotd_exec(
        $db,
        'INSERT INTO replies (question_id, content, ip_hash, display_name, reply_to, approved) VALUES (?, ?, ?, ?, ?, 1)',
        [(int)$item['question_id'], (string)$item['content'], hash('sha256', (string)$item['ip_address'] . qotd_anonymous_salt()), (string)$item['display_name'], $replyTo]
    );
    $replyId = (int)$db->lastInsertRowID();
    qotd_exec($db, 'DELETE FROM queue WHERE id = ?', [$queueId]);
    qotd_add_log((string)$item['ip_address'], 'approved', mb_substr((string)$item['content'], 0, 120));
    $question = qotd_query_one($db, 'SELECT date FROM questions WHERE id = ? LIMIT 1', [(int)$item['question_id']]);
    if ($question !== null) {
        qotd_invalidate_date_cache((string)$question['date']);
    }
    $db->exec('COMMIT');

    return ['reply_id' => $replyId, 'date' => (string)($question['date'] ?? '')];
}

/** Reject a queue item. */
function qotd_reject_queue_item(int $queueId): string
{
    $db = qotd_db();
    $item = qotd_query_one($db, 'SELECT * FROM queue WHERE id = ? LIMIT 1', [$queueId]);
    if ($item === null) {
        throw new RuntimeException('Queue item not found.');
    }

    $question = qotd_query_one($db, 'SELECT date FROM questions WHERE id = ? LIMIT 1', [(int)$item['question_id']]);
    qotd_exec($db, 'DELETE FROM queue WHERE id = ?', [$queueId]);
    qotd_add_log((string)$item['ip_address'], 'rejected', mb_substr((string)$item['content'], 0, 120));
    if ($question !== null) {
        qotd_invalidate_date_cache((string)$question['date']);
    }
    return (string)($question['date'] ?? '');
}

/** Delete a reply and its descendants. */
function qotd_delete_reply(int $replyId): string
{
    $db = qotd_db();
    $reply = qotd_query_one(
        $db,
        'SELECT r.*, q.date AS question_date FROM replies r INNER JOIN questions q ON q.id = r.question_id WHERE r.id = ? LIMIT 1',
        [$replyId]
    );
    if ($reply === null) {
        throw new RuntimeException('Reply not found.');
    }

    qotd_exec($db, 'DELETE FROM replies WHERE id = ?', [$replyId]);
    qotd_add_log(qotd_client_ip(), 'deleted', mb_substr((string)$reply['content'], 0, 120));
    qotd_invalidate_date_cache((string)$reply['question_date']);
    return (string)$reply['question_date'];
}

/** Seed sample questions for testing. */
function qotd_seed_sample_questions(): void
{
    qotd_set_question('2026-06-30', 'What is your question for the board today?');
    qotd_set_question('2026-06-29', 'What was the best book you have ever read?');
    qotd_set_question('2026-06-28', 'If you could learn any skill instantly, what would it be?');
}
