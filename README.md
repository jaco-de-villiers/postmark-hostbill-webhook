# Postmark Inbound Webhook to HostBill Maildir Bridge

A robust, production-ready PHP webhook that accepts inbound email payloads from **Postmark**, converts them into compliant RFC 2822 `.msg` files, and drops them directly into a local system **Maildir** infrastructure for **HostBill** to process via its ticket/reply cron job.

## ⚙️ The Problem It Solves
When utilizing modern transactional mail delivery platforms like Postmark for inbound processing, they parse incoming emails and hand them to your application as JSON via webhooks. However, self-hosted billing platforms like HostBill often rely on traditional piping or monitoring a standard IMAP/POP3 Maildir (`Maildir/new`) on the local file system to process tickets and replies.

This script acts as the lightweight middleman: bridging cloud-native JSON payloads backward into perfectly formatted filesystem-compliant mail data without needing a heavy framework or long-running daemons.

---

## 🚀 Key Architectural Improvements (v2)

Compared to basic implementation scripts, this version is hardened for production operations and high-concurrency environments:

* **Immediate 200 HTTP Handshake:** It responds `200 OK` and flushes the buffer to Postmark *before* invoking disk I/O operations. This prevents Postmark timeouts and connection retries during periods of high system load.
* **True MIME Multipart Generation:** Dynamically builds compliant RFC 2822 structures. If an email includes attachments, it properly crafts the multipart boundaries, structures content types, and preserves attachment encoding so HostBill can parse them natively.
* **Intelligent Threading Preservation:** Extracts Postmark's upstream `MessageID` and wraps it into a proper standard mail `Message-ID` header, ensuring HostBill preserves ticket threading and response continuity.
* **Smart Body Parsing:** Prioritizes Postmark's pre-parsed `StrippedTextReply` to cut out old thread noise automatically. It gracefully falls back to raw text or stripped HTML without mangling entities on plain-text streams.
* **Maildir Specification Compliant:** Generates strict filename structures using Unix timestamps, high-precision microtime, and a unique system entropy string (`timestamp.unique.hostname`) to guarantee zero file collisions under heavy concurrency.
* **Upstream Spam Filtering:** Offers a native `X-Spam-Score` header evaluation pass, allowing you to discard garbage payloads at the edge before allocating local disk space.

---

## 🛠️ Data Flow Architecture

1. **Inbound Email** ──> Sent to Postmark.
2. **Postmark Parser** ──> Converts email to JSON payload and POSTs to this script.
3. **HTTP Handshake** ──> Script validates input, immediately replies `200 OK`, and detaches the HTTP process.
4. **Processing & Encoding** ──> Code parses body fields, maps attachments, and encodes contents to Quoted-Printable/Base64.
5. **Disk Write** ──> Generates a unique Maildir filename and commits the raw RFC 2822 payload to `/Maildir/new/`.
6. **HostBill Ingestion** ──> HostBill's automated background cron job scans the directory, detects the file, and imports it as a ticket update.

---

## 📦 Installation & Setup

1. Place `postmark_webhook.php` into a publicly accessible directory on your web server.
2. Edit the configuration section at the top of the file with your specific paths and mailbox variables:

```php
define('TARGET_MAILDIR', '/your/domain/imap/[example.com/mailbox/Maildir/new]');
define('EMAIL_TO',       'mailbox@example.com');
define('SPAM_THRESHOLD', 5.0); // Set to null to disable

3. Ensure that your web server user (e.g., www-data, nginx, or your PHP-FPM pool user) has explicit write permissions to both the script directory (for logging) and the target Maildir/new path.
4. Configure your Inbound Stream Webhook URL inside your Postmark dashboard to point directly to your public file path.
