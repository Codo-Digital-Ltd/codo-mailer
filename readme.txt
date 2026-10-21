=== Codo Mailer ===
Contributors: cododigital
Tags: smtp, email, amazon ses, email log, deliverability
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure-by-default email delivery: SMTP, Amazon SES, Postmark, Mailgun, Brevo and SendGrid, with a log, resend, backup connection and alerts. Free.

== Description ==

Codo Mailer makes sure the email your WordPress site sends (password resets, order confirmations, form notifications) actually arrives. It routes every `wp_mail()` call through a proper email provider instead of your server's unauthenticated PHP mail.

It is built by [Codo Digital](https://cododigital.co.uk), a UK web development and managed hosting company, and is the mailer we use on our own clients' sites. Everything is free. There is no Pro version, no upsell, no account to create and no tracking.

= Providers =

* Any SMTP server (STARTTLS or SSL/TLS)
* Amazon SES (API, any region, including London `eu-west-2`)
* Postmark
* Mailgun (EU and US regions)
* Brevo
* SendGrid

API providers are used over HTTPS, so blocked SMTP ports on your host don't matter.

= Features =

* **Backup connection** used automatically when the primary fails.
* **Email log** filtered by status, with full details and **resend**.
* **Failure alerts** by email and/or webhook (a Slack incoming webhook works as-is), at most one per hour.
* **Send a test email** from the settings screen.
* **From address control**, with an option to override other plugins' senders.
* Works with WooCommerce, contact form plugins and anything else that uses `wp_mail()`. Core hooks such as `wp_mail_from`, `wp_mail_content_type`, `wp_mail_succeeded` and `wp_mail_failed` keep working. `phpmailer_init` (used by DKIM-signing plugins) runs for SMTP, Amazon SES and Mailgun, which send full MIME; Postmark, Brevo and SendGrid build the message on their side.

= Secure by default =

Email logs hold password-reset links, so a leaky log is a site takeover waiting to happen. Codo Mailer is designed around that:

* Password-reset, set-password and email-confirmation links, one-time tokens, verification codes and PINs, and plaintext passwords are **redacted** from the body and subject before an email is logged or included in an alert, and those emails cannot be resent from the log. Redaction is best-effort, so as a fail-safe, if a secret still shows up once the body is decoded, the whole body is left out of the log.
* The log is only ever shown on the admin screen to administrators (`manage_options`; on multisite, super admins only, because whoever controls mail can read any user's reset email). There are **no REST or AJAX endpoints** for it.
* API keys and passwords are **encrypted at rest** (libsodium), so a database dump alone does not reveal them.
* Or keep credentials out of the database entirely with **`wp-config.php` constants**.
* Logs are deleted after 30 days by default.

= For hosts and agencies: configuration in wp-config.php =

Every setting can be fixed with a constant. Constants override the database and show read-only in the admin screen.

`
define( 'CODO_MAILER_FROM_EMAIL', 'noreply@example.co.uk' );
define( 'CODO_MAILER_FROM_NAME', 'Example Ltd' );

define( 'CODO_MAILER_PRIMARY_TYPE', 'ses' );
define( 'CODO_MAILER_PRIMARY_REGION', 'eu-west-2' );
define( 'CODO_MAILER_PRIMARY_ACCESS_KEY', 'AKIA...' );
define( 'CODO_MAILER_PRIMARY_SECRET_KEY', '...' );

define( 'CODO_MAILER_BACKUP_TYPE', 'smtp' );
define( 'CODO_MAILER_BACKUP_HOST', 'smtp.example.net' );
define( 'CODO_MAILER_BACKUP_USERNAME', '...' );
define( 'CODO_MAILER_BACKUP_PASSWORD', '...' );

define( 'CODO_MAILER_ALERT_EMAIL', 'ops@example.co.uk' );
`

Connection field names: SMTP `host`, `port`, `encryption` (tls, ssl, none), `auth`, `username`, `password`; SES `region`, `access_key`, `secret_key`; Postmark `server_token`, `message_stream`; Mailgun `domain`, `api_key`, `region` (eu, us); Brevo and SendGrid `api_key`.

If an SMTP password is a constant but the host, port, encryption or username are not, the first successful send locks the connection to that server. Changing any of them in the admin screen then stops the connection, so the password can't be redirected to another server. To move servers, set the new values as constants too.

To use your own encryption key instead of the site salts, define `CODO_MAILER_ENCRYPTION_KEY`. See "How do I set a strong encryption key?" in the FAQ. If you rotate your salts without one, re-enter saved API keys and passwords (secrets set as constants are unaffected).

= For developers =

* `codo_mailer_redaction_patterns` filter: add regular expressions for other secrets (e.g. magic-login tokens). They apply to the subject as well as the body.
* `codo_mailer_transport` filter: provide a transport for a custom connection type.
* `codo_mailer_alert_interval` filter: change the alert throttle (seconds).
* `codo_mailer_attachment_size_limit` filter: change the largest total attachment size an API connection will send (bytes; 10 MB for Postmark, 25 MB otherwise, 0 for no cap). Larger emails fail on that connection and go to the backup.
* `codo_mailer_smtp_allow_plaintext_auth` filter: return false to refuse SMTP logins over an unencrypted connection (other than to localhost).
* `codo_mailer_capability` filter: change who can manage the plugin.
* `codo_mailer_sent` action: fires after delivery with the message, connection and provider result.

Source, issues and tests: [github.com/Codo-Digital-Ltd/codo-mailer](https://github.com/Codo-Digital-Ltd/codo-mailer).

== Installation ==

1. Install from Plugins > Add New, or upload the `codo-mailer` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Settings > Codo Mailer, choose a provider and enter its credentials.
4. Use the "Send a test" tab to confirm delivery.

Until a primary connection is saved, WordPress keeps sending email the way it did before.

== Frequently Asked Questions ==

= Do I need an account with an email provider? =

Yes. Codo Mailer connects your site to a provider; it doesn't send email itself. Amazon SES, Postmark, Mailgun, Brevo and SendGrid all have free or low-cost tiers, or you can use your existing mailbox's SMTP server.

= Does it work with WooCommerce and form plugins? =

Yes. Anything that sends through `wp_mail()` goes through Codo Mailer.

= Why can't I resend a password-reset email? =

The reset link was redacted before logging, so the logged copy no longer contains it. Ask the user to request a new reset, which is also safer.

= What happens if both connections fail? =

The failure is logged with each provider's error, WordPress's `wp_mail_failed` action fires, and an alert goes to your alert email and/or webhook.

= Does it send any data to Codo Digital? =

No. It talks only to the email provider (and alert webhook) you configure.

= Is it multisite compatible? =

Yes. Each site has its own settings and log, managed by super admins only. Constants in `wp-config.php` apply to every site in the network.

= Can I send an email with only Bcc recipients? =

Over SMTP, Amazon SES and Mailgun, yes. Postmark, Brevo and SendGrid require at least one To address and will report an error.

= Will a failure slow my site down? =

Each provider call times out after 15 seconds. Failure alerts are sent at the end of the request, after the page has been delivered where the server supports it, so visitors don't wait for them.

= Why does the settings screen warn about SMTP without encryption? =

With Encryption set to None and Authenticate on, your SMTP username and password are sent to the server in plain text, where anyone on the network path can read them. Codo Mailer also turns off automatic STARTTLS in this mode, so this happens even if the server supports encryption. Choose TLS or SSL unless the server is a trusted relay on your own network. Connections to localhost are not warned about. Hosts can refuse plaintext logins outright with the `codo_mailer_smtp_allow_plaintext_auth` filter.

= How do I set a strong encryption key? =

Saved API keys and passwords are encrypted with a key derived from WordPress's `AUTH_KEY`, `AUTH_SALT`, `SECURE_AUTH_KEY` and `SECURE_AUTH_SALT`. If any of those is missing from wp-config.php, or still says "put your unique phrase here", WordPress stores it in the database, and a database backup alone is then enough to decrypt your secrets. The settings screen warns you when this is the case.

To fix it, add a dedicated key of at least 32 random bytes to wp-config.php. Generate one with `php -r 'echo base64_encode( random_bytes( 32 ) ), PHP_EOL;'` (or `wp eval 'echo base64_encode( random_bytes( 32 ) );'`) and add:

`define( 'CODO_MAILER_ENCRYPTION_KEY', 'paste-the-value-here' );`

Use a random value, not a memorable passphrase: the key is hashed without key stretching, so a short phrase is a weak key. Keys shorter than 32 characters are warned about.

Setting or changing the key makes existing saved secrets unreadable. To rotate it: set the new key, then re-enter each API key and password on the settings screen (secrets defined as constants in wp-config.php are unaffected). Keep the key out of version control and out of database backups, which is the point of keeping it in wp-config.php.

== External services ==

Codo Mailer delivers your site's email through an email provider that you choose and configure. It does not contact any service until you save a connection in Settings > Codo Mailer (or define one in `wp-config.php`), and it only ever contacts the providers you have configured. It never sends data to Codo Digital.

**When data is sent:** every time WordPress sends an email through `wp_mail()` (for example password resets, new-user notifications, WooCommerce orders and form notifications), when you use "Send a test", and when you resend an email from the log. The primary connection is used first; the backup connection is contacted only if the primary fails.

**What is sent:** the email itself: sender, recipients (To, Cc, Bcc), Reply-To, subject, body, attachments and any custom headers, plus the credentials you entered for that provider so it can authenticate the request (for Amazon SES, your access key ID and a signature made with your secret key; the secret key itself is never sent).

= Amazon SES (Amazon Web Services) =

Amazon Simple Email Service is an email sending service. If you choose it, each email is sent to the SES API endpoint for the region you select (`email.<region>.amazonaws.com`), signed with your access keys.

* Terms: https://aws.amazon.com/service-terms/
* Privacy: https://aws.amazon.com/privacy/

= Postmark (ActiveCampaign) =

Postmark is a transactional email service. If you choose it, each email is sent to `api.postmarkapp.com` with your server token.

* Terms: https://postmarkapp.com/terms-of-service
* Privacy: https://www.activecampaign.com/legal/privacy-policy

= Mailgun (Sinch Email) =

Mailgun is an email sending service. If you choose it, each email is sent to `api.eu.mailgun.net` (EU region) or `api.mailgun.net` (US region), depending on your setting, with your API key.

* Terms: https://www.mailgun.com/legal/terms/
* Privacy: https://www.mailgun.com/legal/privacy-policy/

= Brevo =

Brevo is an email and marketing platform with a transactional email API. If you choose it, each email is sent to `api.brevo.com` with your API key.

* Terms: https://www.brevo.com/legal/termsofuse/
* Privacy: https://www.brevo.com/legal/privacypolicy/

= SendGrid (Twilio) =

SendGrid is an email sending service. If you choose it, each email is sent to `api.sendgrid.com` with your API key.

* Terms: https://www.twilio.com/en-us/legal/tos
* Privacy: https://www.twilio.com/en-us/legal/privacy

= SMTP server =

If you choose SMTP, each email is sent to the SMTP server you enter (your mailbox provider, host or any other server), with the username and password you enter. Its terms and privacy policy are those of whoever runs that server.

= Failure alert webhook (optional) =

If you enter a webhook URL (for example a Slack incoming webhook), Codo Mailer posts a JSON alert to that URL when an email fails to send, at most once an hour by default. The alert contains the site name, site URL, the failed email's subject, the error message and the time. Nothing is posted if the field is left empty. The service's terms and privacy policy are those of whoever provides that URL; for Slack: https://slack.com/main-services-agreement and https://slack.com/trust/privacy/privacy-policy

== Changelog ==

= 1.0.1 =
* Security: a password set in wp-config.php is now locked to the SMTP server it last sent through. Changing that server's host, port, encryption or username in the admin screen stops the connection until they are set in wp-config.php too.
* Security: the alert webhook URL is now stored encrypted and never shown again after saving. Existing URLs are encrypted automatically.
* Security: a saved SMTP password is cleared when the server it belongs to is changed.
* Security: attachments given as stream URLs (phar://, ftp:// and so on) are ignored, and header names, content types, charsets and subjects are checked for line-break injection.
* Fix: an unrecognised connection type now fails and is logged, instead of falling back to PHP mail().
* Fix: an error thrown by another plugin's phpmailer_init hook no longer stops the backup connection from being tried.
* Tested with PHPMailer 7, as bundled with WordPress 6.9 and later.

= 1.0.0 =
* First release: SMTP, Amazon SES, Postmark, Mailgun, Brevo and SendGrid; backup connection; email log with redaction and resend; email and webhook alerts; wp-config.php constants; encrypted secrets.

== Upgrade Notice ==

= 1.0.1 =
Security hardening for stored credentials and message headers. Recommended for all sites.

= 1.0.0 =
First release.
