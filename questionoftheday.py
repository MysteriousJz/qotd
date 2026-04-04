import hashlib
import html
import os
import random
import re
import secrets
import sqlite3
import warnings
from datetime import datetime, timezone
from functools import wraps

from flask import (
    Flask,
    abort,
    g,
    redirect,
    render_template_string,
    request,
    session,
    url_for,
)
from markupsafe import Markup

app = Flask(__name__)

_secret_key = os.environ.get("SECRET_KEY")
if not _secret_key:
    warnings.warn(
        "SECRET_KEY environment variable is not set. Using a random key — sessions will not persist across restarts.",
        stacklevel=1,
    )
    _secret_key = secrets.token_hex(32)
app.secret_key = _secret_key

DATABASE = os.environ.get("DATABASE", "qotd.db")
ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD", "")
if not ADMIN_PASSWORD:
    warnings.warn(
        "ADMIN_PASSWORD environment variable is not set. Admin login will be disabled.",
        stacklevel=1,
    )

MAX_POST_LENGTH = 4000
RATE_LIMIT_SECONDS = 30
DEFAULT_OP_TEXT = "What is your question for the board today?"
LOG_LIMIT = 200

CSS = """
body {
    margin: 0;
    padding: 8px;
    background: #eef2ff;
    color: #000;
    font-family: monospace;
    font-size: 15px;
    line-height: 1.35;
}
.main {
    max-width: 760px;
    margin: 0 auto;
}
.header {
    border-bottom: 1px solid #b7c5d9;
    margin-bottom: 10px;
    padding-bottom: 6px;
}
.board-title {
    font-size: 24px;
    font-weight: bold;
    color: #af0a0f;
}
.board-subtitle {
    font-size: 13px;
    color: #333;
}
.nav {
    margin: 8px 0 12px;
}
.nav a {
    color: #34345c;
    text-decoration: underline;
    margin-right: 10px;
}
.status, .error, .success {
    border: 1px solid #b7c5d9;
    padding: 6px 8px;
    margin: 10px 0;
    background: #f7f7f7;
}
.error {
    border-color: #af0a0f;
    color: #af0a0f;
    background: #fff3f3;
}
.success {
    border-color: #117743;
    color: #117743;
    background: #f3fff7;
}
.op, .post, .panel, .form-wrap {
    border: 1px solid #b7c5d9;
    background: #f8fafe;
    margin-bottom: 10px;
}
.op {
    background: #f0e0d6;
}
.post-head, .panel-head {
    padding: 4px 6px;
    border-bottom: 1px solid #b7c5d9;
    background: #d6daf0;
    font-size: 13px;
}
.op .post-head {
    background: #e4cfc1;
}
.post-body, .panel-body {
    padding: 8px;
    overflow-wrap: anywhere;
}
.post-body p, .panel-body p, blockquote {
    margin: 0 0 8px;
}
blockquote {
    white-space: normal;
}
.post-meta, .small, .logline {
    font-size: 12px;
    color: #333;
}
.quote {
    color: #789922;
    text-decoration: none;
}
.quote:hover {
    text-decoration: underline;
}
.reply-link, .danger-link {
    font-size: 12px;
}
.reply-link {
    color: #34345c;
}
.danger-link {
    color: #af0a0f;
}
textarea, input[type="text"], input[type="password"] {
    width: 100%;
    box-sizing: border-box;
    font-family: monospace;
    font-size: 15px;
    border: 1px solid #888;
    background: #fff;
    color: #000;
    padding: 6px;
    margin: 4px 0 8px;
}
textarea {
    min-height: 130px;
    resize: vertical;
}
button, input[type="submit"] {
    border: 1px solid #888;
    background: #e6e6e6;
    color: #000;
    font-family: monospace;
    font-size: 14px;
    padding: 6px 10px;
}
button:hover, input[type="submit"]:hover {
    background: #ddd;
}
.inline-form {
    display: inline;
}
.admin-actions {
    margin-top: 6px;
}
.code {
    background: #fff;
    border: 1px solid #ccc;
    padding: 1px 3px;
}
.listing {
    margin: 0;
    padding-left: 18px;
}
.footer-note {
    font-size: 12px;
    color: #444;
    margin: 12px 0;
}
@media (max-width: 600px) {
    body {
        padding: 6px;
        font-size: 14px;
    }
    .board-title {
        font-size: 20px;
    }
    textarea, input[type="text"], input[type="password"] {
        font-size: 16px;
    }
    button, input[type="submit"] {
        width: 100%;
        margin-bottom: 6px;
    }
    .inline-form button {
        width: auto;
        margin-bottom: 0;
    }
}
"""

PUBLIC_TEMPLATE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ title }}</title>
<style>{{ css }}</style>
</head>
<body>
<div class="main">
    <div class="header">
        <div class="board-title">/qotd/</div>
        <div class="board-subtitle">one thread / one question / text only</div>
    </div>
    <div class="nav">
        <a href="/">Thread</a>
        <a href="/submit">Post</a>
        <a href="/admin/login">Admin</a>
    </div>
    {{ body }}
</div>
</body>
</html>"""

ADMIN_TEMPLATE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ title }}</title>
<style>{{ css }}</style>
</head>
<body>
<div class="main">
    <div class="header">
        <div class="board-title">/qotd/ admin</div>
        <div class="board-subtitle">moderation console</div>
    </div>
    <div class="nav">
        <a href="/">Thread</a>
        <a href="/admin">Dashboard</a>
        <a href="/admin/queue">Queue</a>
        <a href="/admin/logs">Logs</a>
        <a href="/admin/logout">Logout</a>
    </div>
    {{ body }}
</div>
</body>
</html>"""


def _render_page(title: str, body: str) -> str:
    return render_template_string(
        PUBLIC_TEMPLATE,
        title=title,
        body=Markup(body),
        css=Markup(CSS),
    )


def _render_admin_page(title: str, body: str) -> str:
    return render_template_string(
        ADMIN_TEMPLATE,
        title=title,
        body=Markup(body),
        css=Markup(CSS),
    )


def _esc(text: str) -> str:
    return html.escape(text, quote=True)


def _format_timestamp(value: str) -> str:
    try:
        return datetime.fromisoformat(value).strftime("%Y-%m-%d %H:%M")
    except ValueError:
        return value


def _parse_timestamp(value: str) -> datetime:
    try:
        dt = datetime.fromisoformat(value)
    except ValueError:
        dt = datetime.strptime(value, "%Y-%m-%d %H:%M:%S")
    if dt.tzinfo is not None:
        return dt.astimezone(timezone.utc).replace(tzinfo=None)
    return dt


def _utc_now_naive() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


def _text_to_html(content: str) -> str:
    escaped = _esc(content)
    escaped = _linkify_post_references(escaped)
    return escaped.replace("\n", "<br>")


def _linkify_post_references(text: str) -> str:
    return re.sub(
        r"(?:&gt;&gt;|&gt;)(\d+)",
        lambda match: (
            f'<a class="quote" href="#post-{match.group(1)}">&gt;{match.group(1)}</a>'
        ),
        text,
    )


def _client_ip() -> str:
    forwarded = request.headers.get("X-Forwarded-For", "").strip()
    if forwarded:
        return forwarded.split(",")[0].strip()
    return request.remote_addr or "unknown"


def _user_hash(ip_address: str) -> str:
    return hashlib.sha256(f"{ip_address}:{app.secret_key}".encode()).hexdigest()[:16]


def _anonymous_id() -> str:
    anon_id = session.get("anonymous_id")
    if not anon_id:
        anon_id = f"No.{secrets.randbelow(900000) + 100000}"
        session["anonymous_id"] = anon_id
    return anon_id


def get_identity():
    ip_address = _client_ip()
    return _anonymous_id(), _user_hash(ip_address), ip_address


def get_db():
    if "db" not in g:
        g.db = sqlite3.connect(DATABASE)
        g.db.row_factory = sqlite3.Row
        g.db.execute("PRAGMA foreign_keys=ON")
        g.db.execute("PRAGMA journal_mode=WAL")
    return g.db


@app.teardown_appcontext
def close_db(exc):
    db = g.pop("db", None)
    if db is not None:
        db.close()


def _table_exists(db: sqlite3.Connection, name: str) -> bool:
    row = db.execute(
        "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
        (name,),
    ).fetchone()
    return row is not None


def _columns(db: sqlite3.Connection, table_name: str) -> set[str]:
    if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", table_name):
        raise ValueError("Invalid table name")
    rows = db.execute(f"PRAGMA table_info({table_name})").fetchall()
    return {row["name"] for row in rows}


def _create_schema(db: sqlite3.Connection) -> None:
    db.executescript(
        """
        CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question_text TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question_id INTEGER NOT NULL,
            user_hash TEXT NOT NULL,
            display_name TEXT NOT NULL,
            content TEXT NOT NULL,
            reply_to INTEGER,
            approved INTEGER NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (question_id) REFERENCES questions(id)
        );

        CREATE TABLE IF NOT EXISTS queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question_id INTEGER NOT NULL,
            user_hash TEXT NOT NULL,
            display_name TEXT NOT NULL,
            content TEXT NOT NULL,
            reply_to INTEGER,
            ip_address TEXT NOT NULL,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (question_id) REFERENCES questions(id)
        );

        CREATE TABLE IF NOT EXISTS bans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT UNIQUE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS post_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            anonymous_id TEXT NOT NULL,
            content_preview TEXT NOT NULL,
            action TEXT NOT NULL,
            post_id INTEGER,
            queue_id INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        """
    )


def _migrate_legacy_schema(db: sqlite3.Connection) -> None:
    if not _table_exists(db, "questions"):
        return
    question_columns = _columns(db, "questions")
    if "date" not in question_columns:
        return

    db.execute("PRAGMA foreign_keys=OFF")
    db.execute("DROP TABLE IF EXISTS posts_legacy")
    db.execute("DROP TABLE IF EXISTS queue_legacy")
    db.execute("DROP TABLE IF EXISTS questions_legacy")
    db.execute("ALTER TABLE questions RENAME TO questions_legacy")
    db.execute("ALTER TABLE posts RENAME TO posts_legacy")
    db.execute("ALTER TABLE queue RENAME TO queue_legacy")
    db.execute("PRAGMA foreign_keys=ON")

    _create_schema(db)

    legacy_question = db.execute(
        "SELECT * FROM questions_legacy ORDER BY id DESC LIMIT 1"
    ).fetchone()
    if legacy_question:
        cur = db.execute(
            "INSERT INTO questions (question_text, is_active, created_at) VALUES (?, 1, ?)",
            (legacy_question["question_text"], legacy_question["created_at"]),
        )
        active_question_id = cur.lastrowid
        db.execute(
            """
            INSERT INTO posts (
                question_id, user_hash, display_name, content, reply_to, approved, created_at
            )
            SELECT ?, user_hash, display_name, content, reply_to, 1, created_at
            FROM posts_legacy
            WHERE question_id = ? AND approved = 1
            ORDER BY id ASC
            """,
            (active_question_id, legacy_question["id"]),
        )
        db.execute(
            """
            INSERT INTO queue (
                question_id, user_hash, display_name, content, reply_to, ip_address, submitted_at
            )
            SELECT ?, user_hash, display_name, content, reply_to, 'unknown', submitted_at
            FROM queue_legacy
            WHERE question_id = ?
            ORDER BY id ASC
            """,
            (active_question_id, legacy_question["id"]),
        )


def init_db() -> None:
    db = get_db()
    _migrate_legacy_schema(db)
    _create_schema(db)
    if "ip_address" not in _columns(db, "queue"):
        db.execute("ALTER TABLE queue ADD COLUMN ip_address TEXT NOT NULL DEFAULT 'unknown'")
    active = db.execute(
        "SELECT id FROM questions WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
    ).fetchone()
    if active is None:
        db.execute("UPDATE questions SET is_active = 0")
        db.execute(
            "INSERT INTO questions (question_text, is_active) VALUES (?, 1)",
            (DEFAULT_OP_TEXT,),
        )
    db.commit()


def current_question(db: sqlite3.Connection) -> sqlite3.Row:
    question = db.execute(
        "SELECT * FROM questions WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
    ).fetchone()
    if question is None:
        init_db()
        question = db.execute(
            "SELECT * FROM questions WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
        ).fetchone()
    return question


def admin_required(func):
    @wraps(func)
    def wrapped(*args, **kwargs):
        if not session.get("admin"):
            return redirect(url_for("admin_login"))
        return func(*args, **kwargs)

    return wrapped


def _post_notice(message: str, kind: str = "status") -> str:
    return f'<div class="{kind}">{_esc(message)}</div>' if message else ""


def _render_post(post: sqlite3.Row) -> str:
    reply_line = ""
    if post["reply_to"]:
        reply_line = (
            f'<div class="small">Ref: '
            f'<a class="quote" href="#post-{post["reply_to"]}">&gt;{post["reply_to"]}</a></div>'
        )
    return (
        f'<div class="post" id="post-{post["id"]}">'
        f'<div class="post-head"><strong>Anonymous {_esc(post["display_name"])}</strong> '
        f'<span class="post-meta">{_format_timestamp(post["created_at"])} No.{post["id"]}</span></div>'
        f'<div class="post-body">{reply_line}<p>{_text_to_html(post["content"])}</p>'
        f'<div class="small"><a class="reply-link" href="/submit?reply_to={post["id"]}">Reply</a></div>'
        '</div></div>'
    )


def _thread_html(
    question: sqlite3.Row,
    posts,
    anonymous_id: str,
    notice: str = "",
    reply_to: int | None = None,
    form_error: str = "",
) -> str:
    op_html = (
        '<div class="op" id="op">'
        '<div class="post-head"><strong>OP</strong> '
        f'<span class="post-meta">{_format_timestamp(question["created_at"])} / current prompt</span></div>'
        f'<div class="post-body"><blockquote>{_text_to_html(question["question_text"])}</blockquote></div>'
        '</div>'
    )
    posts_html = "".join(_render_post(post) for post in posts) or (
        '<div class="panel"><div class="panel-body">No approved replies yet.</div></div>'
    )
    form_html = _submit_form(question, anonymous_id, reply_to=reply_to, error=form_error)
    return notice + op_html + posts_html + form_html + (
        '<div class="footer-note">Classic rules: text only, one board, one thread, one chance every 30 seconds.</div>'
    )


def _submit_form(
    question: sqlite3.Row,
    anonymous_id: str,
    reply_to: int | None = None,
    error: str = "",
) -> str:
    reply_prefix = f">{reply_to}\n" if reply_to else ""
    reply_field = (
        f'<input type="hidden" name="reply_to" value="{reply_to}">' if reply_to else ""
    )
    return (
        f'{error}<div class="form-wrap" id="post-form">'
        '<div class="panel-head"><strong>Post a Reply</strong></div>'
        '<div class="panel-body">'
        f'<div class="small">Session ID: <span class="code">{_esc(anonymous_id)}</span></div>'
        f'<div class="small">Current OP: {_esc(question["question_text"][:120])}</div>'
        '<form method="post" action="/submit">'
        f'<input type="hidden" name="question_id" value="{question["id"]}">'
        f'{reply_field}'
        '<label for="content">Text</label>'
        f'<textarea id="content" name="content" maxlength="{MAX_POST_LENGTH}" '
        'placeholder=">123 to reference another reply">'
        f'{_esc(reply_prefix)}</textarea>'
        f'<input type="submit" value="Submit to moderation">'
        '</form></div></div>'
    )


def _rate_limit_remaining(db: sqlite3.Connection, ip_address: str) -> int:
    row = db.execute(
        """
        SELECT created_at FROM post_logs
        WHERE ip_address = ? AND action = 'submitted'
        ORDER BY id DESC LIMIT 1
        """,
        (ip_address,),
    ).fetchone()
    if row is None:
        return 0
    elapsed = _utc_now_naive() - _parse_timestamp(row["created_at"])
    remaining = RATE_LIMIT_SECONDS - int(elapsed.total_seconds())
    return remaining if remaining > 0 else 0


def _is_banned(db: sqlite3.Connection, ip_address: str) -> bool:
    return db.execute(
        "SELECT 1 FROM bans WHERE ip_address = ?",
        (ip_address,),
    ).fetchone() is not None


def _log_event(
    db: sqlite3.Connection,
    ip_address: str,
    anonymous_id: str,
    action: str,
    content: str = "",
    post_id: int | None = None,
    queue_id: int | None = None,
) -> None:
    db.execute(
        """
        INSERT INTO post_logs (ip_address, anonymous_id, content_preview, action, post_id, queue_id)
        VALUES (?, ?, ?, ?, ?, ?)
        """,
        (ip_address, anonymous_id, content[:120], action, post_id, queue_id),
    )


def _extract_reply_to(content: str, explicit_reply_to: int | None) -> int | None:
    if explicit_reply_to:
        return explicit_reply_to
    for raw_line in content.splitlines():
        line = raw_line.lstrip()
        if line.startswith(">>"):
            candidate = line[2:]
        elif line.startswith(">"):
            candidate = line[1:]
        else:
            continue
        digits = []
        for char in candidate:
            if char.isdigit():
                digits.append(char)
            else:
                break
        if digits:
            return int("".join(digits))
    return None


@app.route("/")
def index():
    init_db()
    db = get_db()
    question = current_question(db)
    posts = db.execute(
        "SELECT * FROM posts WHERE approved = 1 AND question_id = ? ORDER BY id ASC",
        (question["id"],),
    ).fetchall()
    anonymous_id, _, _ = get_identity()
    message = request.args.get("message", "")
    notice = ""
    if message == "submitted":
        notice = _post_notice("Your reply has been sent to the moderation queue.", "success")
    body = _thread_html(
        question,
        posts,
        anonymous_id,
        notice=notice,
        reply_to=request.args.get("reply_to", type=int),
    )
    return _render_page("/qotd/", body)


@app.route("/submit", methods=["GET", "POST"])
def submit():
    init_db()
    db = get_db()
    question = current_question(db)
    anonymous_id, user_hash, ip_address = get_identity()
    reply_to_param = request.args.get("reply_to", type=int)

    if request.method == "POST":
        content = request.form.get("content", "").strip()
        question_id = request.form.get("question_id", type=int)
        reply_to = _extract_reply_to(content, request.form.get("reply_to", type=int))
        errors = []
        banned = _is_banned(db, ip_address)

        if question_id != question["id"]:
            errors.append("The board changed while you were typing. Reload and try again.")
        if banned:
            errors.append("Your IP address is banned from posting.")
        remaining = _rate_limit_remaining(db, ip_address)
        if remaining:
            errors.append(f"Slow down. Wait {remaining} seconds before posting again.")
        if not content:
            errors.append("Post content cannot be empty.")
        if len(content) > MAX_POST_LENGTH:
            errors.append(f"Post is too long (max {MAX_POST_LENGTH} characters).")
        if reply_to is not None:
            exists = db.execute(
                "SELECT 1 FROM posts WHERE id = ? AND approved = 1",
                (reply_to,),
            ).fetchone()
            if exists is None:
                errors.append("That reply target does not exist yet.")

        if errors:
            if banned:
                _log_event(db, ip_address, anonymous_id, "banned-rejected", content)
                db.commit()
            error_html = "".join(_post_notice(message, "error") for message in errors)
            body = _thread_html(
                question,
                db.execute(
                    "SELECT * FROM posts WHERE approved = 1 AND question_id = ? ORDER BY id ASC",
                    (question["id"],),
                ).fetchall(),
                anonymous_id,
                reply_to=reply_to or reply_to_param,
                form_error=error_html,
            )
            return _render_page("Post Reply", body)

        cursor = db.execute(
            """
            INSERT INTO queue (question_id, user_hash, display_name, content, reply_to, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (question["id"], user_hash, anonymous_id, content, reply_to, ip_address),
        )
        _log_event(db, ip_address, anonymous_id, "submitted", content, queue_id=cursor.lastrowid)
        db.commit()
        return redirect(url_for("index", message="submitted"))

    posts = db.execute(
        "SELECT * FROM posts WHERE approved = 1 AND question_id = ? ORDER BY id ASC",
        (question["id"],),
    ).fetchall()
    body = _thread_html(question, posts, anonymous_id, reply_to=reply_to_param)
    return _render_page("Post Reply", body)


@app.route("/admin/login", methods=["GET", "POST"])
def admin_login():
    init_db()
    error = ""
    if request.method == "POST":
        password = request.form.get("password", "")
        if ADMIN_PASSWORD and secrets.compare_digest(password, ADMIN_PASSWORD):
            session["admin"] = True
            return redirect(url_for("admin_dashboard"))
        error = _post_notice("Invalid password.", "error")
    body = (
        f'{error}<div class="panel"><div class="panel-head"><strong>Admin Login</strong></div>'
        '<div class="panel-body"><form method="post" action="/admin/login">'
        '<label for="password">Password</label>'
        '<input type="password" id="password" name="password">'
        '<input type="submit" value="Login">'
        '</form></div></div>'
    )
    return _render_admin_page("Admin Login", body)


@app.route("/admin/logout")
def admin_logout():
    session.pop("admin", None)
    return redirect(url_for("index"))


@app.route("/admin")
@admin_required
def admin_dashboard():
    init_db()
    db = get_db()
    question = current_question(db)
    recent_posts = db.execute(
        "SELECT * FROM posts WHERE question_id = ? ORDER BY id DESC LIMIT 25",
        (question["id"],),
    ).fetchall()
    banned_rows = db.execute("SELECT * FROM bans ORDER BY id DESC").fetchall()
    message = request.args.get("message", "")
    notice = _post_notice(message, "success") if message else ""
    banned_html = "".join(
        f'<li><span class="code">{_esc(row["ip_address"])}</span> '
        f'<span class="small">{_format_timestamp(row["created_at"])}</span></li>'
        for row in banned_rows
    ) or "<li>No banned IPs.</li>"
    posts_html = "".join(
        (
            f'<div class="post"><div class="post-head"><strong>{_esc(post["display_name"])}</strong> '
            f'<span class="post-meta">{_format_timestamp(post["created_at"])} No.{post["id"]}</span></div>'
            f'<div class="post-body"><p>{_text_to_html(post["content"])}</p>'
            f'<form class="inline-form" method="post" action="/admin/delete_post/{post["id"]}">'
            '<button type="submit">Delete post</button></form></div></div>'
        )
        for post in recent_posts
    ) or '<div class="panel"><div class="panel-body">No approved posts yet.</div></div>'
    body = (
        f'{notice}'
        '<div class="panel"><div class="panel-head"><strong>Change OP</strong></div><div class="panel-body">'
        '<form method="post" action="/admin/op">'
        '<label for="question_text">Current prompt</label>'
        f'<textarea id="question_text" name="question_text">{_esc(question["question_text"])}</textarea>'
        '<input type="submit" value="Update OP">'
        '</form></div></div>'
        '<div class="panel"><div class="panel-head"><strong>Moderation Tools</strong></div><div class="panel-body">'
        '<p><a href="/admin/queue">Open moderation queue</a></p>'
        '<p><a href="/admin/logs">Open post log</a></p>'
        '<form method="post" action="/admin/delete_post_by_id">'
        '<label for="post_id">Delete approved post by ID</label>'
        '<input type="text" id="post_id" name="post_id" inputmode="numeric">'
        '<input type="submit" value="Delete Post">'
        '</form>'
        '<form method="post" action="/admin/ban">'
        '<label for="ip_address">Ban IP address</label>'
        '<input type="text" id="ip_address" name="ip_address" placeholder="127.0.0.1">'
        '<input type="submit" value="Ban IP">'
        '</form>'
        f'<p class="small">Active OP ID: <span class="code">{question["id"]}</span></p>'
        '</div></div>'
        '<div class="panel"><div class="panel-head"><strong>Banned IPs</strong></div>'
        f'<div class="panel-body"><ul class="listing">{banned_html}</ul></div></div>'
        '<div class="panel"><div class="panel-head"><strong>Recent Approved Replies</strong></div>'
        f'<div class="panel-body">{posts_html}</div></div>'
    )
    return _render_admin_page("Admin Dashboard", body)


@app.route("/admin/op", methods=["POST"])
@admin_required
def admin_update_op():
    init_db()
    db = get_db()
    question_text = request.form.get("question_text", "").strip()
    if not question_text:
        return redirect(url_for("admin_dashboard", message="OP text cannot be empty."))
    question = current_question(db)
    db.execute(
        "UPDATE questions SET question_text = ? WHERE id = ?",
        (question_text, question["id"]),
    )
    db.commit()
    return redirect(url_for("admin_dashboard", message="OP updated."))


@app.route("/admin/queue")
@admin_required
def admin_queue():
    init_db()
    db = get_db()
    items = db.execute(
        "SELECT * FROM queue ORDER BY submitted_at ASC, id ASC"
    ).fetchall()
    rows = []
    for item in items:
        reply_line = ""
        if item["reply_to"]:
            reply_line = (
                f'<div class="small">Ref: <a class="quote" href="/#post-{item["reply_to"]}">&gt;{item["reply_to"]}</a></div>'
            )
        rows.append(
            '<div class="post">'
            f'<div class="post-head"><strong>{_esc(item["display_name"])}</strong> '
            f'<span class="post-meta">{_format_timestamp(item["submitted_at"])} / {_esc(item["ip_address"] or "unknown")}</span></div>'
            f'<div class="post-body">{reply_line}<p>{_text_to_html(item["content"])}</p>'
            '<div class="admin-actions">'
            f'<form class="inline-form" method="post" action="/admin/approve/{item["id"]}">'
            '<button type="submit">Approve</button></form> '
            f'<form class="inline-form" method="post" action="/admin/delete_queue/{item["id"]}">'
            '<button type="submit">Delete</button></form> '
            f'<form class="inline-form" method="post" action="/admin/ban">'
            f'<input type="hidden" name="ip_address" value="{_esc(item["ip_address"] or "")}">'
            '<button type="submit">Ban IP</button></form>'
            '</div></div></div>'
        )
    body = "".join(rows) or '<div class="panel"><div class="panel-body">Queue is empty.</div></div>'
    return _render_admin_page("Moderation Queue", body)


@app.route("/admin/approve/<int:item_id>", methods=["POST"])
@admin_required
def admin_approve(item_id: int):
    init_db()
    db = get_db()
    item = db.execute("SELECT * FROM queue WHERE id = ?", (item_id,)).fetchone()
    if item is None:
        abort(404)
    cursor = db.execute(
        """
        INSERT INTO posts (question_id, user_hash, display_name, content, reply_to, approved)
        VALUES (?, ?, ?, ?, ?, 1)
        """,
        (item["question_id"], item["user_hash"], item["display_name"], item["content"], item["reply_to"]),
    )
    db.execute("DELETE FROM queue WHERE id = ?", (item_id,))
    _log_event(db, item["ip_address"], item["display_name"], "approved", item["content"], post_id=cursor.lastrowid)
    db.commit()
    return redirect(url_for("admin_queue"))


@app.route("/admin/delete_queue/<int:item_id>", methods=["POST"])
@admin_required
def admin_delete_queue(item_id: int):
    init_db()
    db = get_db()
    item = db.execute("SELECT * FROM queue WHERE id = ?", (item_id,)).fetchone()
    if item is not None:
        _log_event(db, item["ip_address"], item["display_name"], "queue-deleted", item["content"], queue_id=item_id)
        db.execute("DELETE FROM queue WHERE id = ?", (item_id,))
        db.commit()
    return redirect(url_for("admin_queue"))


@app.route("/admin/delete_post_by_id", methods=["POST"])
@admin_required
def admin_delete_post_by_id():
    post_id = request.form.get("post_id", "").strip()
    if not post_id.isdigit():
        return redirect(url_for("admin_dashboard", message="Enter a numeric post ID."))
    return _delete_approved_post(int(post_id))


@app.route("/admin/delete_post/<int:post_id>", methods=["POST"])
@admin_required
def admin_delete_post(post_id: int):
    return _delete_approved_post(post_id)


def _delete_approved_post(post_id: int):
    init_db()
    db = get_db()
    post = db.execute("SELECT * FROM posts WHERE id = ?", (post_id,)).fetchone()
    if post is None:
        return redirect(url_for("admin_dashboard", message="Post not found."))
    _log_event(db, "", post["display_name"], "post-deleted", post["content"], post_id=post_id)
    db.execute("DELETE FROM posts WHERE id = ?", (post_id,))
    db.commit()
    return redirect(url_for("admin_dashboard", message=f"Deleted post No.{post_id}."))


@app.route("/admin/ban", methods=["POST"])
@admin_required
def admin_ban():
    init_db()
    db = get_db()
    ip_address = request.form.get("ip_address", "").strip()
    if not ip_address:
        return redirect(url_for("admin_dashboard", message="IP address is required."))
    db.execute("INSERT OR IGNORE INTO bans (ip_address) VALUES (?)", (ip_address,))
    _log_event(db, ip_address, "admin", "ip-banned")
    db.commit()
    back_to_queue = request.referrer and request.referrer.endswith("/admin/queue")
    target = url_for("admin_queue") if back_to_queue else url_for("admin_dashboard", message=f"Banned {ip_address}.")
    return redirect(target)


@app.route("/admin/logs")
@admin_required
def admin_logs():
    init_db()
    db = get_db()
    rows = db.execute(
        "SELECT * FROM post_logs ORDER BY id DESC LIMIT ?",
        (LOG_LIMIT,),
    ).fetchall()
    body_rows = []
    for row in rows:
        detail = []
        if row["post_id"]:
            detail.append(f'post <span class="code">{row["post_id"]}</span>')
        if row["queue_id"]:
            detail.append(f'queue <span class="code">{row["queue_id"]}</span>')
        detail_html = " / ".join(detail)
        preview = _esc(row["content_preview"])
        body_rows.append(
            '<div class="post">'
            f'<div class="post-head"><strong>{_esc(row["anonymous_id"])}</strong> '
            f'<span class="post-meta">{_format_timestamp(row["created_at"])} / {_esc(row["ip_address"] or "unknown")} / {_esc(row["action"])} {detail_html}</span></div>'
            f'<div class="post-body"><p>{preview or "-"}</p></div></div>'
        )
    body = "".join(body_rows) or '<div class="panel"><div class="panel-body">No logs yet.</div></div>'
    return _render_admin_page("Post Log", body)


if __name__ == "__main__":
    with app.app_context():
        init_db()
    debug = os.environ.get("FLASK_DEBUG", "0").lower() in {"1", "true", "yes"}
    app.run(debug=debug)
