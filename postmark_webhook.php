<?php
/**
 * Postmark Inbound → HostBill Maildir Webhook
 * =============================================
 * Postmark POSTs inbound emails as JSON to this script.
 * We convert them to RFC 2822 .msg files in the Maildir/new folder
 * that HostBill's cron job monitors for new tickets/replies.
 *
 * Fixes over v1:
 *  - Returns 200 to Postmark immediately before file operations
 *  - Uses Postmark's StrippedTextReply instead of manual separator splitting
 *  - Builds proper MIME multipart email so attachments are preserved
 *  - Adds Message-ID header for correct reply threading
 *  - Maildir-spec compliant filename (timestamp.unique.hostname)
 *  - Skips html_entity_decode on plain text body
 */
// ─── CONFIGURATION ───────────────────────────────────────────────────────────
define('TARGET_MAILDIR', '/your/domain/imap/example.com/mailbox/Maildir/new');
define('EMAIL_TO',       'mailbox@example.com');

// Spam: reject if X-Spam-Score >= this. Set to null to disable.
define('SPAM_THRESHOLD', 5.0);
// ─── END CONFIGURATION ───────────────────────────────────────────────────────

// ─── ERROR LOGGING ───────────────────────────────────────────────────────────
ini_set('display_errors', 0);
ini_set('log_errors',     1);
ini_set('error_log',      __DIR__ . '/postmark_php_errors.log');
error_reporting(E_ALL);

function logMsg(string $msg): void {
    file_put_contents(
        __DIR__ . '/postmark_webhook.log',
        date('Y-m-d H:i:s') . ' - ' . $msg . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// ─── INPUT VALIDATION ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    logMsg('Empty input received');
    exit('Bad Request');
}

$data = json_decode($raw, true);
if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    logMsg('JSON decode failed: ' . json_last_error_msg());
    exit('Invalid JSON');
}

// Maildir must exist before we commit to a 200
if (!is_dir(TARGET_MAILDIR)) {
    logMsg('ERROR: Target Maildir not found: ' . TARGET_MAILDIR);
    http_response_code(500);
    exit('Maildir not found');
}

// ─── RETURN 200 TO POSTMARK IMMEDIATELY ──────────────────────────────────────
// Postmark only needs to know we received it. File I/O happens after this.
http_response_code(200);
header('Content-Type: text/plain');
header('Content-Length: 2');
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ignore_user_abort(true);
    ob_flush();
    flush();
}

// ─── SPAM CHECK ──────────────────────────────────────────────────────────────
if (SPAM_THRESHOLD !== null) {
    foreach (($data['Headers'] ?? []) as $h) {
        if (strcasecmp($h['Name'] ?? '', 'x-spam-score') === 0) {
            if ((float)($h['Value'] ?? 0) >= SPAM_THRESHOLD) {
                logMsg('SPAM_REJECT from ' . ($data['From'] ?? '') .
                       ' score=' . $h['Value']);
                exit;
            }
            break;
        }
    }
}

// ─── PARSE FIELDS ────────────────────────────────────────────────────────────
$from     = $data['From']     ?? '';
$fromName = $data['FromName'] ?? '';
$subject  = $data['Subject']  ?? '(No Subject)';
$replyTo  = $data['ReplyTo']  ?? $from;
$date     = $data['Date']     ?? date('r');
$msgId    = $data['MessageID'] ?? '';  // Postmark's own Message-ID

// Use Postmark's pre-stripped reply text (removes quoted history automatically).
// Fall back to full TextBody, then stripped HtmlBody — in that order.
// Do NOT run html_entity_decode on TextBody; it's already plain text.
if (!empty($data['StrippedTextReply'])) {
    $body = trim($data['StrippedTextReply']);
} elseif (!empty($data['TextBody'])) {
    $body = trim($data['TextBody']);
} elseif (!empty($data['HtmlBody'])) {
    $body = trim(strip_tags($data['HtmlBody']));
} else {
    $body = '(No message body)';
}

// Attachments
$attachments = [];
foreach (($data['Attachments'] ?? []) as $att) {
    if (!empty($att['Name']) && !empty($att['Content'])) {
        $attachments[] = $att;  // ['Name', 'Content' (base64), 'ContentType', 'ContentLength']
    }
}

// ─── BUILD RFC 2822 EMAIL ────────────────────────────────────────────────────
$boundary  = '----=_Part_' . uniqid('', true);
$hostname  = gethostname() ?: 'localhost';
// Generate a proper Message-ID for threading; incorporate Postmark's ID if present
$newMsgId  = '<postmark-' . ($msgId ?: uniqid('', true)) . '@' . $hostname . '>';

$hasAttachments = !empty($attachments);

$email  = '';
$email .= 'From: ' . mime_encode_header($fromName) . ' <' . $from . ">\r\n";
$email .= 'To: '   . EMAIL_TO . "\r\n";
$email .= 'Subject: ' . mime_encode_header($subject) . "\r\n";
$email .= 'Date: '    . $date . "\r\n";
$email .= 'Reply-To: ' . $replyTo . "\r\n";
$email .= 'Message-ID: ' . $newMsgId . "\r\n";
$email .= 'MIME-Version: 1.0' . "\r\n";

if ($hasAttachments) {
    $email .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
    $email .= "\r\n";
    $email .= '--' . $boundary . "\r\n";
    $email .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $email .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n";
    $email .= "\r\n";
    $email .= quoted_printable_encode($body) . "\r\n";

    foreach ($attachments as $att) {
        $email .= '--' . $boundary . "\r\n";
        $email .= 'Content-Type: ' . ($att['ContentType'] ?? 'application/octet-stream') .
                  '; name="' . addslashes($att['Name']) . '"' . "\r\n";
        $email .= 'Content-Transfer-Encoding: base64' . "\r\n";
        $email .= 'Content-Disposition: attachment; filename="' .
                  addslashes($att['Name']) . '"' . "\r\n";
        $email .= "\r\n";
        // Postmark sends it already base64-encoded; just chunk it for MIME compliance
        $email .= chunk_split($att['Content'], 76, "\r\n");
    }

    $email .= '--' . $boundary . '--' . "\r\n";
} else {
    // Simple plain-text email — no boundary needed
    $email .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $email .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n";
    $email .= "\r\n";
    $email .= quoted_printable_encode($body) . "\r\n";
}

// ─── WRITE TO MAILDIR ─────────────────────────────────────────────────────────
// Maildir spec filename: timestamp.unique.hostname
// Using microtime for sub-second uniqueness when multiple emails arrive together
$timestamp = microtime(true);
$unique    = uniqid('', true);
$filename  = sprintf('%d.%s.%s', (int)$timestamp, $unique, $hostname);
$fullpath  = rtrim(TARGET_MAILDIR, '/') . '/' . $filename;

$result = file_put_contents($fullpath, $email, LOCK_EX);

if ($result === false) {
    logMsg('ERROR: Failed to write to ' . $fullpath);
    // Can't send 500 now (already sent 200), just log it
    exit;
}

// Set file permissions so the mail daemon can read it
chmod($fullpath, 0600);

logMsg('OK: ' . $from . ' → ' . $filename .
       ($hasAttachments ? ' [' . count($attachments) . ' attachment(s)]' : '') .
       ($data['StrippedTextReply'] ? ' [stripped reply]' : ''));

// ─── HELPERS ─────────────────────────────────────────────────────────────────

/**
 * RFC 2047 encode a header value if it contains non-ASCII characters.
 * Prevents garbled From/Subject headers with international names.
 */
function mime_encode_header(string $value): string {
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}