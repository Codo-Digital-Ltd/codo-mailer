=== Codo Mailer ===
Contributors: cododigital
Tags: smtp, email, amazon ses, email log, deliverability
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure-by-default email delivery: SMTP, Amazon SES, Postmark, Mailgun, Brevo and SendGrid, with a log, resend, backup connection and alerts. Free.

== Description ==

Codo Mailer makes sure the email your WordPress site sends (password resets, order confirmations, form notifications) actually arrives. It routes every `wp_mail()` call through a proper email provider instead of your server's unauthenticated PHP mail.

It is built by [Codo Digital](https://codo.digital), a UK web development and managed hosting company, and is the mailer we use on our own clients' sites. Everything is free. There is no Pro version, no upsell, no account to create and no tracking.

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
* Works with WooCommerce, contact form plugins and anything else that uses `wp_mail()`. Core hooks such as `wp_mail_from`, `wp_mail_content_type`, `wp_mail_succeeded` and `wp_mail_failed` keep working. `phpmailer_init` (used by DKIM-signing plugins) runs for SMTP, Amazon SES and Mailgun, which send full MIME; Postmark, Brevo and SendGrid build the message on their side, so it does not run for them (see the FAQ).

= Secure by default =

Email logs hold password-reset links, so a leaky log is a site takeover waiting to happen. Codo Mailer is designed around that:

* Password-reset, set-password and email-confirmation links, one-time tokens, verification codes and PINs, and plaintext passwords are **redacted** from the body and subject before an email is logged or included in an alert, and those emails cannot be resent from the log. Redaction is best-effort, so as a fail-safe, if a secret still shows up once the body is decoded, the whole body is left out of the log.
* The log is only ever shown on the admin screen to administrators (`manage_options`; on multisite, super admins only, because whoever controls mail can read any user's reset email). There are **no REST or AJAX endpoints** for it.
* API keys and passwords are **encrypted at rest** (libsodium), so a database dump alone does not reveal them.
* Or keep credentials out of the database entirely with **`wp-config.php` constants**.
* Logs are deleted after 30 days by default.
* The log works with WordPress's **personal data export and erasure** tools, and suggests text for your privacy policy.

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

Connection field names: SMTP `host`, `port`, `encryption` (tls, ssl, none), `auth`, `username`, `password`; SES `region`, `access_key`, `secret_key`, `session_token` (optional, for temporary credentials); Postmark `server_token`, `message_stream`; Mailgun `domain`, `api_key`, `region` (eu, us); Brevo and SendGrid `api_key`.

If an SMTP password is a constant but the host, port, encryption or username are not, the first successful send locks the connection to that server. Changing any of them in the admin screen then stops the connection, so the password can't be redirected to another server. To move servers, set the new values as constants too.

To use your own encryption key instead of the site salts, define `CODO_MAILER_ENCRYPTION_KEY`. See "How do I set a strong encryption key?" in the FAQ. If you rotate your salts without one, re-enter saved API keys and passwords (secrets set as constants are unaffected).

= For developers =

* `codo_mailer_redaction_patterns` filter: add regular expressions for other secrets (e.g. magic-login tokens). They apply to the subject as well as the body.
* `codo_mailer_transport` filter: provide a transport for a custom connection type.
* `codo_mailer_alert_interval` filter: change the alert throttle (seconds).
* `codo_mailer_attachment_size_limit` filter: change the largest total attachment size an API connection will send (bytes; 10 MB for Postmark, 25 MB otherwise, 0 for no cap). Larger emails fail on that connection and go to the backup.
* `codo_mailer_smtp_allow_plaintext_auth` filter: return false to refuse SMTP logins over an unencrypted connection (other than to localhost).
* `codo_mailer_capability` filter: change who can manage the plugin. Read "Can I let editors or shop managers manage email?" in the FAQ first.
* `codo_mailer_message` filter: change an email before it is sent, for every provider. It receives the `CodoDigital\Mailer\Mail\Message` and the source (`wp_mail`, `test` or `resend`) and must return a Message, e.g. `$message->with( array( 'bcc' => array( array( 'email' => 'archive@example.com', 'name' => '' ) ) ) )`. It runs once per email, before the primary connection; the backup, the log and `wp_mail_succeeded` see the result. Use it instead of `phpmailer_init`, which Postmark, Brevo and SendGrid never fire.
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

= Why doesn't my DKIM, Bcc archive or other phpmailer_init plugin work with Postmark, Brevo or SendGrid? =

Some plugins change outgoing email by hooking WordPress's `phpmailer_init` action, which hands them the PHPMailer object just before sending: to add a DKIM signature, Bcc archive copies, embedded images or extra headers. Codo Mailer fires it for SMTP, Amazon SES and Mailgun, which send a full MIME message built with PHPMailer. Postmark, Brevo and SendGrid take the email as JSON through their APIs, so there is no PHPMailer object and `phpmailer_init` does not run for them; those plugins' changes are skipped. The settings screen points this out when you pick one of these providers, and warns if a plugin on your site uses the hook.

What to do instead:

* DKIM: these providers sign email themselves once you verify your domain with them, so a DKIM plugin isn't needed.
* Bcc copies, extra headers and other changes: developers can use the `codo_mailer_message` filter (see "For developers"), which runs for every provider.
* Otherwise, choose SMTP, Amazon SES or Mailgun, where `phpmailer_init` keeps working.

= Why can't I resend a password-reset email? =

The reset link was redacted before logging, so the logged copy no longer contains it. Ask the user to request a new reset, which is also safer.

= What happens if both connections fail? =

The failure is logged with each provider's error, WordPress's `wp_mail_failed` action fires, and an alert goes to your alert email and/or webhook.

= Does it send any data to Codo Digital? =

No. It talks only to the email provider (and alert webhook) you configure.

= Does the email log work with WordPress's privacy tools (GDPR export and erasure)? =

Yes. Codo Mailer adds an exporter and an eraser to Tools > Export Personal Data and Tools > Erase Personal Data. Both find log entries where the person's address is a To, Cc, Bcc or Reply-To address (exact matches only, ignoring case).

* **Export** includes the date, status, sender, subject, message and attachment names of each email, and how the person's address appeared on it. Other recipients' addresses are left out. Password-reset links, one-time codes and passwords are redacted from the export even if redaction was turned off when the email was logged.
* **Erasure** deletes each email that was sent only to that person. An email that was also sent to other people is kept, because it is part of their record too: the person's address is removed from it, and the erasure report says how many such emails were kept. Its subject and body are not changed. If they mention the person, you can empty the whole log with "Delete all log entries" on the Email log tab.

Settings > Privacy > Policy Guide includes suggested text about the email log and how long it is kept.

= How long are emails kept in the log? =

For the number of days set in "Keep logs for (days)", 30 by default. Old entries are deleted by a daily WP-Cron task, which WordPress runs when the site gets visits. If your site sets `DISABLE_WP_CRON`, make sure a system cron job calls `wp-cron.php` (or runs `wp cron event run --due-now`), otherwise entries are kept until it does. You can also empty the log at any time with "Delete all log entries" on the Email log tab.

= Is it multisite compatible? =

Yes. Each site has its own settings and log, managed by super admins only. Constants in `wp-config.php` apply to every site in the network.

= Can I use temporary AWS credentials (an IAM role or STS) with Amazon SES? =

Yes. Temporary credentials come with a session token, which Codo Mailer sends as `X-Amz-Security-Token` and includes in the request signature. You can supply them in three ways:

* Leave the access key ID blank on the settings screen (and don't define it in wp-config.php). Codo Mailer then reads the standard `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and `AWS_SESSION_TOKEN` environment variables, as set on AWS Lambda and by many container platforms.
* Define `CODO_MAILER_PRIMARY_SESSION_TOKEN` (or `CODO_MAILER_BACKUP_SESSION_TOKEN`) in wp-config.php alongside the access key and secret, for example from values your deployment refreshes.
* Enter the session token on the settings screen. Temporary credentials expire, typically within hours, so this is only practical for testing. Saving a different access key ID removes a saved session token, and the "Remove" box next to it clears it.

Codo Mailer does not fetch credentials from the EC2 instance metadata service or the ECS container endpoint itself; on those platforms, export the role's credentials to the environment variables above.

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

= Can I let editors or shop managers manage email? =

Yes, with the `codo_mailer_capability` filter, for example `add_filter( 'codo_mailer_capability', fn() => 'edit_pages' );`. The default is `manage_options`, or `manage_network_options` on multisite. Only give it to people you would trust with every account on the site. Anyone who can manage Codo Mailer can change the connection, so they can route all of the site's email, including password resets, through a server they control.

Even with the capability lowered, two things stay with administrators: reading message bodies in the email log, and turning **Redact secrets** off. Bodies can contain working password-reset links, and redaction is what keeps them out of the log. Other users see the log's recipients, subjects and errors, and can still resend emails.

== External services ==

Codo Mailer delivers your site's email through an email provider that you choose and configure. It does not contact any service until you save a connection in Settings > Codo Mailer (or define one in `wp-config.php`), and it only ever contacts the providers you have configured. It never sends data to Codo Digital.

**When data is sent:** every time WordPress sends an email through `wp_mail()` (for example password resets, new-user notifications, WooCommerce orders and form notifications), when you use "Send a test", and when you resend an email from the log. The primary connection is used first; the backup connection is contacted only if the primary fails.

**What is sent:** the email itself: sender, recipients (To, Cc, Bcc), Reply-To, subject, body, attachments and any custom headers, plus the credentials you entered for that provider so it can authenticate the request (for Amazon SES, your access key ID, the session token if you use temporary credentials, and a signature made with your secret key; the secret key itself is never sent).

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

= 1.1.0 =
* New: personal data export and erasure for the email log, under Tools > Export Personal Data and Tools > Erase Personal Data. Exports leave out other recipients and redact secrets. Erasure deletes emails sent only to that person and removes their address from emails that were also sent to others. Suggested privacy policy text is added to the Policy Guide.
* New: Amazon SES supports temporary AWS credentials (IAM roles, STS). Set a session token on the settings screen or with `CODO_MAILER_PRIMARY_SESSION_TOKEN`, or leave the access key ID blank to use the `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and `AWS_SESSION_TOKEN` environment variables.
* New: `codo_mailer_message` filter to change an email before it is sent, for every provider.
* Docs: the settings screen and FAQ explain that `phpmailer_init` does not run for Postmark, Brevo and SendGrid, and warn when a plugin on the site relies on it. The FAQ also explains that log retention depends on WP-Cron.
* Improvement: Amazon SES request signing now encodes and normalises the request path as AWS specifies for services other than S3, checked against the AWS Signature Version 4 test suite. Today's SES requests are signed the same as before.

= 1.0.2 =
* Security: Resend no longer attaches a file that has changed since the email was sent, or is missing. The log says which attachments were left out.
* Security: redaction now also catches one-time codes and PINs, passwords written without a colon, and more login-link parameters, and it covers the subject line in the log and in failure alerts.
* Security: when access is opened to other roles with the `codo_mailer_capability` filter, only administrators can read logged message bodies or turn redaction off.
* Security: the settings screen warns when the encryption key could be recovered from the database, or is too short. The FAQ explains how to set a strong key.
* Security: the settings screen warns when SMTP sends its password without encryption. Hosts can refuse this with the new `codo_mailer_smtp_allow_plaintext_auth` filter.
* Fix: attachments too large for an API connection (10 MB for Postmark, 25 MB for the others, or more than PHP's memory allows) now fail cleanly and go to the backup connection instead of stopping PHP. The limit can be changed with the new `codo_mailer_attachment_size_limit` filter.

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

= 1.1.0 =
Personal data export and erasure for the email log, temporary AWS credentials for Amazon SES, and a new codo_mailer_message filter.

= 1.0.2 =
Safer resend, wider redaction of codes and passwords, and warnings for weak encryption keys and unencrypted SMTP logins. Recommended for all sites.

= 1.0.1 =
Security hardening for stored credentials and message headers. Recommended for all sites.

= 1.0.0 =
First release.
