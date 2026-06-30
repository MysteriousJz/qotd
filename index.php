<?php
declare(strict_types=1);

require_once __DIR__ . '/admin.php';

/** Send a full HTML response. */
function qotd_emit(string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/** Build a public notice block from query params. */
function qotd_public_message_html(string $message): string
{
    if ($message === '') {
        return '';
    }

    if ($message === 'submitted') {
        return qotd_notice('Your reply has been sent to moderation.', 'status');
    }

    return qotd_notice($message, 'status');
}

/** Build the HTML for a single date page. */
function qotd_date_page_html(string $date, string $message = '', int $replyToId = 0, string $draftContent = '', string $errorMessage = ''): string
{
    qotd_init_db();
    $question = qotd_question_for_date($date);
    $calendar = qotd_calendar_html(qotd_date_obj($date), qotd_question_dates_for_month(qotd_date_obj($date)), $date);
    $noticeHtml = qotd_public_message_html($message);
    if ($errorMessage !== '') {
        $noticeHtml .= qotd_notice($errorMessage, 'error');
    }

    if ($question === null) {
        return qotd_public_shell(
            APP_NAME,
            qotd_no_question_layout('No questions have been added yet. Please check back later.', $calendar, $noticeHtml)
        );
    }

    $replies = qotd_replies_for_question((int)$question['id']);
    $replyTarget = $replyToId > 0 ? qotd_reply_with_question_date($replyToId) : null;
    if ($replyTarget !== null && (int)$replyTarget['question_id'] !== (int)$question['id']) {
        $replyTarget = null;
    }
    $body = qotd_thread_layout($question, $replies, $date, $noticeHtml, $replyToId, $replyTarget, $draftContent, $calendar);
    return qotd_public_shell(APP_NAME . ' / ' . $date, $body);
}

/** Build the HTML for the homepage. */
function qotd_home_page_html(string $message = '', int $replyToId = 0, string $draftContent = '', string $errorMessage = ''): string
{
    $today = qotd_now()->format('Y-m-d');
    return qotd_date_page_html($today, $message, $replyToId, $draftContent, $errorMessage);
}

/** Process a public reply submission. */
function qotd_handle_public_submission(string $date): void
{
    qotd_init_db();
    $question = qotd_question_for_date($date);
    if ($question === null) {
        qotd_emit(qotd_error_page('Not found', 'That question does not exist.'), 404);
    }

    $clientIp = qotd_client_ip();
    $content = trim((string)($_POST['content'] ?? ''));
    $questionId = (int)($_POST['question_id'] ?? 0);
    $replyKind = (string)($_POST['reply_kind'] ?? 'question');
    $replyToInput = (int)($_POST['reply_to'] ?? 0);
    $replyToId = 0;

    if ($replyKind === 'post' && $replyToInput > 0) {
        $replyToId = $replyToInput;
    } elseif (preg_match('/^(?:>>|>)(\d+)/m', $content, $match) === 1) {
        $replyToId = (int)$match[1];
    }

    $errors = [];
    if ($questionId !== (int)$question['id']) {
        $errors[] = 'Please reload the page and try again.';
    }
    if ($content === '') {
        $errors[] = 'Content cannot be empty.';
    }
    if (mb_strlen($content) > MAX_POST_LENGTH) {
        $errors[] = 'Content is too long.';
    }
    if (qotd_is_banned_cached($clientIp)) {
        $errors[] = 'This IP address is blocked.';
    }
    $remaining = qotd_rate_limit_remaining($clientIp);
    if ($remaining > 0) {
        $errors[] = 'Please wait ' . $remaining . ' seconds between posts.';
    }

    $replyTarget = null;
    if ($replyToId > 0) {
        $replyTarget = qotd_reply_with_question_date($replyToId);
        if ($replyTarget === null || (int)$replyTarget['question_id'] !== (int)$question['id']) {
            $errors[] = 'That reply target is unavailable.';
        }
    }

    if ($errors !== []) {
        $calendar = qotd_calendar_html(qotd_date_obj($date), qotd_question_dates_for_month(qotd_date_obj($date)), $date);
        $notice = '';
        foreach ($errors as $error) {
            $notice .= qotd_notice($error, 'error');
        }
        $body = qotd_thread_layout($question, qotd_replies_for_question((int)$question['id']), $date, $notice, $replyToId, $replyTarget, $content, $calendar);
        qotd_emit(qotd_public_shell(APP_NAME . ' / ' . $date, $body));
    }

    $displayName = qotd_display_name_for_ip($clientIp);
    qotd_queue_reply((int)$question['id'], $content, $clientIp, $displayName, $replyToId > 0 ? $replyToId : null);
    qotd_add_log($clientIp, 'submitted', mb_substr($content, 0, 120));

    header('Location: ' . qotd_date_url($date, ['message' => 'submitted']));
    exit;
}

/** Cache a generated date page. */
function qotd_maybe_cache_date_page(string $date, string $html, bool $cacheable): void
{
    if (!$cacheable) {
        return;
    }

    file_put_contents(qotd_cache_file_for_date($date), $html, LOCK_EX);
}

/** Serve a cached date page if possible. */
function qotd_try_cached_date_page(string $date): bool
{
    $file = qotd_cache_file_for_date($date);
    if (!is_file($file)) {
        return false;
    }

    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    return true;
}

/** Render and optionally cache a date page. */
function qotd_render_date_route(string $date, bool $isHome = false): void
{
    $queryPresent = ($_SERVER['QUERY_STRING'] ?? '') !== '';
    $replyToId = (int)($_GET['reply_to'] ?? 0);
    $message = (string)($_GET['message'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        qotd_handle_public_submission($date);
    }

    if (!$queryPresent && $replyToId === 0 && $message === '' && !$isHome && qotd_try_cached_date_page($date)) {
        exit;
    }

    $cacheable = !$queryPresent && $replyToId === 0 && $message === '' && !$isHome;
    $draftContent = '';
    $errorMessage = '';

    qotd_init_db();
    $question = qotd_question_for_date($date);
    $calendar = qotd_calendar_html(qotd_date_obj($date), qotd_question_dates_for_month(qotd_date_obj($date)), $date);
    $noticeHtml = qotd_public_message_html($message);

    if ($question === null) {
        $html = qotd_public_shell(APP_NAME, qotd_no_question_layout('No questions have been added yet. Please check back later.', $calendar, $noticeHtml));
        qotd_maybe_cache_date_page($date, $html, $cacheable);
        qotd_emit($html);
    }

    $replyTarget = $replyToId > 0 ? qotd_reply_with_question_date($replyToId) : null;
    if ($replyTarget !== null && (int)$replyTarget['question_id'] !== (int)$question['id']) {
        $replyTarget = null;
    }
    $replies = qotd_replies_for_question((int)$question['id']);
    $body = qotd_thread_layout($question, $replies, $date, $noticeHtml, $replyToId, $replyTarget, $draftContent, $calendar);
    $html = qotd_public_shell(APP_NAME . ' / ' . $date, $body);
    qotd_maybe_cache_date_page($date, $html, $cacheable);
    qotd_emit($html);
}

/** Route public traffic. */
function qotd_handle_public_request(string $path): void
{
    qotd_ensure_runtime_dirs();
    qotd_start_session();

    $clientIp = qotd_client_ip();
    if (qotd_is_banned_cached($clientIp)) {
        qotd_emit(qotd_error_page('Access denied', 'This IP address is blocked.'), 403);
    }

    if ($path === '/' || $path === '/index.php') {
        qotd_render_date_route(qotd_now()->format('Y-m-d'), true);
    }

    if (preg_match('#^/date/(\d{4}-\d{2}-\d{2})$#', $path, $match) === 1) {
        $date = qotd_normalize_date($match[1]);
        if ($date === null) {
            qotd_emit(qotd_error_page('Not found', 'That page does not exist.'), 404);
        }
        qotd_render_date_route($date, false);
    }

    qotd_emit(qotd_error_page('Not found', 'That page does not exist.'), 404);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/admin')) {
    qotd_handle_admin_request($path);
}

qotd_handle_public_request($path);
