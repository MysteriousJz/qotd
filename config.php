<?php
declare(strict_types=1);

// Everything runs in America/New_York so "today" and month navigation stay consistent.
date_default_timezone_set('America/New_York');

const APP_NAME = 'Question of the Day';
const APP_TIMEZONE = 'America/New_York';
const APP_BASE_URL = '';

// Keep the SQLite database outside the web root.
const DB_PATH = '/home/runner/work/qotd-data/qotd.sqlite';

// Public date-page cache directory.
const CACHE_DIR = '/tmp/qotd_cache';

// Anonymous ID salt is generated outside the codebase.
const ANON_SALT_FILE = '/home/runner/work/qotd-data/anon_salt.txt';

// Default admin password: qotdadmin.
// Change this by replacing the hash with one from password_hash().
const ADMIN_PASSWORD_HASH = '$2y$10$0bGtPU.PtMrBlXszLfVt/OIsCZgJNSUFa1eqQNXMcnsURjhp1PCMK';

const RATE_LIMIT_SECONDS = 30;
const MAX_POST_LENGTH = 4000;
const ADMIN_SESSION_TIMEOUT = 1800;
const LOG_LIMIT = 200;
const QUESTION_MAX_LENGTH = 500;
