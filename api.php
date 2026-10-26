<?php
/*
 * MAP Lucky Draw: phone sign-up API (api.php)
 * ============================================
 * Upload this file next to index.html on your website. It needs PHP 7.4 or newer and nothing else:
 * no database, no extra programs.
 *
 * Setup (one line): set ADMIN_PASSWORD below to your own password.
 *
 * - Guests open the folder's address (your QR code) on their phones and sign up.
 * - The organiser opens the same address followed by #wheel, logs in with this password, and the
 *   sign-ups appear on the wheel.
 * - Sign-ups are kept in the "data" folder next to this file, which is created automatically.
 *   "Clear all sign-ups on the server" (in the wheel's Settings) wipes them. Deleting the folder
 *   does the same.
 * - Up to ten events at once: upload the same two files into the folders draw, draw2 … draw10.
 *   Each folder is a separate draw (its own sign-ups, Excel backup, login and password). Guests
 *   all use the first folder's address; the page sends them to the event that is running.
 * - The organiser's laptop also keeps a backup of its whole event here (the wheel, winners, round,
 *   prize, event name), so another laptop can take over if one dies, and two laptops on the same
 *   draw stay in step. "New event" in the wheel deletes it together with the sign-ups.
 *
 * Use HTTPS so that guests' details are encrypted on their way to your server.
 */

const ADMIN_PASSWORD = 'change-this-password'; // ← set your own password
const MAX_ENTRIES = 2000;
const DATA_DIR = __DIR__ . '/data';            // created automatically

/* ---- Advanced settings (normally leave these as they are) ------------------------------------ */
const SUBMIT_LIMIT_PER_MINUTE = 300; // sign-ups per minute from one IP address (a whole venue may share one Wi-Fi IP)
const LOGIN_MAX_FAILS = 5;           // wrong passwords from one IP address...
const LOGIN_LOCK_SECONDS = 900;      // ...within 15 minutes lock logins from that address for 15 minutes
const SESSION_HOURS = 12;            // the organiser stays logged in for this long
const CLIENT_IP_HEADER = '';         // only behind a proxy/CDN, e.g. 'HTTP_CF_CONNECTING_IP' for Cloudflare

/* ---- Internal constants ---------------------------------------------------------------------- */
const MLD_VERSION = 1;
const MLD_MAX_BODY = 8192;           // bytes
const MLD_EVENT_MAX_BODY = 2097152;  // bytes: the organiser's whole event (only for action=eventState, organiser only)
const MLD_PAGE_SIZE = 1000;          // entries per page for the organiser's laptop
const MLD_TRIM = " \t\n\r\0\x0B";   // trim()'s classic default, spelled out (PHP 8.6 adds \f)
// First line of every storage file: if the web server ever runs one of them as PHP, it answers
// 404 and stops, so the JSON below it is never sent to a browser.
const MLD_GUARD = '<?php http_response_code(404); exit; ?>';
const MLD_DEFAULT_CONFIG = [
    'open' => true,
    'title' => 'Lucky Draw',
    'subtitle' => 'Spin to win',
    'consentText' => 'I agree that MAP may store my details to run this draw and contact me if I win.',
    'marketingText' => 'Keep me updated with news and offers from MAP.',
    'showMarketing' => true,
    'eventName' => '', // e.g. "Newcastle": guests choose their event by it; it names the Excel files
    'eventDate' => '', // DD-MM-YYYY, the day the event was named (part of the Excel backup's file name)
];
// Same length limits as the app's settings.
const MLD_TEXT_LIMITS = ['title' => 60, 'subtitle' => 80, 'consentText' => 400, 'marketingText' => 200];
const MLD_EVENT_NAME_MAX = 60;
// "In use": a draw counts as running while a logged-in laptop has checked for sign-ups within this many seconds.
const MLD_ACTIVE_SECONDS = 90;
const MLD_ACTIVE_WRITE_EVERY = 15;   // a laptop's "last seen" time is saved at most this often
const MLD_ACTIVE_KEEP = 600;         // records older than this are dropped
const MLD_ACTIVE_LAST = '~last';     // in active.php: when any organiser last checked in (kept after the records go)
// The draw folders that can sit side by side (the same list as DRAW_SLOTS in index.html).
const MLD_DRAW_FOLDERS = ['draw', 'draw2', 'draw3', 'draw4', 'draw5', 'draw6', 'draw7', 'draw8', 'draw9', 'draw10'];
// JavaScript's \s (what the app collapses to a single space), for PCRE with the u flag.
const MLD_WS = ' \t\n\x0B\f\r\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
// The app's EMAIL_RE, ported to PCRE (D = "$" means the very end of the string).
const MLD_EMAIL_RE = '/^(?!\.)(?!.*\.\.)[^' . MLD_WS . '@"<>,;:()\[\]\\\\]{1,64}@(?=.{1,253}$)'
    . '(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+\p{L}{2,63}$/uD';
const MLD_HTACCESS = "# MAP Lucky Draw: sign-ups are stored here. Block all web access to this folder.\n"
    . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
    . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";

/* =============================================================================================== *
 * Bootstrap: never show PHP errors or paths to visitors; every answer is JSON.
 * =============================================================================================== */
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);
ob_start();
if (function_exists('header_remove')) {
    header_remove('X-Powered-By');
}
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Cross-Origin-Resource-Policy: same-origin');

/** An error answer: HTTP status + { ok:false, code, message, field? }. */
class MldError extends Exception
{
    public $status;
    public $payload;
    public $headers;

    public function __construct($status, array $payload, array $headers = [])
    {
        parent::__construct(isset($payload['code']) ? $payload['code'] : 'error');
        $this->status = $status;
        $this->payload = $payload;
        $this->headers = $headers;
    }
}

set_error_handler(function ($type, $message, $file = '', $line = 0) {
    if (error_reporting() & $type) {
        // Log only the kind of problem and the line: never the message (it could quote user input).
        error_log('MAP Lucky Draw api.php: PHP error type ' . $type . ' on line ' . $line);
    }
    return true;
});

set_exception_handler(function ($e) {
    if ($e instanceof MldError) {
        mld_send($e->status, $e->payload, $e->headers);
    }
    error_log('MAP Lucky Draw api.php: ' . get_class($e) . ' on line ' . $e->getLine());
    mld_send(500, ['ok' => false, 'code' => 'server', 'message' => 'Something went wrong on the server. Please try again.']);
});

register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || empty($e['type']) || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!empty($GLOBALS['mld_sent'])) {
        return;
    }
    error_log('MAP Lucky Draw api.php: fatal error type ' . $e['type'] . ' on line ' . $e['line']);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '{"ok":false,"code":"server","message":"Something went wrong on the server. Please try again."}';
});

/** Sends the JSON answer and stops. */
function mld_send($status, array $data, array $headers = [])
{
    // &soft=1 on submit / login (the app's own pages): an ordinary refusal (wrong password, already entered, closed,
    // full, a field to correct) comes with HTTP 200 and ok:false, so the browser doesn't log it as an error.
    if (!empty($GLOBALS['mld_soft']) && isset($data['ok']) && $data['ok'] === false && in_array($status, [400, 401, 403, 409], true)) {
        $status = 200;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        foreach ($headers as $h) {
            header($h);
        }
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"ok":false,"code":"server","message":"Something went wrong on the server. Please try again."}';
    }
    $GLOBALS['mld_sent'] = true;
    echo $json;
    exit;
}

/** Throws an error answer. */
function mld_fail($status, $code, $message, $field = null, array $extra = [], array $headers = [])
{
    $p = ['ok' => false, 'code' => $code, 'message' => $message];
    if ($field !== null) {
        $p['field'] = $field;
    }
    throw new MldError($status, $p + $extra, $headers);
}

/* =============================================================================================== *
 * Routing: api.php?action=<name>
 * =============================================================================================== */
function mld_main()
{
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    if (!is_string($action)) {
        $action = '#';
    }
    $routes = [
        '' => ['GET', 'mld_action_ping'],
        'ping' => ['GET', 'mld_action_ping'],
        'config' => ['GET', 'mld_action_config'],
        'submit' => ['POST', 'mld_action_submit'],
        'login' => ['POST', 'mld_action_login'],
        'logout' => ['POST', 'mld_action_logout'],
        'session' => ['GET', 'mld_action_session'],
        'entries' => ['GET', 'mld_action_entries'],
        'setConfig' => ['POST', 'mld_action_set_config'],
        'clear' => ['POST', 'mld_action_clear'],
        'exportXlsx' => ['GET', 'mld_action_export_xlsx'],
        'setWinners' => ['POST', 'mld_action_set_winners'],
        'eventState' => [['GET', 'POST'], 'mld_action_event_state'],
    ];
    if (!isset($routes[$action])) {
        mld_fail(404, 'not_found', 'Unknown action.');
    }
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    $want = (array) $routes[$action][0];
    if ($method === 'HEAD' && in_array('GET', $want, true)) {
        $method = 'GET';
    }
    if (!in_array($method, $want, true)) {
        mld_fail(405, 'method_not_allowed', 'Use ' . implode(' or ', $want) . ' for this action.', null, [], ['Allow: ' . implode(', ', $want)]);
    }
    call_user_func($routes[$action][1]);
}

/* =============================================================================================== *
 * Public actions (guests' phones)
 * =============================================================================================== */
function mld_action_ping()
{
    mld_send(200, ['ok' => true, 'app' => 'map-lucky-draw', 'version' => MLD_VERSION]);
}

/** GET config: the sign-up form's texts and whether sign-ups are open. No personal data, no counts. */
function mld_action_config()
{
    $state = mld_state();
    mld_send(200, ['ok' => true] + mld_public_config($state));
}

function mld_public_config(array $state)
{
    $c = $state['config'];
    return [
        'open' => $c['open'],
        'full' => $state['count'] >= MAX_ENTRIES,
        'title' => $c['title'],
        'subtitle' => $c['subtitle'],
        'consentText' => $c['consentText'],
        'marketingText' => $c['marketingText'],
        'showMarketing' => $c['showMarketing'],
        'event' => $state['epoch'], // changes on "New event", so guests' phones forget the last event
        'eventName' => $c['eventName'],
        // A yes/no only (no counts, no ids): is an organiser's laptop running this draw right now?
        'organiserActive' => mld_active_any(),
        // Does this draw still hold an event (a laptop's event backup or sign-ups since the last "New event")? Yes/no.
        'hasEvent' => !empty($state['evHas']) || $state['count'] > 0,
        // Seconds since an organiser's laptop last ran the wheel here, rounded to the minute (null: never).
        'lastActiveAgo' => mld_active_ago(),
    ] + mld_draw_folders();
}

/**
 * ['drawFolders' => [...]]: which draw folders (draw, draw2 … draw10) are uploaded next to this one, so pages only
 * ask those (no requests for folders that don't exist). Empty when this folder isn't one of them, or when it can't
 * be told reliably from here (the page then simply asks every draw folder).
 */
function mld_draw_folders()
{
    $own = basename(__DIR__);
    $script = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    // The folder in the address must be this folder on disk, and the folder above it must be readable.
    if (!in_array($own, MLD_DRAW_FOLDERS, true) || basename(str_replace('\\', '/', dirname($script))) !== $own
        || @scandir(dirname(__DIR__)) === false) {
        return [];
    }
    $out = [];
    foreach (MLD_DRAW_FOLDERS as $name) {
        if ($name === $own || @is_file(dirname(__DIR__) . '/' . $name . '/api.php')) {
            $out[] = $name;
        }
    }
    return ['drawFolders' => $out];
}

/** POST submit: { clientId, name, number, email, consent:true, adult:true, marketing }. */
function mld_action_submit()
{
    $GLOBALS['mld_soft'] = isset($_GET['soft']) && $_GET['soft'] === '1';
    $wait = mld_limit_hit('submit', SUBMIT_LIMIT_PER_MINUTE, 60);
    if ($wait > 0) {
        mld_fail(429, 'rate_limited', 'Too many attempts — please wait a minute and try again.', null, [], ['Retry-After: ' . $wait]);
    }
    $v = mld_validate_submission(mld_json_body());

    $result = mld_locked(true, function () use ($v) {
        $state = mld_state_read();
        $raw = '';
        $rows = mld_entries_read($raw);
        // Same clientId again (a retry after a lost answer): the same entry, never a second one.
        foreach ($rows as $r) {
            if (isset($r['clientId']) && $r['clientId'] === $v['clientId']) {
                return [200, ['ok' => true, 'entryId' => $r['entryId'], 'repeat' => true]];
            }
        }
        if (!$state['config']['open']) {
            return [403, ['ok' => false, 'code' => 'closed', 'message' => 'Entries are now closed.']];
        }
        if (count($rows) >= MAX_ENTRIES) {
            return [409, ['ok' => false, 'code' => 'full', 'message' => 'Sorry, this draw is full.']];
        }
        $phone = mld_norm_phone($v['number']);
        $email = mld_norm_email($v['email']);
        $field = null;
        foreach ($rows as $r) {
            if ($phone !== '' && mld_norm_phone(isset($r['number']) ? $r['number'] : '') === $phone) {
                $field = 'number';
                break;
            }
        }
        if ($field === null) {
            foreach ($rows as $r) {
                if ($email !== '' && mld_norm_email(isset($r['email']) ? $r['email'] : '') === $email) {
                    $field = 'email';
                    break;
                }
            }
        }
        if ($field !== null) {
            return [409, ['ok' => false, 'code' => 'duplicate', 'field' => $field,
                'message' => 'This phone number or email has already been entered.']];
        }

        $seq = $state['seq'];
        foreach ($rows as $r) {
            $seq = max($seq, $r['seq']);
        }
        $seq++;
        $now = mld_now_iso();
        $entry = [
            'seq' => $seq,
            'entryId' => bin2hex(random_bytes(10)),
            'clientId' => $v['clientId'],
            'receivedAt' => $now,
            'name' => $v['name'],
            'number' => $v['number'],
            'email' => $v['email'],
            'consent' => true,
            'consentAt' => $now,
            'marketing' => $v['marketing'],
            'adult' => true, // confirmed "I am aged 18 or over"
        ];
        mld_entries_append($entry, $raw);
        $state['seq'] = $seq;
        $state['count'] = count($rows) + 1;
        mld_state_write($state);
        $rows[] = $entry;
        mld_backup_write($rows, $state['winners']); // the Excel backup always includes this sign-up
        return [201, ['ok' => true, 'entryId' => $entry['entryId']]];
    });
    mld_send($result[0], $result[1]);
}

/** The same rules as the app's form: all three fields are required; consent and "18 or over" must be ticked. */
function mld_validate_submission(array $b)
{
    $errors = [];
    $name = mld_clean_line(mld_field($b, 'name'));
    $number = mld_clean_line(mld_field($b, 'number'));
    $email = mld_clean_line(mld_field($b, 'email'));

    if ($name === '') {
        $errors['name'] = 'Please enter a name.';
    } elseif (mld_len($name) > 80) {
        $errors['name'] = 'Name must be 80 characters or fewer.';
    }
    if ($number === '') {
        $errors['number'] = 'Please enter a phone number.';
    } elseif (!mld_valid_phone($number)) {
        $errors['number'] = 'Enter a valid phone number (7–15 digits; +, spaces, dashes and brackets are fine).';
    }
    if ($email === '') {
        $errors['email'] = 'Please enter an email address.';
    } elseif (!mld_valid_email($email)) {
        $errors['email'] = 'Enter a valid email address, like name@example.com.';
    }
    if (!array_key_exists('consent', $b) || $b['consent'] !== true) {
        $errors['consent'] = 'You must tick “I agree” to enter the draw.';
    }
    if (!array_key_exists('adult', $b) || $b['adult'] !== true) {
        $errors['adult'] = 'You must be 18 or over to enter the draw.';
    }
    $clientId = isset($b['clientId']) ? $b['clientId'] : null;
    if (!is_string($clientId) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $clientId)) {
        $errors['clientId'] = 'Missing or invalid clientId.';
    }
    if ($errors) {
        $field = array_keys($errors)[0];
        mld_fail(400, 'invalid', $errors[$field], $field, ['errors' => $errors]);
    }
    return [
        'clientId' => $clientId,
        'name' => $name,
        'number' => $number,
        'email' => $email,
        'marketing' => isset($b['marketing']) && $b['marketing'] === true,
    ];
}

/** A text field from the JSON body: strings as they are, whole numbers as text, anything else empty. */
function mld_field(array $b, $key)
{
    if (!isset($b[$key])) {
        return '';
    }
    $v = $b[$key];
    if (is_string($v)) {
        return $v;
    }
    return is_int($v) ? (string) $v : '';
}

/* =============================================================================================== *
 * Admin actions (the organiser's laptop). PHP session cookie "mld_session".
 * =============================================================================================== */

/** POST login: { password, quiet? } — quiet: a log-in only to clear this draw from another draw's "All draws" (it doesn't
    count as a laptop using this draw). */
function mld_action_login()
{
    $GLOBALS['mld_soft'] = isset($_GET['soft']) && $_GET['soft'] === '1';
    mld_require_csrf();
    if (!mld_password_is_set()) {
        mld_fail(503, 'password_not_set', 'Open api.php and set ADMIN_PASSWORD first.');
    }
    $b = mld_json_body();
    $password = isset($b['password']) ? $b['password'] : null;
    $key = mld_ip_key('login');

    // Check the lock, compare and count the failure in one step, so parallel guesses can't slip through.
    $outcome = mld_limits(function (array &$limits) use ($key, $password) {
        $now = time();
        $rec = isset($limits[$key]) ? $limits[$key] : null;
        if ($rec && $rec[3] > $now) {
            return ['locked', $rec[3] - $now];
        }
        $ok = is_string($password) && hash_equals(hash('sha256', ADMIN_PASSWORD), hash('sha256', $password));
        if ($ok) {
            unset($limits[$key]);
            return ['ok', 0];
        }
        if (!$rec || $now - $rec[0] >= LOGIN_LOCK_SECONDS) {
            $rec = [$now, 0, LOGIN_LOCK_SECONDS, 0];
        }
        $rec[1]++;
        if ($rec[1] >= LOGIN_MAX_FAILS) {
            $rec[3] = $now + LOGIN_LOCK_SECONDS;
        }
        $limits[$key] = $rec;
        return ['bad', 0];
    });
    if ($outcome[0] === 'locked') {
        $mins = (int) ceil($outcome[1] / 60);
        mld_fail(429, 'locked', 'Too many wrong passwords. Please wait ' . $mins . ' minute' . ($mins === 1 ? '' : 's')
            . ' and try again.', null, ['retryAfter' => $outcome[1]], ['Retry-After: ' . $outcome[1]]);
    }
    if ($outcome[0] !== 'ok') {
        mld_fail(401, 'bad_password', 'That password is not right.');
    }

    mld_session_setup();
    if (@session_start() !== true) {
        mld_fail(500, 'storage', 'The server could not start a login session. Please make sure PHP can write to the "data" folder next to api.php.');
    }
    if (!@session_regenerate_id(true)) {
        mld_fail(500, 'storage', 'The server could not start a login session. Please make sure PHP can write to the "data" folder next to api.php.');
    }
    $_SESSION = [
        'mld_admin' => true,
        'mld_at' => time(),
        'mld_fp' => mld_fingerprint(),
    ];
    $sid = session_id();
    session_write_close();
    mld_session_cleanup();
    // A laptop that has just logged in counts as using this draw straight away, so a second laptop logging in
    // at the same moment is told (see action=session, othersActive). It doesn't make the draw's event "recently
    // used" yet (lastActiveAgo): only running the wheel does.
    if (!(isset($b['quiet']) && $b['quiet'] === true)) {
        mld_active_touch(is_string($sid) ? $sid : '', true, false);
    }
    mld_send(200, ['ok' => true]);
}

/** POST logout */
function mld_action_logout()
{
    mld_require_csrf();
    if (isset($_COOKIE['mld_session']) && is_string($_COOKIE['mld_session']) && $_COOKIE['mld_session'] !== '') {
        mld_active_forget($_COOKIE['mld_session']); // this laptop no longer counts as running the draw
        if (mld_session_known($_COOKIE['mld_session']) && @session_start() === true) {
            $_SESSION = [];
            @session_destroy();
        }
    }
    $paths = [mld_cookie_path()];
    if ($paths[0] !== '/') {
        $paths[] = '/'; // a log-in cookie from before each draw folder had its own
    }
    foreach ($paths as $path) {
        setcookie('mld_session', '', [
            'expires' => 1,
            'path' => $path,
            'secure' => mld_is_https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    mld_send(200, ['ok' => true]);
}

/**
 * GET session: { ok, othersActive } when logged in, else 401 (with &soft=1: 200 { ok:false, code:"unauthorised" }). othersActive: another logged-in laptop (not this one)
 * has used this draw in the last MLD_ACTIVE_SECONDS seconds.
 */
function mld_action_session()
{
    // &soft=1 (a page checking whether its stored log-in is still valid): "not logged in" is an ordinary answer then,
    // 200 + ok:false, so the browser doesn't log an expired log-in as an error.
    if (isset($_GET['soft']) && $_GET['soft'] === '1' && (!mld_password_is_set() || !mld_is_admin())) {
        mld_send(200, ['ok' => false, 'code' => 'unauthorised', 'message' => 'Please log in.']);
    }
    mld_require_admin();
    $sid = isset($_COOKIE['mld_session']) && is_string($_COOKIE['mld_session']) ? $_COOKIE['mld_session'] : '';
    mld_send(200, ['ok' => true, 'othersActive' => mld_active_any($sid === '' ? null : mld_active_key($sid))]);
}

/** GET entries&since=<seq>: sign-ups after that sequence number, oldest first, at most 1000. */
function mld_action_entries()
{
    mld_require_admin();
    mld_active_touch(null, false, !mld_idle()); // the laptop checks every few seconds: this draw is in use
    $sid = isset($_COOKIE['mld_session']) && is_string($_COOKIE['mld_session']) ? $_COOKIE['mld_session'] : '';
    $since = isset($_GET['since']) ? $_GET['since'] : '';
    if (!is_string($since) || ($since !== '' && !preg_match('/^[0-9]{1,15}$/D', $since))) {
        mld_fail(400, 'invalid', 'since must be a whole number.', 'since');
    }
    $since = (int) $since;
    $data = mld_locked(false, function () {
        $raw = '';
        return [mld_state_read(), mld_entries_read($raw)];
    });
    $state = $data[0];
    $rows = $data[1];
    $maxSeq = $state['seq'];
    foreach ($rows as $r) {
        $maxSeq = max($maxSeq, $r['seq']);
    }
    if ($since > $maxSeq) {
        $since = 0; // the data folder was reset: send everything again
    }
    $out = [];
    $more = false;
    foreach ($rows as $r) {
        if ($r['seq'] <= $since) {
            continue;
        }
        if (count($out) >= MLD_PAGE_SIZE) {
            $more = true;
            break;
        }
        $out[] = [
            'seq' => $r['seq'],
            'entryId' => $r['entryId'],
            'receivedAt' => mld_str($r, 'receivedAt'),
            'name' => mld_str($r, 'name'),
            'number' => mld_str($r, 'number'),
            'email' => mld_str($r, 'email'),
            'consent' => !empty($r['consent']),
            'consentAt' => mld_str($r, 'consentAt'),
            'marketing' => !empty($r['marketing']),
            'adult' => !empty($r['adult']),
        ];
    }
    mld_send(200, [
        'ok' => true,
        'epoch' => $state['epoch'],
        'entries' => $out,
        'next' => $out ? $out[count($out) - 1]['seq'] : $since,
        'total' => count($rows),
        'more' => $more,
        'stateVersion' => $state['evVersion'], // the event backup changed (another laptop): fetch action=eventState
        'epochFrom' => $state['epochFrom'],
        'eventId' => $state['eventId'],
        // Is another laptop on this draw right now? (A winner card that a laptop which went away left open is then
        // finished by one that is still here.)
        'othersActive' => mld_active_any($sid === '' ? null : mld_active_key($sid)),
        'now' => mld_now_ms(),
    ]);
}

/** POST setConfig: { open, title, subtitle, consentText, marketingText, showMarketing } (any of them). */
function mld_action_set_config()
{
    mld_require_csrf();
    mld_require_admin();
    $b = mld_json_body();
    // Validate before taking the lock (throws 400 on a wrong type).
    mld_config_apply(MLD_DEFAULT_CONFIG, $b, true);
    $state = mld_locked(true, function () use ($b) {
        $state = mld_state_read();
        $c = mld_config_apply($state['config'], $b, true);
        // The event's date goes with its name: cleared with it, and today's date if the laptop didn't send one.
        if ($c['eventName'] === '') {
            $c['eventDate'] = '';
        } elseif ($c['eventDate'] === '') {
            $c['eventDate'] = date('d-m-Y');
        }
        $state['config'] = $c;
        mld_state_write($state);
        return $state;
    });
    mld_send(200, ['ok' => true, 'config' => mld_public_config($state)]);
}

/**
 * POST clear: { confirm:"DELETE" } — "New event": deletes every sign-up, the Excel backup and the laptops' event
 * backup, and starts a new epoch (every laptop on this draw then starts the new event too).
 * { confirm:"DELETE", keepEvent:true } — "Clear all sign-ups on the server": only the sign-ups and the Excel backup
 * go; the laptops' event backup (their wheel) is kept.
 */
function mld_action_clear()
{
    mld_require_csrf();
    mld_require_admin();
    $b = mld_json_body();
    if (!isset($b['confirm']) || $b['confirm'] !== 'DELETE') {
        mld_fail(400, 'invalid', 'Type DELETE to confirm.', 'confirm');
    }
    $keep = isset($b['keepEvent']) && $b['keepEvent'] === true;
    $state = mld_locked(true, function () use ($keep) {
        $state = mld_state_read();
        $ev = $keep ? mld_event_read() : null;
        if ($ev !== null && $ev['epoch'] !== $state['epoch']) {
            $ev = null;
        }
        $state['epoch'] = bin2hex(random_bytes(8));
        $state['epochAt'] = mld_now_iso();
        $state['epochFrom'] = $keep ? 'signups' : 'newEvent';
        if (!$keep) {
            $state['eventId'] = bin2hex(random_bytes(8)); // a new event: a laptop still holding the old one never merges it in
        }
        $state['count'] = 0;
        $state['evVersion']++;           // laptops notice the change (it keeps counting up, never reused)
        if ($ev !== null) {
            mld_event_write($state['epoch'], $state['evVersion'], $ev['json']);
            $state['winners'] = mld_winners_from_event(json_decode($ev['json'], false, 64));
        } else {
            @unlink(mld_path('event-state.php'));
            $state['evHas'] = false;
            $state['winners'] = ['phones' => [], 'emails' => []];
        }
        if (!$keep) {
            $state['config']['eventName'] = ''; // the next event gets its own name (and Excel file name)
            $state['config']['eventDate'] = '';
        }
        mld_state_write($state);         // seq keeps counting up, so old numbers are never reused
        mld_write_atomic(mld_path('entries.php'), MLD_GUARD . "\n");
        mld_backup_write([], $state['winners']);
        return $state;
    });
    mld_send(200, ['ok' => true, 'epoch' => $state['epoch'], 'version' => $state['evVersion'], 'epochFrom' => $state['epochFrom'],
        'eventId' => $state['eventId']]);
}

/**
 * POST setWinners (organiser only): { winners: [ { number, email } ] } — who has won on the laptop, so the
 * server's Excel backup can show "Winner" next to them too.
 */
function mld_action_set_winners()
{
    mld_require_csrf();
    mld_require_admin();
    $b = mld_json_body();
    $list = isset($b['winners']) && is_array($b['winners']) ? $b['winners'] : null;
    if ($list === null || count($list) > MAX_ENTRIES) {
        mld_fail(400, 'invalid', 'Send the winners as a list.', 'winners');
    }
    $w = ['phones' => [], 'emails' => []];
    foreach ($list as $r) {
        if (!is_array($r)) {
            continue;
        }
        $p = mld_norm_phone(isset($r['number']) && is_string($r['number']) ? mld_cut($r['number'], 40) : '');
        $e = mld_norm_email(isset($r['email']) && is_string($r['email']) ? mld_cut($r['email'], 254) : '');
        if ($p !== '') {
            $w['phones'][] = $p;
        }
        if ($e !== '') {
            $w['emails'][] = $e;
        }
    }
    $w = mld_winners_clean($w);
    mld_locked(true, function () use ($w) {
        $state = mld_state_read();
        // Never removes a mark another laptop's event backup holds: the backup's winners are always included.
        $ev = mld_event_read();
        if ($ev !== null && $ev['epoch'] === $state['epoch']) {
            $w = mld_winners_union($w, mld_winners_from_event(json_decode($ev['json'], false, 64)));
        }
        $state['winners'] = $w;
        mld_state_write($state);
        $raw = '';
        mld_backup_write(mld_entries_read($raw), $w);
    });
    mld_send(200, ['ok' => true]);
}

/* ---- The laptops' event backup (data/event-state.php) ------------------------------------------- *
 * The organiser's laptop stores its whole event for this draw here (everyone on the wheel, including people
 * typed in by hand, the winner log, the round, the prize, the event's name). Another laptop that logs in to this
 * draw restores it, and every laptop on the draw stays in step through it: each store must name the version it
 * was based on, so two laptops never overwrite each other (the second one merges and stores again).            */

/**
 * GET eventState (organiser only): { ok, epoch, version, state } — state is the last event stored for the current
 * epoch (null if none). With &have=<version> and nothing new: { ok, epoch, version, unchanged:true } (no state).
 * POST eventState (organiser only) { epoch, baseVersion, state }: stored when epoch is the current epoch and
 * baseVersion is the current version → { ok, version }; else 409 { code:"conflict", epoch, version, state } or
 * 409 { code:"epoch", epoch } ("New event" cleared the draw meanwhile). With &soft=1 those two answers come with
 * HTTP 200 instead (still ok:false): two laptops on one draw meet them all the time, and browsers log every 409.
 */
function mld_action_event_state()
{
    $post = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST';
    if ($post) {
        mld_require_csrf();
    }
    mld_require_admin();
    mld_active_touch(null, false, !mld_idle());
    if (!$post) {
        $have = isset($_GET['have']) ? $_GET['have'] : '';
        if (!is_string($have) || ($have !== '' && !preg_match('/^[0-9]{1,15}$/D', $have))) {
            mld_fail(400, 'invalid', 'have must be a whole number.', 'have');
        }
        $got = mld_locked(false, function () {
            return [mld_state_read(), mld_event_read()];
        });
        $state = $got[0];
        $sid = isset($_COOKIE['mld_session']) && is_string($_COOKIE['mld_session']) ? $_COOKIE['mld_session'] : '';
        // othersActive: is another laptop on this draw right now (then a winner card it left open stays its own)?
        // othersAgo: seconds since another laptop last checked in (only within the last 10 minutes; else null).
        $me = $sid === '' ? null : mld_active_key($sid);
        $head = mld_event_head($state) + ['othersActive' => mld_active_any($me), 'othersAgo' => mld_active_others_ago($me)];
        if ($have !== '' && (int) $have === $state['evVersion']) {
            mld_send(200, $head + ['unchanged' => true]);
        }
        $ev = $got[1];
        mld_send_with_state(200, $head, $ev !== null && $ev['epoch'] === $state['epoch'] ? $ev['json'] : null);
    }

    $b = mld_json_body(MLD_EVENT_MAX_BODY, false);
    $epoch = isset($b->epoch) ? $b->epoch : null;
    $base = isset($b->baseVersion) ? $b->baseVersion : null;
    $doc = isset($b->state) ? $b->state : null;
    if (!is_string($epoch) || $epoch === '' || strlen($epoch) > 64) {
        mld_fail(400, 'invalid', 'epoch must be text.', 'epoch');
    }
    if (!is_int($base) || $base < 0) {
        mld_fail(400, 'invalid', 'baseVersion must be a whole number.', 'baseVersion');
    }
    if (!is_object($doc)) {
        mld_fail(400, 'invalid', 'state must be an object.', 'state');
    }
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json) || strlen($json) > MLD_EVENT_MAX_BODY) {
        mld_fail(413, 'too_large', 'The event is too large to back up on the server.');
    }
    $winners = mld_winners_from_event($doc);
    $has = mld_event_has_content($doc);
    $result = mld_locked(true, function () use ($epoch, $base, $json, $winners, $has) {
        $state = mld_state_read();
        if ($epoch !== $state['epoch']) {
            return ['epoch', $state, null];
        }
        if ($base !== $state['evVersion']) {
            $ev = mld_event_read();
            return ['conflict', $state, $ev !== null && $ev['epoch'] === $state['epoch'] ? $ev['json'] : null];
        }
        $state['evVersion']++;
        $state['evHas'] = $has;
        mld_event_write($state['epoch'], $state['evVersion'], $json);
        $changed = $winners !== $state['winners'];
        $state['winners'] = $winners;
        mld_state_write($state);
        if ($changed) { // the Excel backup's Winner column follows the event (whichever laptop drew them)
            $raw = '';
            mld_backup_write(mld_entries_read($raw), $winners);
        }
        return ['ok', $state, null];
    });
    $state = $result[1];
    $status = isset($_GET['soft']) && $_GET['soft'] === '1' ? 200 : 409;
    if ($result[0] === 'epoch') {
        mld_send($status, ['ok' => false, 'code' => 'epoch', 'message' => 'This draw was started afresh (New event) on another laptop.']
            + mld_event_head($state));
    }
    if ($result[0] === 'conflict') {
        mld_send_with_state($status, ['ok' => false, 'code' => 'conflict', 'message' => 'Another laptop saved this event first.']
            + mld_event_head($state), $result[2]);
    }
    mld_send(200, ['ok' => true, 'epoch' => $state['epoch'], 'version' => $state['evVersion'], 'now' => mld_now_ms()]);
}

/** The small part of an event answer: the epoch and version, why the epoch began, which event the draw holds (eventId:
    new on "New event", kept when only the sign-ups are cleared), the number of sign-ups. */
function mld_event_head(array $state)
{
    return [
        'ok' => true,
        'epoch' => $state['epoch'],
        'version' => $state['evVersion'],
        'epochFrom' => $state['epochFrom'],
        'eventId' => $state['eventId'],
        'signups' => $state['count'],
        'now' => mld_now_ms(),
    ];
}

/** Sends $data plus "state": the stored event's JSON as it is (never decoded and encoded again), or null. */
function mld_send_with_state($status, array $data, $json)
{
    $head = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($head) || substr($head, -1) !== '}') {
        mld_fail(500, 'server', 'Something went wrong on the server. Please try again.');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
    }
    $GLOBALS['mld_sent'] = true;
    echo substr($head, 0, -1) . ',"state":' . (is_string($json) && $json !== '' ? $json : 'null') . '}';
    exit;
}

/** The stored event backup: ['epoch', 'version', 'json'] or null. Call with the data lock held. */
function mld_event_read()
{
    $raw = @file_get_contents(mld_path('event-state.php'));
    if (!is_string($raw) || strncmp($raw, MLD_GUARD . "\n", strlen(MLD_GUARD) + 1) !== 0) {
        return null;
    }
    $rest = substr($raw, strlen(MLD_GUARD) + 1);
    $nl = strpos($rest, "\n");
    if ($nl === false) {
        return null;
    }
    $head = json_decode(substr($rest, 0, $nl), true);
    $json = rtrim(substr($rest, $nl + 1), "\r\n");
    if (!is_array($head) || !isset($head['epoch'], $head['version']) || !is_string($head['epoch'])
        || !is_int($head['version']) || $json === '' || $json[0] !== '{') {
        return null;
    }
    return ['epoch' => $head['epoch'], 'version' => $head['version'], 'json' => $json];
}

/** Stores the event backup (guard line, a small header line, the event's JSON). Call with the exclusive lock held. */
function mld_event_write($epoch, $version, $json)
{
    $head = json_encode(['epoch' => $epoch, 'version' => $version, 'at' => mld_now_iso()], JSON_UNESCAPED_SLASHES);
    mld_write_atomic(mld_path('event-state.php'), MLD_GUARD . "\n" . $head . "\n" . $json . "\n");
}

/** Does the laptop's event hold anything (people, winners, or a name)? */
function mld_event_has_content($doc)
{
    if (!is_object($doc)) {
        return false;
    }
    if ((isset($doc->entries) && is_array($doc->entries) && $doc->entries)
        || (isset($doc->log) && is_array($doc->log) && $doc->log)) {
        return true;
    }
    return isset($doc->ev) && is_object($doc->ev) && isset($doc->ev->name) && is_string($doc->ev->name)
        && trim($doc->ev->name) !== '';
}

/**
 * Who has won in a laptop's event: every "Winner" record in its winner log (not No-shows), by the phone number and
 * email on the record and on that person's entry. The server's Excel backup marks them "Winner".
 */
function mld_winners_from_event($doc)
{
    $w = ['phones' => [], 'emails' => []];
    if (!is_object($doc) || !isset($doc->log) || !is_array($doc->log)) {
        return $w;
    }
    $byId = [];
    if (isset($doc->entries) && is_array($doc->entries)) {
        foreach ($doc->entries as $e) {
            if (is_object($e) && isset($e->id) && is_string($e->id)) {
                $byId[$e->id] = $e;
            }
        }
    }
    $add = function ($rec) use (&$w) {
        $p = mld_norm_phone(isset($rec->number) && is_string($rec->number) ? mld_cut($rec->number, 64) : '');
        $e = mld_norm_email(isset($rec->email) && is_string($rec->email) ? mld_cut($rec->email, 254) : '');
        if ($p !== '') {
            $w['phones'][$p] = true;
        }
        if ($e !== '') {
            $w['emails'][$e] = true;
        }
    };
    foreach ($doc->log as $r) {
        if (!is_object($r) || !isset($r->type) || $r->type !== 'Winner') {
            continue;
        }
        $add($r);
        if (isset($r->entryId) && is_string($r->entryId) && isset($byId[$r->entryId])) {
            $add($byId[$r->entryId]);
        }
    }
    return mld_winners_clean(['phones' => array_keys($w['phones']), 'emails' => array_keys($w['emails'])]);
}

function mld_winners_union(array $a, array $b)
{
    return mld_winners_clean([
        'phones' => array_values(array_unique(array_merge($a['phones'], $b['phones']))),
        'emails' => array_values(array_unique(array_merge($a['emails'], $b['emails']))),
    ]);
}

/* ---- Excel backup ------------------------------------------------------------------------------- */

/**
 * GET exportXlsx (organiser only): the Excel backup of every phone sign-up (Name | Phone number | Email),
 * rebuilt after each sign-up. It lives in data/backup-xlsx.php behind the same guard line as the other
 * data files, so it can only be downloaded through here, after logging in.
 */
function mld_action_export_xlsx()
{
    mld_require_admin();
    $got = mld_locked(false, function () {
        return [mld_backup_read(), mld_state_read()['config']];
    });
    $bytes = $got[0];
    if ($bytes === null) { // missing or damaged: rebuild it from the sign-ups
        $bytes = mld_locked(true, function () {
            $raw = '';
            $rows = mld_entries_read($raw);
            return mld_backup_write($rows, mld_state_read()['winners']);
        });
    }
    // "<Event name> <DD-MM-YYYY> (server backup).xlsx", e.g. "Newcastle 10-10-2026 (server backup).xlsx"
    $name = mld_file_stem($got[1]) . ' (server backup).xlsx';
    if (!headers_sent()) {
        header_remove('X-Powered-By');
        http_response_code(200);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: ' . mld_content_disposition($name));
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
    }
    echo $bytes;
    exit;
}

/**
 * "<Event name> <DD-MM-YYYY>", safe as a file name (the same rule as the app): \ / : * ? " < > | and control
 * characters removed, spaces collapsed, at most 80 characters; "Lucky Draw" when there is no name yet.
 */
function mld_file_stem(array $config)
{
    $name = isset($config['eventName']) && is_string($config['eventName']) ? $config['eventName'] : '';
    $name = (string) preg_replace('/[\\\\\/:*?"<>|\x{0000}-\x{001F}\x{007F}-\x{009F}\x{FFFE}\x{FFFF}]/u', '', $name);
    $name = trim((string) preg_replace('/[' . MLD_WS . ']+/u', ' ', $name), ' ');
    $name = trim(mld_cut(ltrim($name, '. '), 80), ' ');
    if ($name === '') {
        $name = 'Lucky Draw';
    }
    $date = isset($config['eventDate']) && is_string($config['eventDate'])
        && preg_match('/^[0-9]{2}-[0-9]{2}-[0-9]{4}$/D', $config['eventDate']) ? $config['eventDate'] : date('d-m-Y');
    return $name . ' ' . $date;
}

/** RFC 6266: the UTF-8 name in filename*, and a plain-ASCII copy in filename for older browsers. */
function mld_content_disposition($name)
{
    $ascii = (string) preg_replace('/[^\x20-\x7E]/u', '_', $name);
    $ascii = str_replace(['"', '\\', '%'], '_', $ascii);
    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

/** The stored backup's bytes, or null when it is missing or damaged. Call with a lock held. */
function mld_backup_read()
{
    $raw = @file_get_contents(mld_path('backup-xlsx.php'));
    if (!is_string($raw) || strncmp($raw, MLD_GUARD . "\n", strlen(MLD_GUARD) + 1) !== 0) {
        return null;
    }
    $bytes = base64_decode(substr($raw, strlen(MLD_GUARD) + 1), true);
    return (is_string($bytes) && strncmp($bytes, "PK\x03\x04", 4) === 0) ? $bytes : null;
}

/** Rebuilds the Excel backup from these sign-ups and returns its bytes. Call with the exclusive lock held. */
function mld_backup_write(array $rows, array $winners)
{
    $bytes = mld_xlsx($rows, $winners);
    mld_write_atomic(mld_path('backup-xlsx.php'), MLD_GUARD . "\n" . base64_encode($bytes));
    return $bytes;
}

/** A one-sheet workbook ("Entrants": Name | Phone number | Email | Winner), the same layout as the wheel's Excel. */
function mld_xlsx(array $rows, array $winners)
{
    $wp = array_flip(isset($winners['phones']) ? $winners['phones'] : []);
    $we = array_flip(isset($winners['emails']) ? $winners['emails'] : []);
    $cell = function ($ref, $text, $style) {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $text);
        if ($text === null || $text === '') {
            return '';
        }
        $s = $style ? ' s="1"' : '';
        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
            . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
    };
    $sheet = '<row r="1">' . $cell('A1', 'Name', true) . $cell('B1', 'Phone number', true) . $cell('C1', 'Email', true)
        . $cell('D1', 'Winner', true) . '</row>';
    $n = 1;
    foreach ($rows as $r) {
        $n++;
        $sheet .= '<row r="' . $n . '">' . $cell('A' . $n, mld_str($r, 'name'), false)
            . $cell('B' . $n, mld_str($r, 'number'), false) . $cell('C' . $n, mld_str($r, 'email'), false)
            . $cell('D' . $n, (isset($wp[mld_norm_phone(mld_str($r, 'number'))]) || isset($we[mld_norm_email(mld_str($r, 'email'))])) ? 'Winner' : '', false)
            . '</row>';
    }
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $ns = 'http://schemas.openxmlformats.org';
    $files = [
        '[Content_Types].xml' => $x . '<Types xmlns="' . $ns . '/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => $x . '<Relationships xmlns="' . $ns . '/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $ns . '/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => $x . '<workbook xmlns="' . $ns . '/spreadsheetml/2006/main" xmlns:r="' . $ns . '/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Entrants" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Entrants!$A$1:$D$' . $n . '</definedName></definedNames>'
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' => $x . '<Relationships xmlns="' . $ns . '/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $ns . '/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $ns . '/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => $x . '<styleSheet xmlns="' . $ns . '/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF6E006E"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => $x . '<worksheet xmlns="' . $ns . '/spreadsheetml/2006/main">'
            . '<dimension ref="A1:D' . $n . '"/>'
            . '<sheetViews><sheetView workbookViewId="0" tabSelected="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols><col min="1" max="1" width="32" customWidth="1"/><col min="2" max="2" width="20" customWidth="1"/><col min="3" max="3" width="38" customWidth="1"/><col min="4" max="4" width="12" customWidth="1"/></cols>'
            . '<sheetData>' . $sheet . '</sheetData>'
            . '<autoFilter ref="A1:D' . $n . '"/>'
            . '</worksheet>',
    ];
    return mld_zip_store($files);
}

/** A minimal ZIP writer (stored, no compression), so no PHP extension is needed. */
function mld_zip_store(array $files)
{
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    $out = '';
    $central = '';
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $len = strlen($data);
        $offset = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0) . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $len, $len,
            strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    $count = count($files);
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out), 0);
}

/* ---- Admin helpers ------------------------------------------------------------------------------ */

/** Every admin POST must carry X-Requested-With: map-lucky-draw (other websites cannot add it). */
function mld_require_csrf()
{
    $h = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '';
    if ($h !== 'map-lucky-draw') {
        mld_fail(403, 'csrf', 'This request is missing its security header.');
    }
}

function mld_password_is_set()
{
    $p = (string) ADMIN_PASSWORD;
    // Only the placeholder and an empty password are refused; the organiser chooses the strength.
    return $p !== 'change-this-password' && trim($p, MLD_TRIM) !== '';
}

function mld_require_admin()
{
    if (!mld_password_is_set() || !mld_is_admin()) {
        mld_fail(401, 'unauthorised', 'Please log in.');
    }
}

function mld_is_admin()
{
    static $known = null; // asked once per request (a second session_start would send the cookie again)
    if ($known !== null) {
        return $known;
    }
    $known = false;
    if (!isset($_COOKIE['mld_session']) || !mld_session_known($_COOKIE['mld_session'])) {
        return false; // no cookie, or one this server never issued: never create a session for anonymous visitors
    }
    if (@session_start(['read_and_close' => true]) !== true) {
        return false;
    }
    $s = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : [];
    $_SESSION = [];
    $known = !empty($s['mld_admin'])
        && isset($s['mld_at'], $s['mld_fp']) && is_string($s['mld_fp'])
        && time() - (int) $s['mld_at'] < SESSION_HOURS * 3600
        && hash_equals(mld_fingerprint(), $s['mld_fp']);
    return $known;
}

/**
 * Is $id a log-in session this server issued (its session file exists)? Checked before any session is started, so a
 * made-up or expired cookie never creates a session file or gets a new cookie back. Also sets up the session.
 */
function mld_session_known($id)
{
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9,-]{22,256}$/D', $id)) {
        return false;
    }
    mld_session_setup();
    return is_file(mld_path('sessions') . '/sess_' . $id);
}

/** Changes whenever ADMIN_PASSWORD changes, so a new password logs every laptop out. */
function mld_fingerprint()
{
    $state = mld_state();
    return hash_hmac('sha256', 'session:' . ADMIN_PASSWORD, $state['secret']);
}

/** Cookie mld_session: HttpOnly, SameSite=Strict, Secure on HTTPS. Session files live in data/sessions. */
function mld_session_setup()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    mld_prepare_dir();
    if (!function_exists('session_start')) {
        mld_fail(500, 'storage', 'PHP sessions are switched off on this server, so the organiser cannot log in.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // session.auto_start: close that one, we use our own
    }
    $dir = mld_path('sessions');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        clearstatcache();
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        mld_storage_error();
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    ini_set('session.save_handler', 'files');
    session_save_path($dir);
    session_name('mld_session');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.lazy_write', '1');
    ini_set('session.gc_maxlifetime', (string) (SESSION_HOURS * 3600));
    session_cache_limiter(''); // keep our Cache-Control: no-store
    session_set_cookie_params([
        'lifetime' => SESSION_HOURS * 3600,
        'path' => mld_cookie_path(), // this folder only: logging in to /draw2/ never replaces the /draw/ login
        'domain' => '',
        'secure' => mld_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/** This folder's address path, e.g. "/draw2/": the log-in cookie belongs to this folder only. */
function mld_cookie_path()
{
    $script = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '' || $dir[0] !== '/') {
        return '/';
    }
    $dir = rtrim($dir, '/') . '/';
    // Only characters that are safe in a cookie's Path (never ";" or ","); anything else: the whole site.
    return preg_match('#^/[A-Za-z0-9._~!$&\'()*+=@%/-]*$#D', $dir) ? $dir : '/';
}

/* ---- "In use": which logged-in laptops are running this draw (data/active.php) ----------------- *
 * One record per log-in session, keyed by a keyed hash of its session id (never the id itself), holding
 * only the time that laptop last checked for sign-ups. The public config turns this into a yes/no.  */

function mld_active_key($sid)
{
    $state = mld_state();
    return substr(hash_hmac('sha256', 'active:' . (string) $sid, $state['secret']), 0, 32);
}

/** &idle=1: the laptop is logged in but not running the wheel yet (log-in, "Name your event", a held event's screen). */
function mld_idle()
{
    return isset($_GET['idle']) && $_GET['idle'] === '1';
}

/**
 * Called on every organiser poll (and at log-in, with that new session's id); writes at most every
 * MLD_ACTIVE_WRITE_EVERY seconds per session unless $force. $use: the laptop is running the wheel, so the draw's event
 * counts as used now ("~last", lastActiveAgo); a log-in or a laptop that is only looking keeps the draw "in use"
 * (organiserActive) without making an old event recent again.
 */
function mld_active_touch($sid = null, $force = false, $use = true)
{
    if ($sid === null) {
        $sid = isset($_COOKIE['mld_session']) && is_string($_COOKIE['mld_session']) ? $_COOKIE['mld_session'] : '';
    }
    if (!is_string($sid) || $sid === '') {
        return;
    }
    $key = mld_active_key($sid);
    $now = time();
    $list = mld_guarded_read('active.php');
    $fresh = function ($k) use ($list, $now) {
        return isset($list[$k]) && is_int($list[$k]) && $list[$k] <= $now && $now - $list[$k] < MLD_ACTIVE_WRITE_EVERY;
    };
    if (!$force && $fresh($key) && (!$use || $fresh(MLD_ACTIVE_LAST))) {
        return;
    }
    mld_guarded_update('active.php', function (array &$list) use ($key, $now, $use) {
        $list = mld_active_clean($list, $now);
        $list[$key] = $now;
        if ($use) {
            $list[MLD_ACTIVE_LAST] = $now;
        }
    });
}

/** Logging out: this session no longer keeps the draw "in use". */
function mld_active_forget($sid)
{
    $key = mld_active_key($sid);
    if (!array_key_exists($key, mld_guarded_read('active.php'))) {
        return;
    }
    mld_guarded_update('active.php', function (array &$list) use ($key) {
        $list = mld_active_clean($list, time());
        unset($list[$key]);
    });
}

/**
 * True when any logged-in laptop checked for sign-ups in the last MLD_ACTIVE_SECONDS seconds ($exceptKey: not
 * counting that session, e.g. the laptop that asks).
 */
function mld_active_any($exceptKey = null)
{
    $now = time();
    foreach (mld_guarded_read('active.php') as $k => $t) {
        if ($k === MLD_ACTIVE_LAST || $k === $exceptKey) {
            continue;
        }
        if (is_int($t) && $t <= $now + 5 && $now - $t <= MLD_ACTIVE_SECONDS) {
            return true;
        }
    }
    return false;
}

/** Seconds since another laptop (not $exceptKey) last checked in, while its record is kept (10 min); else null. */
function mld_active_others_ago($exceptKey)
{
    $now = time();
    $best = null;
    foreach (mld_guarded_read('active.php') as $k => $t) {
        if ($k !== MLD_ACTIVE_LAST && $k !== $exceptKey && is_int($t) && $t <= $now + 5 && ($best === null || $t > $best)) {
            $best = $t;
        }
    }
    return $best === null || $now - $best >= MLD_ACTIVE_KEEP ? null : max(0, $now - $best);
}

/** Seconds since an organiser's laptop last ran the wheel here, rounded to the minute; null if never. */
function mld_active_ago()
{
    $list = mld_guarded_read('active.php');
    $t = isset($list[MLD_ACTIVE_LAST]) && is_int($list[MLD_ACTIVE_LAST]) ? $list[MLD_ACTIVE_LAST] : null;
    if ($t === null) {
        foreach ($list as $k => $v) { // records written before "~last" existed
            if ($k !== MLD_ACTIVE_LAST && is_int($v) && ($t === null || $v > $t)) {
                $t = $v;
            }
        }
    }
    if ($t === null) {
        return null;
    }
    return (int) (round(max(0, time() - $t) / 60) * 60);
}

function mld_active_clean(array $list, $now)
{
    $out = [];
    foreach ($list as $k => $t) {
        if (is_string($k) && preg_match('/^[0-9a-f]{32}$/D', $k) && is_int($t) && $t <= $now + 5 && $now - $t < MLD_ACTIVE_KEEP) {
            $out[$k] = $t;
        }
    }
    if (isset($list[MLD_ACTIVE_LAST]) && is_int($list[MLD_ACTIVE_LAST]) && $list[MLD_ACTIVE_LAST] <= $now + 5) {
        $out[MLD_ACTIVE_LAST] = $list[MLD_ACTIVE_LAST];
    }
    return $out;
}

/** Deletes session files older than SESSION_HOURS (hosts often switch PHP's own clean-up off). */
function mld_session_cleanup()
{
    $files = glob(mld_path('sessions') . '/sess_*');
    if (!$files) {
        return;
    }
    $cutoff = time() - SESSION_HOURS * 3600 - 60;
    foreach ($files as $f) {
        $t = @filemtime($f);
        if ($t !== false && $t < $cutoff) {
            @unlink($f);
        }
    }
}

function mld_is_https()
{
    $s = $_SERVER;
    if (!empty($s['HTTPS']) && strtolower((string) $s['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($s['SERVER_PORT']) && (string) $s['SERVER_PORT'] === '443') {
        return true;
    }
    if (isset($s['REQUEST_SCHEME']) && strtolower((string) $s['REQUEST_SCHEME']) === 'https') {
        return true;
    }
    // Behind a proxy that ends HTTPS. Trusting these can only ever add the Secure flag.
    if (isset($s['HTTP_X_FORWARDED_PROTO'])) {
        $p = explode(',', (string) $s['HTTP_X_FORWARDED_PROTO']);
        if (strtolower(trim($p[0], MLD_TRIM)) === 'https') {
            return true;
        }
    }
    return isset($s['HTTP_X_FORWARDED_SSL']) && strtolower((string) $s['HTTP_X_FORWARDED_SSL']) === 'on';
}

/* =============================================================================================== *
 * Request body
 * =============================================================================================== */

/**
 * The JSON object in the request body: 415 if not JSON, 413 if over $max bytes (8 KB unless the action allows more),
 * 400 if malformed. $assoc false: objects stay objects (so {} and [] are told apart).
 */
function mld_json_body($max = MLD_MAX_BODY, $assoc = true)
{
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE']
        : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : '');
    $type = strtolower(trim(explode(';', (string) $ct, 2)[0], MLD_TRIM));
    if ($type !== 'application/json') {
        mld_fail(415, 'unsupported_media_type', 'Please send JSON (Content-Type: application/json).');
    }
    $len = isset($_SERVER['CONTENT_LENGTH']) ? trim((string) $_SERVER['CONTENT_LENGTH'], MLD_TRIM) : '';
    if ($len !== '' && (!preg_match('/^[0-9]{1,12}$/D', $len) || (int) $len > $max)) {
        mld_fail(413, 'too_large', 'The request is too large.');
    }
    $in = @fopen('php://input', 'rb');
    $raw = $in ? stream_get_contents($in, $max + 1) : '';
    if ($in) {
        fclose($in);
    }
    if (!is_string($raw)) {
        $raw = '';
    }
    if (strlen($raw) > $max) {
        mld_fail(413, 'too_large', 'The request is too large.');
    }
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    $raw = trim($raw, MLD_TRIM);
    if ($raw === '' || $raw[0] !== '{') {
        mld_fail(400, 'bad_request', 'The request must be a JSON object.');
    }
    $depth = $assoc ? 32 : 64;
    $data = json_decode($raw, $assoc, $depth);
    if (json_last_error() === JSON_ERROR_UTF16) {
        // A lone UTF-16 surrogate escape: drop it, as the app does, and try again.
        $bs = '\\\\';
        $hi = $bs . 'u[dD][89abAB][0-9a-fA-F]{2}';
        $lo = $bs . 'u[dD][c-fC-F][0-9a-fA-F]{2}';
        $raw = preg_replace([
            '/(?<!' . $bs . ')((?:' . $bs . $bs . ')*)' . $hi . '(?!' . $lo . ')/',
            '/(?<!' . $hi . ')(?<!' . $bs . ')((?:' . $bs . $bs . ')*)' . $lo . '/',
        ], '$1', $raw);
        $data = is_string($raw) ? json_decode($raw, $assoc, $depth) : null;
    }
    if (json_last_error() !== JSON_ERROR_NONE || !($assoc ? is_array($data) : is_object($data))) {
        mld_fail(400, 'bad_request', 'The request is not valid JSON.');
    }
    return $data;
}

/* =============================================================================================== *
 * Text hygiene and validation (ported from index.html)
 * =============================================================================================== */

/** The app's cleanText: control characters become spaces; U+FFFE/U+FFFF are removed. */
function mld_clean_text($v)
{
    $s = (string) $v;
    if (!preg_match('//u', $s)) {
        // Not valid UTF-8 (never the case for decoded JSON): replace the bad bytes.
        $s = htmlspecialchars_decode(htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
    }
    $s = preg_replace('/[\x{FFFE}\x{FFFF}]/u', '', $s);
    return (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', ' ', (string) $s);
}

/** The app's cleanLine: cleanText, runs of whitespace collapsed to one space, trimmed. */
function mld_clean_line($v)
{
    $s = preg_replace('/[' . MLD_WS . ']+/u', ' ', mld_clean_text($v));
    return trim((string) $s, ' ');
}

/** Length in characters (code points), like Array.from(s).length. */
function mld_len($s)
{
    $n = preg_match_all('/./su', (string) $s);
    return $n === false ? strlen((string) $s) : $n;
}

/** The first $max characters. */
function mld_cut($s, $max)
{
    return preg_match('/^.{0,' . (int) $max . '}/su', (string) $s, $m) ? $m[0] : '';
}

/** 7–15 digits with an optional leading + and spaces, dashes and brackets. */
function mld_valid_phone($s)
{
    $t = trim((string) $s, ' ');
    if (strlen($t) > 64 || !preg_match('/^\+?[0-9 ()\-]+$/D', $t)) {
        return false;
    }
    $n = strlen((string) preg_replace('/[^0-9]/', '', $t));
    return $n >= 7 && $n <= 15;
}

function mld_valid_email($s)
{
    $t = trim((string) $s, ' ');
    // The app counts UTF-16 code units (JavaScript's length): characters beyond U+FFFF count twice.
    $units = mld_len($t) + (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $t);
    return $units <= 254 && preg_match(MLD_EMAIL_RE, $t) === 1;
}

/** Digits only; UK numbers normalised so 07…, +44 7…, 0044 7… and +44 (0) 7… all match. */
function mld_norm_phone($raw)
{
    $s = (string) preg_replace('/\(\s*0\s*\)/', '', (string) $raw);
    $plus = preg_match('/^\s*\+/', $s) === 1;
    $d = (string) preg_replace('/[^0-9]/', '', $s);
    if ($d === '') {
        return '';
    }
    if (strncmp($d, '0044', 4) === 0) {
        $d = (string) substr($d, 4);
    } elseif ($plus && strncmp($d, '44', 2) === 0) {
        $d = (string) substr($d, 2);
    } elseif (!$plus && strncmp($d, '44', 2) === 0 && strlen($d) === 12) {
        $d = (string) substr($d, 2);
    } else {
        return $d;
    }
    return '0' . ltrim($d, '0');
}

function mld_norm_email($e)
{
    return mld_lower(trim((string) $e, ' '));
}

/**
 * Lower case like JavaScript's toLowerCase(). Uses mbstring when the server has it; otherwise
 * ASCII plus Latin-1, Latin Extended-A, Greek and Cyrillic capitals (enough for real email addresses).
 */
function mld_lower($s)
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    $s = strtolower($s);
    $re = '/[\x{00C0}-\x{00DE}\x{0100}-\x{017F}\x{0386}-\x{03AB}\x{0400}-\x{042F}]/u';
    return (string) preg_replace_callback($re, function ($m) {
        $c = $m[0]; // always two bytes in UTF-8 in these ranges
        $cp = ((ord($c[0]) & 0x1F) << 6) | (ord($c[1]) & 0x3F);
        if ($cp <= 0xDE) {
            $cp += $cp === 0xD7 ? 0 : 32;                        // À–Þ (not ×)
        } elseif ($cp <= 0x17F) {
            if ($cp === 0x130) {
                return "i\xCC\x87";                              // İ → i̇
            }
            if ($cp === 0x178) {
                $cp = 0xFF;                                      // Ÿ → ÿ
            } elseif (($cp >= 0x139 && $cp <= 0x148) || ($cp >= 0x179 && $cp <= 0x17E)) {
                $cp += $cp % 2;                                  // odd capitals
            } elseif ($cp !== 0x138 && $cp % 2 === 0) {
                $cp++;                                           // even capitals
            }
        } elseif ($cp <= 0x3AB) {
            if ($cp === 0x386) {
                $cp = 0x3AC;
            } elseif ($cp >= 0x388 && $cp <= 0x38A) {
                $cp += 37;
            } elseif ($cp === 0x38C) {
                $cp = 0x3CC;
            } elseif ($cp === 0x38E || $cp === 0x38F) {
                $cp += 63;
            } elseif ($cp >= 0x391 && $cp !== 0x3A2) {
                $cp += 32;                                       // Α–Ϋ
            }
        } else {
            $cp += $cp < 0x410 ? 80 : 32;                        // Ѐ–Џ, А–Я
        }
        return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
    }, $s);
}

/** A string field of a stored row. */
function mld_str(array $r, $key)
{
    return isset($r[$key]) && is_string($r[$key]) ? $r[$key] : '';
}

/**
 * Applies $in's settings to $config. Strict: a wrong type is a 400 error; otherwise it is ignored.
 * Texts are cleaned and cut to the app's limits.
 */
function mld_config_apply(array $config, $in, $strict)
{
    $out = array_merge(MLD_DEFAULT_CONFIG, $config);
    if (!is_array($in)) {
        return $out;
    }
    foreach (['open', 'showMarketing'] as $k) {
        if (!array_key_exists($k, $in)) {
            continue;
        }
        if (is_bool($in[$k])) {
            $out[$k] = $in[$k];
        } elseif ($strict) {
            mld_fail(400, 'invalid', $k . ' must be true or false.', $k);
        }
    }
    foreach (MLD_TEXT_LIMITS as $k => $max) {
        if (!array_key_exists($k, $in)) {
            continue;
        }
        if (is_string($in[$k])) {
            $out[$k] = mld_cut(trim(mld_clean_text($in[$k]), ' '), $max);
        } elseif ($strict) {
            mld_fail(400, 'invalid', $k . ' must be text.', $k);
        }
    }
    if (array_key_exists('eventName', $in)) {
        if (is_string($in['eventName'])) {
            $out['eventName'] = trim(mld_cut(mld_clean_line($in['eventName']), MLD_EVENT_NAME_MAX), ' ');
        } elseif ($strict) {
            mld_fail(400, 'invalid', 'eventName must be text.', 'eventName');
        }
    }
    if (array_key_exists('eventDate', $in)) {
        $d = $in['eventDate'];
        if (is_string($d) && ($d === '' || preg_match('/^(0[1-9]|[12][0-9]|3[01])-(0[1-9]|1[0-2])-[0-9]{4}$/D', $d))) {
            $out['eventDate'] = $d;
        } elseif ($strict) {
            mld_fail(400, 'invalid', 'eventDate must be a date like 10-10-2026.', 'eventDate');
        }
    }
    foreach ($out as $k => $v) {
        if (!array_key_exists($k, MLD_DEFAULT_CONFIG) || gettype($v) !== gettype(MLD_DEFAULT_CONFIG[$k])) {
            unset($out[$k]);
        }
    }
    $out = array_merge(MLD_DEFAULT_CONFIG, $out);
    if ($out['consentText'] === '') {
        $out['consentText'] = MLD_DEFAULT_CONFIG['consentText']; // guests must always see what they agree to
    }
    return $out;
}

/** Milliseconds since 1970 (the laptops line their clocks up with the server's). */
function mld_now_ms()
{
    return (int) floor(microtime(true) * 1000);
}

function mld_now_iso()
{
    $t = microtime(true);
    $sec = (int) floor($t);
    return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', min(999, (int) floor(($t - $sec) * 1000)));
}

/* =============================================================================================== *
 * Rate limits: data/limits.php, counters keyed by a keyed hash of the IP address (never the IP).
 * =============================================================================================== */

function mld_client_ip()
{
    $ip = '';
    if (CLIENT_IP_HEADER !== '' && !empty($_SERVER[CLIENT_IP_HEADER]) && is_string($_SERVER[CLIENT_IP_HEADER])) {
        $parts = explode(',', $_SERVER[CLIENT_IP_HEADER]);
        $ip = trim((string) end($parts), MLD_TRIM); // the address added by your own proxy
    }
    if ($ip === '') {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }
    // IPv6: one phone usually has a whole /64, so count the /64.
    if (strpos($ip, ':') !== false && function_exists('inet_pton')) {
        $bin = @inet_pton($ip);
        if (is_string($bin) && strlen($bin) === 16) {
            $ip = substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff"
                ? (string) @inet_ntop(substr($bin, 12))
                : bin2hex(substr($bin, 0, 8)) . '::/64';
        }
    }
    return $ip;
}

function mld_ip_key($bucket)
{
    $state = mld_state();
    return $bucket . ':' . substr(hash_hmac('sha256', mld_client_ip(), $state['secret']), 0, 32);
}

/**
 * Counts one request in a fixed window. Returns 0 when allowed, else the seconds to wait.
 * Counter record: [windowStart, count, windowSeconds, lockedUntil].
 */
function mld_limit_hit($bucket, $max, $window)
{
    $key = mld_ip_key($bucket);
    return mld_limits(function (array &$limits) use ($key, $max, $window) {
        $now = time();
        $rec = isset($limits[$key]) ? $limits[$key] : null;
        if (!$rec || $now - $rec[0] >= $window) {
            $rec = [$now, 0, $window, 0];
        }
        if ($rec[1] >= $max) {
            return max(1, $rec[0] + $window - $now);
        }
        $rec[1]++;
        $limits[$key] = $rec;
        return 0;
    });
}

/** Read-modify-write of data/limits.php under flock(LOCK_EX). A corrupt file simply starts again. */
function mld_limits(callable $fn)
{
    return mld_guarded_update('limits.php', function (array &$limits) use ($fn) {
        // Drop expired and malformed counters, and never keep more than 5000.
        $now = time();
        foreach ($limits as $k => $rec) {
            if (!is_array($rec) || count($rec) !== 4 || !is_int($rec[0]) || !is_int($rec[1]) || !is_int($rec[2])
                || !is_int($rec[3]) || ($now - $rec[0] >= $rec[2] && $rec[3] <= $now)) {
                unset($limits[$k]);
            }
        }
        if (count($limits) > 5000) {
            uasort($limits, function ($a, $b) {
                return $b[0] - $a[0];
            });
            $limits = array_slice($limits, 0, 5000, true);
        }
        return $fn($limits);
    });
}

/* ---- Small guarded JSON files (limits.php, active.php): guard line + one JSON object ------------ */

/** The JSON object in a guarded file's contents; [] when missing or corrupt. */
function mld_guarded_parse($raw)
{
    if (!is_string($raw) || strncmp($raw, MLD_GUARD, strlen(MLD_GUARD)) !== 0) {
        return [];
    }
    $decoded = json_decode(trim(substr($raw, strlen(MLD_GUARD)), MLD_TRIM), true);
    return is_array($decoded) ? $decoded : [];
}

/** Reads a guarded JSON file under a shared lock (never sees a half-written file). Missing: []. */
function mld_guarded_read($name)
{
    $file = mld_path($name);
    if (!is_file($file)) {
        return [];
    }
    $h = @fopen($file, 'rb');
    if (!$h) {
        return [];
    }
    try {
        @flock($h, LOCK_SH);
        $raw = stream_get_contents($h);
    } finally {
        @flock($h, LOCK_UN);
        fclose($h);
    }
    return mld_guarded_parse($raw);
}

/** Read-modify-write of a guarded JSON file under flock(LOCK_EX); $fn gets the data by reference. */
function mld_guarded_update($name, callable $fn)
{
    mld_prepare_dir();
    $file = mld_path($name);
    $h = @fopen($file, 'c+');
    if (!$h) {
        mld_storage_error();
    }
    @chmod($file, 0600);
    try {
        if (!flock($h, LOCK_EX)) {
            mld_storage_error();
        }
        $data = mld_guarded_parse(stream_get_contents($h));
        $result = $fn($data);
        $json = json_encode((object) $data, JSON_UNESCAPED_SLASHES);
        $content = MLD_GUARD . "\n" . ($json === false ? '{}' : $json) . "\n";
        rewind($h);
        if (!ftruncate($h, 0) || fwrite($h, $content) !== strlen($content)) {
            mld_storage_error();
        }
        fflush($h);
        return $result;
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/* =============================================================================================== *
 * Storage: data/entries.php (guard line + one JSON object per line), data/state.php (guard + JSON).
 * Every read-check-write happens under flock(LOCK_EX) on data/lock.php, so phones that submit at
 * the same moment never lose or duplicate an entry.
 * =============================================================================================== */

function mld_path($name)
{
    return DATA_DIR . '/' . $name;
}

function mld_storage_error()
{
    mld_fail(500, 'storage', 'The server could not save sign-ups. Please make sure PHP can create and write to the "data" folder next to api.php.');
}

/** Creates the data folder with .htaccess (deny all) and an empty index.html. */
function mld_prepare_dir()
{
    static $done = false;
    if ($done) {
        return;
    }
    $dir = DATA_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        clearstatcache();
        if (!is_dir($dir)) {
            mld_storage_error();
        }
        @chmod($dir, 0700);
    }
    if (!is_writable($dir)) {
        mld_storage_error();
    }
    if (!is_file($dir . '/.htaccess') && @file_put_contents($dir . '/.htaccess', MLD_HTACCESS, LOCK_EX) === false) {
        mld_storage_error();
    }
    if (!is_file($dir . '/index.html') && @file_put_contents($dir . '/index.html', '', LOCK_EX) === false) {
        mld_storage_error();
    }
    $done = true;
}

/**
 * Runs $fn while holding the data lock (exclusive for writes, shared for reads). Creates missing
 * storage files first.
 */
function mld_locked($exclusive, callable $fn)
{
    mld_prepare_dir();
    $file = mld_path('lock.php');
    $h = @fopen($file, 'c');
    if (!$h) {
        mld_storage_error();
    }
    try {
        if (!flock($h, LOCK_EX)) {
            mld_storage_error();
        }
        $st = fstat($h);
        if ($st && $st['size'] === 0) {
            fwrite($h, MLD_GUARD . "\n");
            fflush($h);
            @chmod($file, 0600);
        }
        mld_init_files();
        if (!$exclusive) {
            flock($h, LOCK_SH);
        }
        return $fn();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/** Under the exclusive lock: make sure entries.php and a valid state.php exist. */
function mld_init_files()
{
    $entries = mld_path('entries.php');
    if (!is_file($entries)) {
        mld_write_atomic($entries, MLD_GUARD . "\n");
    }
    if (mld_state_parse(@file_get_contents(mld_path('state.php'))) === null) {
        // Missing or damaged: start a fresh state, carrying on the numbering from entries.php.
        $raw = '';
        $seq = 0;
        $rows = mld_entries_read($raw);
        foreach ($rows as $r) {
            $seq = max($seq, $r['seq']);
        }
        mld_state_write([
            'v' => 1,
            'epoch' => bin2hex(random_bytes(8)),
            'epochAt' => mld_now_iso(),
            'seq' => $seq,
            'count' => count($rows),
            'secret' => bin2hex(random_bytes(32)),
            'config' => MLD_DEFAULT_CONFIG,
            'winners' => ['phones' => [], 'emails' => []],
            'evVersion' => 0,
            'evHas' => false,
            'epochFrom' => 'start',
            'eventId' => bin2hex(random_bytes(8)),
        ]);
    }
}

/** Current state (read once per request, under a shared lock). */
function mld_state()
{
    static $state = null;
    if ($state === null) {
        $state = mld_locked(false, function () {
            return mld_state_read();
        });
    }
    return $state;
}

/** Reads state.php; call with the lock held (after mld_init_files). */
function mld_state_read()
{
    $s = mld_state_parse(@file_get_contents(mld_path('state.php')));
    if ($s === null) {
        mld_storage_error();
    }
    return $s;
}

function mld_state_parse($raw)
{
    if (!is_string($raw) || strncmp($raw, MLD_GUARD, strlen(MLD_GUARD)) !== 0) {
        return null;
    }
    $s = json_decode(trim(substr($raw, strlen(MLD_GUARD)), MLD_TRIM), true);
    if (!is_array($s) || !isset($s['epoch'], $s['secret'], $s['seq']) || !is_string($s['epoch'])
        || !is_string($s['secret']) || strlen($s['secret']) < 32 || !is_int($s['seq'])) {
        return null;
    }
    return [
        'v' => 1,
        'epoch' => $s['epoch'],
        'epochAt' => isset($s['epochAt']) && is_string($s['epochAt']) ? $s['epochAt'] : '',
        'seq' => max(0, $s['seq']),
        'count' => isset($s['count']) && is_int($s['count']) ? max(0, $s['count']) : 0,
        'secret' => $s['secret'],
        'config' => mld_config_apply(MLD_DEFAULT_CONFIG, isset($s['config']) ? $s['config'] : [], false),
        'winners' => mld_winners_clean(isset($s['winners']) ? $s['winners'] : null),
        // The laptops' event backup: its version (counts up on every store and on "New event"), whether it holds
        // anything, and why the current epoch began: 'newEvent', 'signups' (only the sign-ups were cleared) or
        // 'start' (a new data folder).
        'evVersion' => isset($s['evVersion']) && is_int($s['evVersion']) ? max(0, $s['evVersion']) : 0,
        'evHas' => isset($s['evHas']) && $s['evHas'] === true,
        'epochFrom' => isset($s['epochFrom']) && in_array($s['epochFrom'], ['newEvent', 'signups', 'start'], true) ? $s['epochFrom'] : 'start',
        // Which event this draw holds: random, new on "New event" only (a sign-up clear keeps it), so a laptop that
        // missed a New event can tell its copy belongs to an earlier event. Older state files: their epoch.
        'eventId' => isset($s['eventId']) && is_string($s['eventId']) && preg_match('/^[0-9a-f]{8,64}$/D', $s['eventId'])
            ? $s['eventId'] : $s['epoch'],
    ];
}

/** Winners as sent by the organiser's laptop: normalised phone numbers and emails (at most MAX_ENTRIES each). */
function mld_winners_clean($w)
{
    $out = ['phones' => [], 'emails' => []];
    foreach (['phones', 'emails'] as $k) {
        if (is_array($w) && isset($w[$k]) && is_array($w[$k])) {
            foreach ($w[$k] as $v) {
                if (is_string($v) && $v !== '' && strlen($v) <= 254 && count($out[$k]) < MAX_ENTRIES) {
                    $out[$k][] = $v;
                }
            }
        }
    }
    return $out;
}

function mld_state_write(array $state)
{
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        mld_storage_error();
    }
    mld_write_atomic(mld_path('state.php'), MLD_GUARD . "\n" . $json . "\n");
}

/**
 * All sign-ups in entries.php, oldest first. Lines that are not complete JSON (for example a line
 * cut short by a crash or a full disk) are skipped. $raw receives the file's contents.
 */
function mld_entries_read(&$raw)
{
    $raw = @file_get_contents(mld_path('entries.php'));
    if (!is_string($raw)) {
        mld_storage_error();
    }
    $rows = [];
    $last = 0;
    $sorted = true;
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line, MLD_TRIM);
        if ($line === '' || $line[0] !== '{') {
            continue;
        }
        $r = json_decode($line, true);
        if (!is_array($r) || !isset($r['seq'], $r['entryId']) || !is_int($r['seq']) || !is_string($r['entryId'])) {
            continue;
        }
        if ($r['seq'] <= $last) {
            $sorted = false;
        }
        $last = $r['seq'];
        $rows[] = $r;
    }
    if (!$sorted) {
        usort($rows, function ($a, $b) {
            return $a['seq'] - $b['seq'];
        });
    }
    return $rows;
}

/** Appends one sign-up as a JSON line. Call with the exclusive lock held. */
function mld_entries_append(array $entry, $raw)
{
    $file = mld_path('entries.php');
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line === false) {
        mld_storage_error();
    }
    if (strncmp($raw, MLD_GUARD, strlen(MLD_GUARD)) !== 0) {
        // The guard line is missing (edited by hand?): rewrite the file with it before adding more.
        $keep = [];
        foreach (explode("\n", $raw) as $l) {
            $l = trim($l, MLD_TRIM);
            if ($l !== '' && $l[0] === '{' && is_array(json_decode($l, true))) {
                $keep[] = $l;
            }
        }
        mld_write_atomic($file, MLD_GUARD . "\n" . ($keep ? implode("\n", $keep) . "\n" : ''));
        $raw = MLD_GUARD . "\n";
    }
    // If the last line was cut short, start on a new line so the torn part stays on its own.
    $data = (substr($raw, -1) === "\n" ? '' : "\n") . $line . "\n";
    $h = @fopen($file, 'ab');
    if (!$h) {
        mld_storage_error();
    }
    $written = fwrite($h, $data);
    fflush($h);
    fclose($h);
    if ($written !== strlen($data)) {
        mld_storage_error();
    }
}

/** Writes a whole file via a temporary file and rename, so readers see the old or the new version. */
function mld_write_atomic($file, $content)
{
    $tmp = mld_path('tmp-' . bin2hex(random_bytes(6)) . '.php'); // .php + guard: never readable over the web
    if (@file_put_contents($tmp, $content) !== strlen($content)) {
        @unlink($tmp);
        mld_storage_error();
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        mld_storage_error();
    }
}

/* =============================================================================================== */
try {
    mld_main();
} catch (MldError $e) {
    mld_send($e->status, $e->payload, $e->headers);
}
