<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Ensure the admin session is valid. */
function qotd_admin_require_auth(): void
{
    qotd_start_session();
    if (qotd_admin_logged_in() && qotd_admin_session_expired()) {
        qotd_logout_admin();
    }

    if (!qotd_admin_logged_in()) {
        header('Location: /admin/login');
        exit;
    }

    qotd_touch_admin_session();
}

/** Render the login form. */
function qotd_admin_login_page(string $message = ''): void
{
    $body = '<main class="page"><section class="panel"><div class="panel-head">Admin Login</div><div class="panel-body">';
    if ($message !== '') {
        $body .= qotd_notice($message, 'error');
    }
    $body .= '<form method="post" action="/admin/login">';
    $body .= qotd_csrf_field();
    $body .= '<label for="password">Password</label>';
    $body .= '<input type="password" id="password" name="password" autocomplete="current-password">';
    $body .= '<div class="form-actions"><button type="submit">Log in</button></div>';
    $body .= '</form></div></section></main>';

    echo qotd_admin_shell('Admin Login', $body);
    exit;
}

/** Admin dashboard page. */
function qotd_admin_dashboard_page(string $message = ''): void
{
    qotd_admin_require_auth();
    qotd_init_db();

    $questionDate = (string)($_GET['date'] ?? qotd_now()->format('Y-m-d'));
    $questionDate = qotd_normalize_date($questionDate) ?? qotd_now()->format('Y-m-d');
    $question = qotd_question_for_date($questionDate);
    $approved = qotd_all_approved_replies();
    $queueCount = count(qotd_queue_items());
    $banList = qotd_query_all(qotd_db(), 'SELECT * FROM bans ORDER BY created_at DESC, id DESC');
    $calendarDates = qotd_question_dates_for_month(qotd_date_obj($questionDate));
    $notice = $message !== '' ? qotd_notice($message, 'status') : '';

    $questionForm = '<section class="panel"><div class="panel-head">Question Manager</div><div class="panel-body">';
    if ($notice !== '') {
        $questionForm .= $notice;
    }
    $questionForm .= '<form method="post" action="/admin/question">';
    $questionForm .= qotd_csrf_field();
    $questionForm .= '<label for="date">Date</label>';
    $questionForm .= '<input type="date" id="date" name="date" value="' . qotd_h($questionDate) . '">';
    $questionForm .= '<label for="question_text">Question text</label>';
    $questionForm .= '<textarea id="question_text" name="question_text" maxlength="' . QUESTION_MAX_LENGTH . '">' . qotd_h((string)($question['question_text'] ?? '')) . '</textarea>';
    $questionForm .= '<div class="form-actions"><button type="submit">Save question</button></div>';
    $questionForm .= '</form>';
    if ($question !== null) {
        $questionForm .= '<div class="help-text">Current question: No.' . qotd_h((string)$question['id']) . '</div>';
    } else {
        $questionForm .= '<div class="help-text">No question exists for this date yet.</div>';
    }
    $questionForm .= '</div></section>';

    $queueItems = qotd_queue_items();
    $queueHtml = '<section class="panel"><div class="panel-head">Moderation Queue</div><div class="panel-body">';
    $queueHtml .= '<div class="help-text">' . count($queueItems) . ' item(s) pending / ' . $queueCount . ' total in queue table</div>';
    if ($queueItems === []) {
        $queueHtml .= '<div class="empty-column">Queue is empty.</div>';
    } else {
        foreach ($queueItems as $item) {
            $replyTo = !empty($item['reply_to']) ? (int)$item['reply_to'] : null;
            $replyLine = $replyTo ? '<div class="reply-to">In reply to <a href="/#post-' . $replyTo . '">#' . $replyTo . '</a></div>' : '';
            $queueHtml .= '<article class="post discussion">';
            $queueHtml .= '<div class="post-head"><div class="post-author">' . qotd_h((string)$item['display_name']) . '</div><div class="post-meta">Queue #' . (int)$item['id'] . ' / ' . qotd_h((string)$item['question_date']) . ' / ' . qotd_h(qotd_format_timestamp((string)$item['submitted_at'])) . '</div></div>';
            $queueHtml .= '<div class="post-body">' . $replyLine . '<div class="post-content">' . qotd_content_to_html((string)$item['content']) . '</div>';
            $queueHtml .= '<div class="post-actions">';
            $queueHtml .= '<form class="inline-form" method="post" action="/admin/queue/' . (int)$item['id'] . '/approve">' . qotd_csrf_field() . '<button type="submit">Approve</button></form> ';
            $queueHtml .= '<form class="inline-form" method="post" action="/admin/queue/' . (int)$item['id'] . '/reject">' . qotd_csrf_field() . '<button type="submit">Reject</button></form> ';
            $queueHtml .= '<form class="inline-form" method="post" action="/admin/bans/add">' . qotd_csrf_field() . '<input type="hidden" name="ip_address" value="' . qotd_h((string)$item['ip_address']) . '"><button type="submit">Ban IP</button></form>';
            $queueHtml .= '</div></div></article>';
        }
    }
    $queueHtml .= '</div></section>';

    $approvedHtml = '<section class="panel"><div class="panel-head">Approved Replies</div><div class="panel-body">';
    if ($approved === []) {
        $approvedHtml .= '<div class="empty-column">No approved replies yet.</div>';
    } else {
        foreach ($approved as $reply) {
            $replyTo = !empty($reply['reply_to']) ? (int)$reply['reply_to'] : null;
            $approvedHtml .= '<article class="post ' . ($replyTo ? 'discussion' : 'answer') . '">';
            $approvedHtml .= '<div class="post-head"><div class="post-author">' . qotd_h((string)$reply['display_name']) . '</div><div class="post-meta">No.' . (int)$reply['id'] . ' / ' . qotd_h((string)$reply['question_date']) . ' / ' . qotd_h(qotd_format_timestamp((string)$reply['created_at'])) . '</div></div>';
            $approvedHtml .= '<div class="post-body">';
            if ($replyTo) {
                $approvedHtml .= '<div class="reply-to">In reply to <a href="/#post-' . $replyTo . '">#' . $replyTo . '</a></div>';
            }
            $approvedHtml .= '<div class="post-content">' . qotd_content_to_html((string)$reply['content']) . '</div>';
            $approvedHtml .= '<div class="post-actions"><form class="inline-form" method="post" action="/admin/replies/' . (int)$reply['id'] . '/delete">' . qotd_csrf_field() . '<button type="submit">Delete</button></form></div>';
            $approvedHtml .= '</div></article>';
        }
    }
    $approvedHtml .= '</div></section>';

    $banHtml = '<section class="panel"><div class="panel-head">Banned IPs</div><div class="panel-body">';
    $banHtml .= '<form method="post" action="/admin/bans/add">' . qotd_csrf_field();
    $banHtml .= '<label for="ip_address">Ban IP address</label><input type="text" id="ip_address" name="ip_address" placeholder="127.0.0.1">';
    $banHtml .= '<div class="form-actions"><button type="submit">Ban</button></div></form>';
    if ($banList === []) {
        $banHtml .= '<div class="help-text">No banned IPs.</div>';
    } else {
        $banHtml .= '<ul class="list">';
        foreach ($banList as $row) {
            $banHtml .= '<li>' . qotd_h((string)$row['ip_address']) . ' <span class="small">' . qotd_h(qotd_format_timestamp((string)$row['created_at'])) . '</span></li>';
        }
        $banHtml .= '</ul>';
    }
    $banHtml .= '</div></section>';

    $logs = qotd_logs();
    $logHtml = '<section class="panel"><div class="panel-head">Activity Log</div><div class="panel-body">';
    if ($logs === []) {
        $logHtml .= '<div class="empty-column">No logs yet.</div>';
    } else {
        foreach ($logs as $row) {
            $logHtml .= '<div class="log-row"><span class="log-action">' . qotd_h((string)$row['action']) . '</span> ';
            $logHtml .= '<span class="log-ip">' . qotd_h((string)$row['ip_address']) . '</span> ';
            $logHtml .= '<span class="log-time">' . qotd_h(qotd_format_timestamp((string)$row['created_at'])) . '</span>';
            if (!empty($row['content_preview'])) {
                $logHtml .= '<div class="small">' . qotd_h((string)$row['content_preview']) . '</div>';
            }
            $logHtml .= '</div>';
        }
    }
    $logHtml .= '</div></section>';

    $calendar = qotd_calendar_html(qotd_date_obj($questionDate), $calendarDates, $questionDate);

    $body = '<main class="page admin-page">'
        . $questionForm
        . $calendar
        . $queueHtml
        . $approvedHtml
        . $banHtml
        . $logHtml
        . '</main>';

    echo qotd_admin_shell('Admin Dashboard', $body);
    exit;
}

/** Moderation queue page. */
function qotd_admin_queue_page(): void
{
    qotd_admin_require_auth();
    qotd_init_db();

    $items = qotd_queue_items();
    $body = '<main class="page"><section class="panel"><div class="panel-head">Moderation Queue</div><div class="panel-body">';
    if ($items === []) {
        $body .= '<div class="empty-column">Queue is empty.</div>';
    } else {
        foreach ($items as $item) {
            $replyTo = !empty($item['reply_to']) ? (int)$item['reply_to'] : null;
            $body .= '<article class="post discussion">';
            $body .= '<div class="post-head"><div class="post-author">' . qotd_h((string)$item['display_name']) . '</div><div class="post-meta">Queue #' . (int)$item['id'] . ' / ' . qotd_h((string)$item['question_date']) . ' / ' . qotd_h(qotd_format_timestamp((string)$item['submitted_at'])) . '</div></div>';
            $body .= '<div class="post-body">';
            if ($replyTo) {
                $body .= '<div class="reply-to">In reply to <a href="/#post-' . $replyTo . '">#' . $replyTo . '</a></div>';
            }
            $body .= '<div class="post-content">' . qotd_content_to_html((string)$item['content']) . '</div>';
            $body .= '<div class="post-actions">';
            $body .= '<form class="inline-form" method="post" action="/admin/queue/' . (int)$item['id'] . '/approve">' . qotd_csrf_field() . '<button type="submit">Approve</button></form> ';
            $body .= '<form class="inline-form" method="post" action="/admin/queue/' . (int)$item['id'] . '/reject">' . qotd_csrf_field() . '<button type="submit">Reject</button></form> ';
            $body .= '<form class="inline-form" method="post" action="/admin/bans/add">' . qotd_csrf_field() . '<input type="hidden" name="ip_address" value="' . qotd_h((string)$item['ip_address']) . '"><button type="submit">Ban IP</button></form>';
            $body .= '</div></div></article>';
        }
    }
    $body .= '</div></section></main>';

    echo qotd_admin_shell('Moderation Queue', $body);
    exit;
}

/** Activity log page. */
function qotd_admin_logs_page(): void
{
    qotd_admin_require_auth();
    qotd_init_db();

    $logs = qotd_logs();
    $body = '<main class="page"><section class="panel"><div class="panel-head">Activity Log</div><div class="panel-body">';
    if ($logs === []) {
        $body .= '<div class="empty-column">No logs yet.</div>';
    } else {
        foreach ($logs as $row) {
            $body .= '<div class="log-row"><span class="log-action">' . qotd_h((string)$row['action']) . '</span> ';
            $body .= '<span class="log-ip">' . qotd_h((string)$row['ip_address']) . '</span> ';
            $body .= '<span class="log-time">' . qotd_h(qotd_format_timestamp((string)$row['created_at'])) . '</span>';
            if (!empty($row['content_preview'])) {
                $body .= '<div class="small">' . qotd_h((string)$row['content_preview']) . '</div>';
            }
            $body .= '</div>';
        }
    }
    $body .= '</div></section></main>';

    echo qotd_admin_shell('Activity Log', $body);
    exit;
}

/** Process login. */
function qotd_admin_login_submit(): void
{
    qotd_start_session();
    qotd_require_csrf();
    qotd_init_db();

    $password = (string)($_POST['password'] ?? '');
    if (!password_verify($password, ADMIN_PASSWORD_HASH)) {
        qotd_admin_login_page('Invalid password.');
    }

    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    qotd_touch_admin_session();
    header('Location: /admin');
    exit;
}

/** Set or update a question. */
function qotd_admin_save_question(): void
{
    qotd_admin_require_auth();
    qotd_require_csrf();
    qotd_init_db();

    $date = qotd_normalize_date((string)($_POST['date'] ?? ''));
    $questionText = trim((string)($_POST['question_text'] ?? ''));
    if ($date === null || $questionText === '') {
        header('Location: /admin?message=' . rawurlencode('Please supply both a valid date and question text.'));
        exit;
    }

    qotd_set_question($date, $questionText);
    header('Location: /admin?date=' . rawurlencode($date) . '&message=' . rawurlencode('Question saved.'));
    exit;
}

/** Approve a queue item. */
function qotd_admin_approve_queue(int $queueId): void
{
    qotd_admin_require_auth();
    qotd_require_csrf();
    qotd_init_db();

    try {
        $result = qotd_approve_queue_item($queueId);
        $targetDate = $result['date'] !== '' ? $result['date'] : qotd_now()->format('Y-m-d');
        header('Location: /admin?date=' . rawurlencode($targetDate) . '&message=' . rawurlencode('Reply approved.'));
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/queue?message=' . rawurlencode($e->getMessage()));
        exit;
    }
}

/** Reject a queue item. */
function qotd_admin_reject_queue(int $queueId): void
{
    qotd_admin_require_auth();
    qotd_require_csrf();
    qotd_init_db();

    try {
        $date = qotd_reject_queue_item($queueId);
        $location = '/admin/queue?message=' . rawurlencode('Reply rejected.');
        if ($date !== '') {
            $location = '/admin?date=' . rawurlencode($date) . '&message=' . rawurlencode('Reply rejected.');
        }
        header('Location: ' . $location);
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/queue?message=' . rawurlencode($e->getMessage()));
        exit;
    }
}

/** Delete an approved reply. */
function qotd_admin_delete_reply(int $replyId): void
{
    qotd_admin_require_auth();
    qotd_require_csrf();
    qotd_init_db();

    try {
        $date = qotd_delete_reply($replyId);
        header('Location: /admin?date=' . rawurlencode($date) . '&message=' . rawurlencode('Reply deleted.'));
        exit;
    } catch (Throwable $e) {
        header('Location: /admin?message=' . rawurlencode($e->getMessage()));
        exit;
    }
}

/** Ban an IP address. */
function qotd_admin_ban_ip(): void
{
    qotd_admin_require_auth();
    qotd_require_csrf();
    qotd_init_db();

    $ipAddress = trim((string)($_POST['ip_address'] ?? ''));
    if ($ipAddress === '') {
        header('Location: /admin?message=' . rawurlencode('Enter an IP address to ban.'));
        exit;
    }

    qotd_ban_ip($ipAddress);
    $redirect = '/admin?message=' . rawurlencode('Banned ' . $ipAddress . '.');
    if (isset($_SERVER['HTTP_REFERER']) && str_contains((string)$_SERVER['HTTP_REFERER'], '/admin/queue')) {
        $redirect = '/admin/queue?message=' . rawurlencode('Banned ' . $ipAddress . '.');
    }
    header('Location: ' . $redirect);
    exit;
}

/** Log out and return to the login page. */
function qotd_admin_logout(): void
{
    qotd_logout_admin();
    header('Location: /admin/login');
    exit;
}

/** Route all admin requests. */
function qotd_handle_admin_request(string $path): void
{
    qotd_init_db();
    qotd_start_session();

    $clientIp = qotd_client_ip();
    if (qotd_is_banned_cached($clientIp) && !qotd_admin_logged_in()) {
        qotd_emit(qotd_error_page('Access denied', 'This IP address is blocked.'), 403);
    }

    if ($path === '/admin' || $path === '/admin/') {
        $message = (string)($_GET['message'] ?? '');
        qotd_admin_dashboard_page($message);
    }

    if ($path === '/admin/login') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            qotd_admin_login_submit();
        }
        qotd_admin_login_page((string)($_GET['message'] ?? ''));
    }

    if ($path === '/admin/logout') {
        qotd_admin_logout();
    }

    if ($path === '/admin/queue') {
        qotd_admin_queue_page();
    }

    if ($path === '/admin/logs') {
        qotd_admin_logs_page();
    }

    if ($path === '/admin/question' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        qotd_admin_save_question();
    }

    if (preg_match('#^/admin/queue/(\d+)/approve$#', $path, $m) === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        qotd_admin_approve_queue((int)$m[1]);
    }

    if (preg_match('#^/admin/queue/(\d+)/reject$#', $path, $m) === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        qotd_admin_reject_queue((int)$m[1]);
    }

    if (preg_match('#^/admin/replies/(\d+)/delete$#', $path, $m) === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        qotd_admin_delete_reply((int)$m[1]);
    }

    if ($path === '/admin/bans/add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        qotd_admin_ban_ip();
    }

    http_response_code(404);
    echo qotd_admin_shell('Not Found', '<main class="page"><section class="panel"><div class="panel-body">Unknown admin route.</div></section></main>');
    exit;
}
