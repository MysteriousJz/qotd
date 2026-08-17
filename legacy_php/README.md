# qotd

Question of the Day — an anonymous PHP/SQLite discussion board.

## Requirements

- PHP 8.x with SQLite3 enabled
- Nginx
- No JavaScript

## Files

- `index.php` — public router
- `admin.php` — admin router and moderation actions
- `config.php` — paths, salts, and admin password hash
- `functions.php` — rendering, helpers, caching
- `db.php` — SQLite access and schema helpers
- `css/style.css` — retro styling

## First run

1. Put the repository in your web root.
2. Make sure PHP can write to `/home/runner/work/qotd-data` and `/tmp/qotd_cache` (or update the paths in `config.php`).
3. Visit `/` once so the database tables are created.
4. Open `/admin/login` and sign in.
5. Add the first question from the admin dashboard.

The app does **not** create a default question automatically.

## Admin password
Set `ADMIN_PASSWORD_HASH` in `config.php` before using the admin interface.
Generate a hash with:

```bash
php -r 'echo password_hash("your_password", PASSWORD_DEFAULT), PHP_EOL;'
```

## Caching

Date pages are cached in `/tmp/qotd_cache` as `date_YYYY-MM-DD.html`.
The cache is rebuilt when:

- a reply is approved
- a reply is rejected
- a reply is deleted
- a question is changed for that month

## Sample questions

There is a helper function in `db.php` named `qotd_seed_sample_questions()`.
Call it once if you want a few starter questions for testing.

## Nginx example

```nginx
server {
    listen 80;
    server_name your_domain_or_ip;

    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
    }

    location ~ \.db$ {
        deny all;
    }

    location ~* \.(css|ico)$ {
        expires 7d;
    }
}
```

## Testing checklist

- [ ] Homepage loads with today's question or the no-question message
- [ ] Admin can create a question for any date
- [ ] User replies go to the moderation queue
- [ ] Admin can approve replies
- [ ] Admin can reject replies
- [ ] `>>123` reply references work
- [ ] Calendar dates are clickable to open that day
- [ ] Previous/next month navigation works
- [ ] 30-second rate limiting blocks repeat posts
- [ ] Banned IPs are blocked
- [ ] Cache invalidates after admin actions
