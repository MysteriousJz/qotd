import os
import re
import hashlib
import html
import random
import secrets
import sqlite3
import warnings
from datetime import datetime, timezone
from functools import wraps

from flask import (
    Flask,
    g,
    request,
    session,
    redirect,
    url_for,
    abort,
)

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
POSTS_PER_PAGE = 20
MAX_POST_LENGTH = 4000

# ---------------------------------------------------------------------------
# Word lists for anonymous name generation
# ---------------------------------------------------------------------------

ADJECTIVES = [
    "swift", "brave", "calm", "dark", "eager", "fair", "glad", "happy",
    "idle", "jolly", "keen", "loud", "mild", "neat", "odd", "pale",
    "quick", "rare", "slow", "tame", "vast", "warm", "wild", "zany",
    "ample", "bold", "cool", "damp", "epic", "fine", "gray", "hard",
    "iron", "jade", "kind", "lame", "mute", "nice", "open", "pink",
    "quiet", "rich", "safe", "tall", "ugly", "vivid", "wise", "young",
]

NOUNS = [
    "fox", "wolf", "bear", "hawk", "crow", "frog", "deer", "mole",
    "hare", "newt", "duck", "fish", "crab", "slug", "moth", "wasp",
    "pike", "kite", "toad", "lynx", "wren", "vole", "ibis", "dove",
    "lark", "colt", "mare", "bull", "seal", "worm", "bees", "gull",
    "swan", "teal", "stag", "pony", "mink", "puma", "boar", "dace",
    "chub", "roach", "rudd", "bream", "ide", "bleak", "sprat", "smelt",
]

# ---------------------------------------------------------------------------
# CSS (embedded in every page via base template)
# ---------------------------------------------------------------------------

CSS = """
body {
    background-color: #f5f5dc;
    font-family: monospace;
    max-width: 600px;
    margin: 0 auto;
    padding: 10px;
    font-size: 16px;
}
a { color: #0000ee; text-decoration: underline; }
.post {
    border-top: 1px solid #ccc;
    margin-top: 10px;
    padding-top: 10px;
}
.post-number { font-weight: bold; }
.post-meta { font-size: 12px; color: #666; }
textarea {
    width: 100%;
    box-sizing: border-box;
    font-family: monospace;
    font-size: 16px;
    margin: 10px 0;
}
input[type="text"], input[type="password"], input[type="date"] {
    width: 100%;
    box-sizing: border-box;
    font-family: monospace;
    font-size: 16px;
    margin: 4px 0;
    padding: 6px;
}
button, input[type="submit"] {
    background: #eee;
    border: 1px solid #ccc;
    font-family: monospace;
    font-size: 16px;
    padding: 8px 12px;
    cursor: pointer;
}
.pagination { margin: 20px 0; text-align: center; }
.pagination a, .pagination span { margin: 0 4px; }
nav { margin-bottom: 16px; }
nav a { margin-right: 12px; }
.queue-item { border-top: 1px solid #ccc; margin-top: 10px; padding-top: 10px; }
.error { color: #cc0000; }
.success { color: #007700; }
label { display: block; margin-top: 8px; }
"""

# ---------------------------------------------------------------------------
# HTML helpers
# ---------------------------------------------------------------------------


def _page(title: str, body: str, display_name: str = "") -> str:
    name_line = f"<p>You are: <strong>{_esc(display_name)}</strong></p>" if display_name else ""
    return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} — QOTD</title>
<style>{CSS}</style>
</head>
<body>
<nav>
<a href="/">Home</a>
<a href="/archive">Archive</a>
<a href="/submit">Post Reply</a>
</nav>
{name_line}
<h2>{title}</h2>
{body}
</body>
</html>"""


def _admin_page(title: str, body: str) -> str:
    return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} — QOTD Admin</title>
<style>{CSS}</style>
</head>
<body>
<nav>
<a href="/">Home</a>
<a href="/admin/queue">Queue</a>
<a href="/admin/new_question">New Question</a>
<a href="/admin/logout">Logout</a>
</nav>
<h2>{title}</h2>
{body}
</body>
</html>"""


def _esc(text: str) -> str:
    """HTML-escape a string using the standard library."""
    return html.escape(text, quote=True)


def _linkify_replies(content: str) -> str:
    """Turn >>123 into a clickable anchor."""
    escaped = _esc(content)
    return re.sub(
        r"&gt;&gt;(\d+)",
        r'<a href="#post-\1">&gt;&gt;\1</a>',
        escaped,
    )


def _format_posts(posts) -> str:
    html = []
    for post in posts:
        pid = post["id"]
        name = _esc(post["display_name"])
        ts = post["created_at"]
        # Show only HH:MM
        try:
            dt = datetime.fromisoformat(ts)
            time_str = dt.strftime("%H:%M")
        except Exception:
            time_str = ts
        content_html = _linkify_replies(post["content"])
        reply_to = post["reply_to"]
        reply_line = ""
        if reply_to:
            reply_line = f'<span class="post-meta">Replying to <a href="#post-{reply_to}">&gt;&gt;{reply_to}</a></span><br>'
        html.append(
            f'<div class="post" id="post-{pid}">'
            f'<span class="post-number">#{pid}</span> '
            f'<span class="post-meta">{name} — {time_str}</span><br>'
            f"{reply_line}"
            f"<p>{content_html}</p>"
            f'<a href="/submit?reply_to={pid}">&gt;&gt;{pid}</a>'
            f"</div>"
        )
    return "\n".join(html)


def _pagination(page: int, total_pages: int, base_url: str) -> str:
    if total_pages <= 1:
        return ""
    parts = ['<div class="pagination">']
    if page > 1:
        parts.append(f'<a href="{base_url}?page={page - 1}">&lt;</a>')
    for p in range(1, total_pages + 1):
        if p == page:
            parts.append(f"<span>[{p}]</span>")
        else:
            parts.append(f'<a href="{base_url}?page={p}">[{p}]</a>')
    if page < total_pages:
        parts.append(f'<a href="{base_url}?page={page + 1}">&gt;</a>')
    parts.append("</div>")
    return "".join(parts)


# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------


def get_db():
    if "db" not in g:
        g.db = sqlite3.connect(DATABASE)
        g.db.row_factory = sqlite3.Row
        g.db.execute("PRAGMA journal_mode=WAL")
        g.db.execute("PRAGMA foreign_keys=ON")
    return g.db


@app.teardown_appcontext
def close_db(exc):
    db = g.pop("db", None)
    if db is not None:
        db.close()


def init_db():
    db = get_db()
    db.executescript("""
        CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT UNIQUE NOT NULL,
            question_text TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question_id INTEGER NOT NULL,
            user_hash TEXT NOT NULL,
            display_name TEXT NOT NULL,
            content TEXT NOT NULL,
            reply_to INTEGER,
            approved BOOLEAN DEFAULT 0,
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
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (question_id) REFERENCES questions(id)
        );
    """)
    db.commit()


# ---------------------------------------------------------------------------
# Anonymous identity helpers
# ---------------------------------------------------------------------------


def _generate_display_name() -> str:
    adj = random.choice(ADJECTIVES)
    noun = random.choice(NOUNS)
    num = random.randint(10, 99)
    return f"{adj}_{noun}_{num}"


def _user_hash(ip: str) -> str:
    secret = app.secret_key
    data = f"{ip}:{secret}".encode()
    return hashlib.sha256(data).hexdigest()[:16]


def get_identity():
    """Return (display_name, user_hash) for the current visitor.

    The display name is stored in the Flask session (a signed, tamper-proof
    cookie) so that the value is server-authoritative and cannot be injected
    or spoofed by the client.
    """
    ip = request.remote_addr or "unknown"
    user_hash = _user_hash(ip)
    # Use Flask session (signed cookie) as the source of truth
    display_name = session.get("display_name")
    if not display_name:
        display_name = _generate_display_name()
        session["display_name"] = display_name
    return display_name, user_hash


# ---------------------------------------------------------------------------
# Admin auth decorator
# ---------------------------------------------------------------------------


def admin_required(f):
    @wraps(f)
    def decorated(*args, **kwargs):
        if not session.get("admin"):
            return redirect(url_for("admin_login"))
        return f(*args, **kwargs)
    return decorated


# ---------------------------------------------------------------------------
# Routes — public
# ---------------------------------------------------------------------------


@app.route("/")
def index():
    init_db()
    display_name, user_hash = get_identity()
    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    db = get_db()

    question = db.execute(
        "SELECT * FROM questions WHERE date = ?", (today,)
    ).fetchone()

    if question is None:
        body = "<p>No question today. Check back tomorrow.</p>"
        return _page("Question of the Day", body, display_name)

    page = request.args.get("page", 1, type=int)
    if page < 1:
        page = 1

    total = db.execute(
        "SELECT COUNT(*) FROM posts WHERE question_id = ? AND approved = 1",
        (question["id"],),
    ).fetchone()[0]
    total_pages = max(1, (total + POSTS_PER_PAGE - 1) // POSTS_PER_PAGE)
    if page > total_pages:
        page = total_pages

    offset = (page - 1) * POSTS_PER_PAGE
    posts = db.execute(
        "SELECT * FROM posts WHERE question_id = ? AND approved = 1 "
        "ORDER BY id ASC LIMIT ? OFFSET ?",
        (question["id"], POSTS_PER_PAGE, offset),
    ).fetchall()

    q_text = _esc(question["question_text"])
    posts_html = _format_posts(posts)
    pagination = _pagination(page, total_pages, "/")

    body = (
        f"<p><strong>{q_text}</strong></p>"
        f"<p><a href='/submit'>Post a reply</a></p>"
        f"{posts_html}"
        f"{pagination}"
    )

    return _page("Question of the Day", body, display_name)


@app.route("/archive")
def archive():
    init_db()
    display_name, _ = get_identity()
    db = get_db()
    questions = db.execute(
        "SELECT date, question_text FROM questions ORDER BY date DESC"
    ).fetchall()

    rows = []
    for q in questions:
        d = _esc(q["date"])
        qt = _esc(q["question_text"])
        rows.append(f'<p><a href="/archive/{d}">{d}</a> — {qt}</p>')

    body = "\n".join(rows) if rows else "<p>No questions yet.</p>"
    return _page("Archive", body, display_name)


@app.route("/archive/<date>")
def archive_date(date):
    init_db()
    # Validate date format
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", date):
        abort(404)

    display_name, _ = get_identity()
    db = get_db()

    question = db.execute(
        "SELECT * FROM questions WHERE date = ?", (date,)
    ).fetchone()
    if question is None:
        abort(404)

    page = request.args.get("page", 1, type=int)
    if page < 1:
        page = 1

    total = db.execute(
        "SELECT COUNT(*) FROM posts WHERE question_id = ? AND approved = 1",
        (question["id"],),
    ).fetchone()[0]
    total_pages = max(1, (total + POSTS_PER_PAGE - 1) // POSTS_PER_PAGE)
    if page > total_pages:
        page = total_pages

    offset = (page - 1) * POSTS_PER_PAGE
    posts = db.execute(
        "SELECT * FROM posts WHERE question_id = ? AND approved = 1 "
        "ORDER BY id ASC LIMIT ? OFFSET ?",
        (question["id"], POSTS_PER_PAGE, offset),
    ).fetchall()

    q_text = _esc(question["question_text"])
    posts_html = _format_posts(posts)
    pagination = _pagination(page, total_pages, f"/archive/{date}")

    body = (
        f"<p><strong>{q_text}</strong></p>"
        f"<p><a href='/submit'>Post a reply</a></p>"
        f"{posts_html}"
        f"{pagination}"
    )

    return _page(f"Archive — {_esc(date)}", body, display_name)


@app.route("/submit", methods=["GET", "POST"])
def submit():
    init_db()
    display_name, user_hash = get_identity()
    db = get_db()

    # Default to today's question
    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    question = db.execute(
        "SELECT * FROM questions WHERE date = ?", (today,)
    ).fetchone()

    reply_to_param = request.args.get("reply_to", type=int)

    if request.method == "POST":
        content = request.form.get("content", "").strip()
        reply_to = request.form.get("reply_to", type=int)
        question_id = request.form.get("question_id", type=int)

        errors = []
        if not content:
            errors.append("Post content cannot be empty.")
        if len(content) > MAX_POST_LENGTH:
            errors.append(f"Post is too long (max {MAX_POST_LENGTH} characters).")
        if question_id is None:
            errors.append("No active question to reply to.")
        else:
            q = db.execute("SELECT id FROM questions WHERE id = ?", (question_id,)).fetchone()
            if q is None:
                errors.append("Invalid question.")

        if errors:
            error_html = "".join(f'<p class="error">{_esc(e)}</p>' for e in errors)
            body = _submit_form(question, reply_to_param or reply_to, error=error_html)
            return _page("Submit Post", body, display_name)

        db.execute(
            "INSERT INTO queue (question_id, user_hash, display_name, content, reply_to) "
            "VALUES (?, ?, ?, ?, ?)",
            (question_id, user_hash, display_name, content, reply_to or None),
        )
        db.commit()

        body = '<p class="success">Your post has been submitted for moderation.</p><p><a href="/">Back to home</a></p>'
        return _page("Post Submitted", body, display_name)

    # GET
    body = _submit_form(question, reply_to_param)
    return _page("Submit Post", body, display_name)


def _submit_form(question, reply_to=None, error="") -> str:
    if question is None:
        return "<p>No active question today. Nothing to reply to.</p><p><a href='/'>Back</a></p>"

    reply_value = str(reply_to) if reply_to else ""
    reply_field = (
        f'<input type="hidden" name="reply_to" value="{reply_value}">'
        if reply_to else ""
    )
    pre_fill = f">>{reply_to}\n" if reply_to else ""
    q_text = _esc(question["question_text"])

    return (
        f"{error}"
        f"<p><strong>Today's question:</strong> {q_text}</p>"
        f"<form method='post' action='/submit'>"
        f'<input type="hidden" name="question_id" value="{question["id"]}">'
        f"{reply_field}"
        f'<label for="content">Your answer:</label>'
        f'<textarea id="content" name="content" rows="6" placeholder="Type your answer here...">{_esc(pre_fill)}</textarea>'
        f'<input type="submit" value="Submit">'
        f"</form>"
    )


# ---------------------------------------------------------------------------
# Routes — admin
# ---------------------------------------------------------------------------


@app.route("/admin/login", methods=["GET", "POST"])
def admin_login():
    init_db()
    error = ""
    if request.method == "POST":
        password = request.form.get("password", "")
        if ADMIN_PASSWORD and secrets.compare_digest(password, ADMIN_PASSWORD):
            session["admin"] = True
            return redirect(url_for("admin_queue"))
        error = '<p class="error">Invalid password.</p>'

    body = (
        f"{error}"
        "<form method='post' action='/admin/login'>"
        "<label for='password'>Password:</label>"
        "<input type='password' id='password' name='password'>"
        "<input type='submit' value='Login'>"
        "</form>"
    )
    return _admin_page("Admin Login", body)


@app.route("/admin/logout")
def admin_logout():
    session.pop("admin", None)
    return redirect(url_for("index"))


@app.route("/admin/queue")
@admin_required
def admin_queue():
    init_db()
    db = get_db()
    items = db.execute(
        "SELECT q.*, qs.question_text, qs.date "
        "FROM queue q "
        "JOIN questions qs ON q.question_id = qs.id "
        "ORDER BY q.submitted_at ASC"
    ).fetchall()

    rows = []
    for item in items:
        name = _esc(item["display_name"])
        content = _esc(item["content"])
        ts = item["submitted_at"]
        reply_line = f"<br>Replying to: #{item['reply_to']}" if item["reply_to"] else ""
        date_line = _esc(item["date"])
        q_text = _esc(item["question_text"])
        rows.append(
            f'<div class="queue-item">'
            f"<strong>{name}</strong> — {ts}{reply_line}<br>"
            f"<em>Question ({date_line}): {q_text}</em><br>"
            f"<p>{content}</p>"
            f"<form method='post' action='/admin/approve/{item['id']}' style='display:inline'>"
            f"<button type='submit'>Approve</button>"
            f"</form> "
            f"<form method='post' action='/admin/delete/{item['id']}' style='display:inline'>"
            f"<button type='submit'>Delete</button>"
            f"</form>"
            f"</div>"
        )

    body = "\n".join(rows) if rows else "<p>No pending posts.</p>"
    return _admin_page("Moderation Queue", body)


@app.route("/admin/approve/<int:item_id>", methods=["POST"])
@admin_required
def admin_approve(item_id):
    init_db()
    db = get_db()
    item = db.execute("SELECT * FROM queue WHERE id = ?", (item_id,)).fetchone()
    if item is None:
        abort(404)
    db.execute(
        "INSERT INTO posts (question_id, user_hash, display_name, content, reply_to, approved) "
        "VALUES (?, ?, ?, ?, ?, 1)",
        (item["question_id"], item["user_hash"], item["display_name"],
         item["content"], item["reply_to"]),
    )
    db.execute("DELETE FROM queue WHERE id = ?", (item_id,))
    db.commit()
    return redirect(url_for("admin_queue"))


@app.route("/admin/delete/<int:item_id>", methods=["POST"])
@admin_required
def admin_delete(item_id):
    init_db()
    db = get_db()
    db.execute("DELETE FROM queue WHERE id = ?", (item_id,))
    db.commit()
    return redirect(url_for("admin_queue"))


@app.route("/admin/new_question", methods=["GET", "POST"])
@admin_required
def admin_new_question():
    init_db()
    error = ""
    success = ""

    if request.method == "POST":
        date = request.form.get("date", "").strip()
        question_text = request.form.get("question_text", "").strip()

        if not date or not re.match(r"^\d{4}-\d{2}-\d{2}$", date):
            error = '<p class="error">Invalid date format. Use YYYY-MM-DD.</p>'
        elif not question_text:
            error = '<p class="error">Question text cannot be empty.</p>'
        else:
            db = get_db()
            existing = db.execute(
                "SELECT id FROM questions WHERE date = ?", (date,)
            ).fetchone()
            if existing:
                error = f'<p class="error">A question for {_esc(date)} already exists.</p>'
            else:
                db.execute(
                    "INSERT INTO questions (date, question_text) VALUES (?, ?)",
                    (date, question_text),
                )
                db.commit()
                success = f'<p class="success">Question for {_esc(date)} added.</p>'

    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    body = (
        f"{error}{success}"
        "<form method='post' action='/admin/new_question'>"
        "<label for='date'>Date (YYYY-MM-DD):</label>"
        f"<input type='date' id='date' name='date' value='{today}'>"
        "<label for='question_text'>Question:</label>"
        "<textarea id='question_text' name='question_text' rows='4'></textarea>"
        "<input type='submit' value='Add Question'>"
        "</form>"
    )
    return _admin_page("New Question", body)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    with app.app_context():
        init_db()
    debug = os.environ.get("FLASK_DEBUG", "0").lower() in ("1", "true", "yes")
    app.run(debug=debug)
