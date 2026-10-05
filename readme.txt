=== Codo Mailer ===
Contributors: cododigital
Tags: smtp, email, amazon ses, email log, deliverability
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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
* Works with WooCommerce, contact form plugins and anything else that uses `wp_mail()`. Core hooks such as `wp_mail_from`, `wp_mail_failed` and `phpmailer_init` keep working.

= Secure by default =

Email logs hold password-reset links, so a leaky log is a site takeover waiting to happen. Codo Mailer is designed around that:

* Password-reset and one-time login links are **redacted** before an email is logged, and those emails cannot be resent from the log.
* The log is only ever shown on the admin screen to users with `manage_options`. There are **no REST or AJAX endpoints** for it.
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

To use your own encryption key instead of the site salts, define `CODO_MAILER_ENCRYPTION_KEY`.

= For developers =

* `codo_mailer_redaction_patterns` filter: add regular expressions for other secret links (e.g. magic-login tokens).
* `codo_mailer_transport` filter: provide a transport for a custom connection type.
* `codo_mailer_alert_interval` filter: change the alert throttle (seconds).
* `codo_mailer_sent` action: fires after delivery with the message, connection and provider result.

Source, issues and tests: [github.com/cododigital/codo-mailer](https://github.com/cododigital/codo-mailer).

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

Each site has its own settings and log. Network-wide constants in `wp-config.php` apply to every site.

== External services ==

This plugin sends your site's outgoing email, including recipients, subject, body and attachments, to the email provider you choose in its settings. Nothing is sent until you configure a provider. Only the provider you select is contacted:

* Amazon SES (Amazon Web Services): https://aws.amazon.com/service-terms/ and https://aws.amazon.com/privacy/
* Postmark (ActiveCampaign): https://postmarkapp.com/terms-of-service and https://postmarkapp.com/privacy-policy
* Mailgun (Sinch): https://www.mailgun.com/legal/terms/ and https://www.mailgun.com/legal/privacy-policy/
* Brevo: https://www.brevo.com/legal/termsofuse/ and https://www.brevo.com/legal/privacypolicy/
* SendGrid (Twilio): https://www.twilio.com/en-us/legal/tos and https://www.twilio.com/en-us/legal/privacy
* SMTP: the server you enter.

If you set an alert webhook, failure alerts (site name, URL, failed email subject and error) are posted to that URL.

== Screenshots ==

1. Settings: choose primary and backup providers.
2. Email log with sent and failed messages.
3. Log entry with resend.

== Changelog ==

= 1.0.0 =
* First release: SMTP, Amazon SES, Postmark, Mailgun, Brevo and SendGrid; backup connection; email log with redaction and resend; email and webhook alerts; wp-config.php constants; encrypted secrets.

== Upgrade Notice ==

= 1.0.0 =
First release.
