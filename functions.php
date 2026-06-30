<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Ensure runtime directories exist. */
function qotd_ensure_runtime_dirs(): void
{
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0700, true);
    }
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0700, true);
    }
    if (is_dir($dbDir)) {
        chmod($dbDir, 0700);
    }
    if (is_dir(CACHE_DIR)) {
        chmod(CACHE_DIR, 0700);
    }
}

/** HTML-escape output safely. */
function qotd_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Get the current UTC time. */
function qotd_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

/** Validate a YYYY-MM-DD date string. */
function qotd_normalize_date(?string $date): ?string
{
    if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if ($dt === false) {
        return null;
    }

    return $dt->format('Y-m-d');
}

/** Parse a date into an immutable object. */
function qotd_date_obj(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date, new DateTimeZone('UTC'));
}

/** Build an absolute date page URL. */
function qotd_date_url(string $date, array $query = []): string
{
    $path = '/date/' . $date;
    if ($query) {
        $path .= '?' . http_build_query($query);
    }
    return $path;
}

/** Get the client's IP address. */
function qotd_client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwarded) && trim($forwarded) !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim($parts[0]);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return is_string($remote) && $remote !== '' ? $remote : 'unknown';
}

/** Deterministic anonymous ID per IP. */
function qotd_anonymous_id(string $ip): string
{
    return substr(hash('sha256', $ip . qotd_anonymous_salt()), 0, 8);
}

/** Load or create the anonymous ID salt. */
function qotd_anonymous_salt(): string
{
    qotd_ensure_runtime_dirs();
    if (is_file(ANON_SALT_FILE)) {
        $salt = trim((string)file_get_contents(ANON_SALT_FILE));
        if ($salt !== '') {
            return $salt;
        }
    }

    $salt = bin2hex(random_bytes(32));
    file_put_contents(ANON_SALT_FILE, $salt, LOCK_EX);
    if (is_file(ANON_SALT_FILE)) {
        chmod(ANON_SALT_FILE, 0600);
    }
    return $salt;
}

/** Display name used for anonymous posts. */
function qotd_display_name_for_ip(string $ip): string
{
    return 'Anonymous (ID: ' . qotd_anonymous_id($ip) . ')';
}

/** Textarea prefill for reply references. */
function qotd_reply_prefix(?int $replyTo): string
{
    return $replyTo ? '>>' . $replyTo . "\n" : '';
}

/** Convert plain text content to HTML with quote links. */
function qotd_content_to_html(string $content): string
{
    $escaped = qotd_h($content);
    $escaped = preg_replace_callback(
        '/&gt;&gt;(\d+)|&gt;(\d+)/',
        static function (array $match): string {
            $id = !empty($match[1]) ? $match[1] : $match[2];
            return '<a class="quote-link" href="#post-' . $id . '">&gt;' . $id . '</a>';
        },
        $escaped
    );

    return nl2br((string)$escaped, false);
}

/** Render a UTC timestamp for display. */
function qotd_format_timestamp(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i');
    } catch (Throwable) {
        return $value;
    }
}

/** Track whether the current user is an admin. */
function qotd_admin_logged_in(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

/** Start the admin session with safer cookie defaults. */
function qotd_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

/** Create a CSRF token if needed. */
function qotd_csrf_token(): string
{
    qotd_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf_token'];
}

/** Hidden CSRF input field. */
function qotd_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . qotd_h(qotd_csrf_token()) . '">';
}

/** Verify CSRF for POST admin actions. */
function qotd_require_csrf(): void
{
    qotd_start_session();
    $sent = (string)($_POST['csrf_token'] ?? '');
    $known = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(403);
        echo qotd_public_shell('Forbidden', '<div class="message error">Invalid security token.</div>');
        exit;
    }
}

/** Keep admin sessions from lingering forever. */
function qotd_admin_session_expired(): bool
{
    qotd_start_session();
    $last = (int)($_SESSION['admin_last_activity'] ?? 0);
    return $last > 0 && (time() - $last) > ADMIN_SESSION_TIMEOUT;
}

/** Refresh admin inactivity timer. */
function qotd_touch_admin_session(): void
{
    qotd_start_session();
    $_SESSION['admin_last_activity'] = time();
}

/** Log a user out. */
function qotd_logout_admin(): void
{
    qotd_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 1, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

/** Current page title shell for public pages. */
function qotd_public_shell(string $title, string $body): string
{
    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . qotd_h($title) . '</title>'
        . '<link rel="stylesheet" href="/css/style.css"></head><body>'
        . '<div class="site"><header class="site-header">'
        . '<div class="site-title">Question of the Day</div>'
        . '<div class="site-subtitle">anonymous discussion board / no javascript / UTC</div>'
        . '<nav class="site-nav"><a href="/">Home</a><a href="/admin">Admin</a></nav>'
        . '</header>'
        . $body
        . '</div></body></html>';
}

/** Current page title shell for admin pages. */
function qotd_admin_shell(string $title, string $body): string
{
    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . qotd_h($title) . '</title>'
        . '<link rel="stylesheet" href="/css/style.css"></head><body>'
        . '<div class="site"><header class="site-header">'
        . '<div class="site-title">QOTD Admin</div>'
        . '<div class="site-subtitle">moderation console</div>'
        . '<nav class="site-nav"><a href="/">Home</a><a href="/admin">Dashboard</a><a href="/admin/queue">Queue</a><a href="/admin/logs">Logs</a><a href="/admin/logout">Logout</a></nav>'
        . '</header>'
        . $body
        . '</div></body></html>';
}

/** Lightweight notice helper. */
function qotd_notice(string $message, string $kind = 'status'): string
{
    return '<div class="message ' . qotd_h($kind) . '">' . qotd_h($message) . '</div>';
}

/** File path for a cached date page. */
function qotd_cache_file_for_date(string $date): string
{
    return rtrim(CACHE_DIR, '/') . '/date_' . $date . '.html';
}

/** Remove a single cached date page. */
function qotd_invalidate_date_cache(string $date): void
{
    $file = qotd_cache_file_for_date($date);
    if (is_file($file)) {
        unlink($file);
    }
}

/** Remove all cached pages for the same month. */
function qotd_invalidate_month_cache(string $date): void
{
    $prefix = substr($date, 0, 7);
    foreach (glob(rtrim(CACHE_DIR, '/') . '/date_' . $prefix . '-*.html') ?: [] as $file) {
        unlink($file);
    }
}

/** File path for the cached banned-IP list. */
function qotd_ban_cache_file(): string
{
    return rtrim(CACHE_DIR, '/') . '/bans.json';
}

/** Load banned IPs from the cache file. */
function qotd_load_banned_ips(): array
{
    $file = qotd_ban_cache_file();
    if (!is_file($file)) {
        return [];
    }

    $json = file_get_contents($file);
    if ($json === false || $json === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $ips = array_map('strval', $data);
    $ips = array_filter($ips, static fn (string $ip): bool => $ip !== '');
    return array_values($ips);
}

/** Refresh the banned-IP cache from the database. */
function qotd_refresh_ban_cache(SQLite3 $db): void
{
    $rows = qotd_query_all($db, 'SELECT ip_address FROM bans ORDER BY ip_address ASC');
    $ips = array_map(static fn (array $row): string => (string)$row['ip_address'], $rows);
    file_put_contents(qotd_ban_cache_file(), json_encode($ips, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]', LOCK_EX);
}

/** Check a cached banned-IP list. */
function qotd_is_banned_cached(string $ip): bool
{
    return in_array($ip, qotd_load_banned_ips(), true);
}

/** Create the markup for the main calendar. */
function qotd_calendar_html(DateTimeImmutable $month, array $questionDates, string $activeDate): string
{
    $first = $month->modify('first day of this month');
    $prev = $first->modify('-1 month');
    $next = $first->modify('+1 month');
    $monthTitle = $first->format('F Y');
    $startDow = (int)$first->format('w');
    $daysInMonth = (int)$first->format('t');
    $today = qotd_now()->format('Y-m-d');
    $questionMap = array_fill_keys($questionDates, true);

    $html = '<section class="panel calendar-panel"><div class="panel-head">Calendar</div><div class="panel-body">';
    $html .= '<div class="calendar-nav"><a href="' . qotd_h(qotd_date_url($prev->format('Y-m-01'))) . '">&laquo; Prev</a>';
    $html .= '<span class="calendar-title">' . qotd_h($monthTitle) . '</span>';
    $html .= '<a href="' . qotd_h(qotd_date_url($next->format('Y-m-01'))) . '">Next &raquo;</a></div>';
    $html .= '<table class="calendar"><thead><tr>';
    foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day) {
        $html .= '<th>' . $day . '</th>';
    }
    $html .= '</tr></thead><tbody><tr>';

    $cell = 0;
    for ($i = 0; $i < $startDow; $i++) {
        $html .= '<td class="empty"></td>';
        $cell++;
    }

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = $first->setDate((int)$first->format('Y'), (int)$first->format('m'), $day)->format('Y-m-d');
        $classes = ['day'];
        if ($date === $today) {
            $classes[] = 'today';
        }
        if ($date === $activeDate) {
            $classes[] = 'active';
        }
        if (isset($questionMap[$date])) {
            $html .= '<td class="' . implode(' ', $classes) . '"><a href="' . qotd_h(qotd_date_url($date)) . '">' . $day . '</a></td>';
        } else {
            $html .= '<td class="' . implode(' ', $classes) . '"><span>' . $day . '</span></td>';
        }
        $cell++;
        if ($cell % 7 === 0 && $day < $daysInMonth) {
            $html .= '</tr><tr>';
        }
    }

    while ($cell % 7 !== 0) {
        $html .= '<td class="empty"></td>';
        $cell++;
    }

    $html .= '</tr></tbody></table></div></section>';
    return $html;
}

/** Render a question card. */
function qotd_question_card(array $question): string
{
    return '<section class="panel question-panel">'
        . '<div class="panel-head">'
        . '<span class="panel-kicker">Question for ' . qotd_h((string)$question['date']) . '</span>'
        . '<span class="panel-meta">No.' . qotd_h((string)$question['id']) . ' / ' . qotd_h(qotd_format_timestamp((string)$question['created_at'])) . '</span>'
        . '</div>'
        . '<div class="panel-body question-text">' . qotd_content_to_html((string)$question['question_text']) . '</div>'
        . '</section>';
}

/** Render an empty-state card. */
function qotd_empty_state(string $message): string
{
    return '<section class="panel"><div class="panel-body">' . qotd_h($message) . '</div></section>';
}

/** Group replies by parent reply_to. */
function qotd_reply_tree(array $replies): array
{
    $byId = [];
    $children = [];

    foreach ($replies as $reply) {
        $reply['id'] = (int)$reply['id'];
        $reply['reply_to'] = $reply['reply_to'] !== null ? (int)$reply['reply_to'] : null;
        $byId[$reply['id']] = $reply;
    }

    foreach ($byId as $reply) {
        $parentId = $reply['reply_to'] ?? 0;
        if ($parentId > 0) {
            $children[$parentId][] = $reply['id'];
        }
    }

    $sorter = static function (array $ids) use ($byId): array {
        usort($ids, static function (int $a, int $b) use ($byId): int {
            $left = $byId[$a]['created_at'];
            $right = $byId[$b]['created_at'];
            if ($left === $right) {
                return $b <=> $a;
            }
            return strcmp((string)$right, (string)$left);
        });
        return $ids;
    };

    foreach ($children as $parentId => $ids) {
        $children[$parentId] = $sorter($ids);
    }

    $roots = [];
    foreach ($byId as $reply) {
        if (empty($reply['reply_to'])) {
            $roots[] = $reply['id'];
        }
    }
    $roots = $sorter($roots);

    return [$byId, $children, $roots];
}

/** Render a single reply card, including nested discussion. */
function qotd_render_reply_card(array $reply, string $questionDate, array $byId, array $children, int $depth = 0, bool $includeChildren = true): string
{
    $replyId = (int)$reply['id'];
    $replyTo = !empty($reply['reply_to']) ? (int)$reply['reply_to'] : null;
    $isDiscussion = $depth > 0 || $replyTo !== null;
    $replyToLine = '';

    if ($replyTo !== null) {
        $replyToLine = '<div class="reply-to">In reply to <a href="#post-' . $replyTo . '">#' . $replyTo . '</a></div>';
    }

    $childHtml = '';
    if ($includeChildren && !empty($children[$replyId])) {
        foreach ($children[$replyId] as $childId) {
            $childHtml .= qotd_render_reply_card($byId[$childId], $questionDate, $byId, $children, $depth + 1, true);
        }
    }

    $classes = ['post'];
    $classes[] = $isDiscussion ? 'discussion' : 'answer';
    if ($depth > 0) {
        $classes[] = 'nested';
    }

    return '<article class="' . implode(' ', $classes) . '" id="post-' . $replyId . '">'
        . '<div class="post-head">'
        . '<div class="post-author">' . qotd_h((string)$reply['display_name']) . '</div>'
        . '<div class="post-meta">No.' . $replyId . ' / ' . qotd_h(qotd_format_timestamp((string)$reply['created_at'])) . '</div>'
        . '</div>'
        . '<div class="post-body">'
        . $replyToLine
        . '<div class="post-content">' . qotd_content_to_html((string)$reply['content']) . '</div>'
        . '<div class="post-actions"><a href="' . qotd_h(qotd_date_url($questionDate, ['reply_to' => $replyId])) . '#reply-form">Reply</a></div>'
        . $childHtml
        . '</div>'
        . '</article>';
}

/** Render a post quote preview for the reply form. */
function qotd_quote_preview(array $reply): string
{
    return '<section class="quote-preview">'
        . '<div class="quote-preview-head">Replying to #' . qotd_h((string)$reply['id']) . ' / ' . qotd_h((string)$reply['display_name']) . '</div>'
        . '<div class="quote-preview-body">' . qotd_content_to_html((string)$reply['content']) . '</div>'
        . '</section>';
}

/** Reply form markup. */
function qotd_reply_form(array $question, int $replyToId = 0, ?array $replyTarget = null, string $message = '', string $draftContent = ''): string
{
    $targetId = $replyToId > 0 ? $replyToId : ($replyTarget ? (int)$replyTarget['id'] : 0);
    $replyKind = $targetId > 0 ? 'post' : 'question';
    $prefill = $draftContent !== '' ? $draftContent : qotd_reply_prefix($targetId > 0 ? $targetId : null);

    $html = '<section class="panel reply-panel" id="reply-form"><div class="panel-head">Reply</div><div class="panel-body">';
    if ($message !== '') {
        $html .= $message;
    }
    if ($replyTarget) {
        $html .= qotd_quote_preview($replyTarget);
    }
    $html .= '<form method="post" action="' . qotd_h(qotd_date_url((string)$question['date'])) . '">';
    $html .= qotd_csrf_field();
    $html .= '<input type="hidden" name="question_id" value="' . qotd_h((string)$question['id']) . '">';
    $html .= '<div class="target-grid">';
    $html .= '<label><input type="radio" name="reply_kind" value="question"' . ($replyKind === 'question' ? ' checked' : '') . '> Reply to question</label>';
    $html .= '<label><input type="radio" name="reply_kind" value="post"' . ($replyKind === 'post' ? ' checked' : '') . '> Reply to specific post #</label>';
    $html .= '<input type="number" name="reply_to" min="0" step="1" value="' . ($targetId > 0 ? $targetId : '') . '" placeholder="123">';
    $html .= '</div>';
    $html .= '<label for="content">Content</label>';
    $html .= '<textarea id="content" name="content" maxlength="' . MAX_POST_LENGTH . '" placeholder=">123 to reference a post">' . qotd_h($prefill) . '</textarea>';
    $html .= '<div class="form-actions"><button type="submit">Submit for moderation</button></div>';
    $html .= '</form></div></section>';

    return $html;
}

/** Render the page column layout for a question date. */
function qotd_thread_layout(array $question, array $replies, string $date, string $message = '', int $replyToId = 0, ?array $replyTarget = null, string $draftContent = '', string $calendarHtml = ''): string
{
    [$byId, $children, $roots] = qotd_reply_tree($replies);

    $answerHtml = '';
    foreach ($roots as $rootId) {
        $answerHtml .= qotd_render_reply_card($byId[$rootId], $date, $byId, $children, 0, false);
    }

    if ($answerHtml === '') {
        $answerHtml = '<div class="empty-column">No approved replies yet.</div>';
    }

    $discussionHtml = '';
    foreach ($roots as $rootId) {
        $reply = $byId[$rootId];
        if (!empty($children[$rootId])) {
            foreach ($children[$rootId] as $childId) {
                $discussionHtml .= qotd_render_reply_card($byId[$childId], $date, $byId, $children, 1, true);
            }
        }
    }

    if ($discussionHtml === '') {
        $discussionHtml = '<div class="empty-column">Discussion shows up here once a reply targets a post.</div>';
    }

    $questionCard = qotd_question_card($question);
    $replyForm = qotd_reply_form($question, $replyToId, $replyTarget, $message, $draftContent);

    return '<main class="page">'
        . $questionCard
        . '<section class="panel"><div class="panel-head">Board</div><div class="panel-body two-column">'
        . '<div class="column answers"><div class="column-title">Answers</div>' . $answerHtml . '</div>'
        . '<div class="column discussion"><div class="column-title">Discussion</div>' . $discussionHtml . '</div>'
        . '</div></section>'
        . $replyForm
        . $calendarHtml
        . '</main>';
}

/** Render a no-question state with the calendar. */
function qotd_no_question_layout(string $message, string $calendarHtml, string $noticeHtml = ''): string
{
    return '<main class="page">'
        . $noticeHtml
        . '<section class="panel"><div class="panel-head">Today</div><div class="panel-body">' . qotd_h($message) . '</div></section>'
        . $calendarHtml
        . '</main>';
}

/** Render a simple error page. */
function qotd_error_page(string $title, string $message): string
{
    return qotd_public_shell($title, '<main class="page"><section class="panel"><div class="panel-body">' . qotd_h($message) . '</div></section></main>');
}
