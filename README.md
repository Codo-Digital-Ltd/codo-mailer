# Codo Mailer

[![CI](https://github.com/cododigital/codo-mailer/actions/workflows/ci.yml/badge.svg)](https://github.com/cododigital/codo-mailer/actions/workflows/ci.yml)
![Coverage](https://img.shields.io/badge/coverage-%E2%89%A595%25-brightgreen)
![PHP](https://img.shields.io/badge/PHP-7.4%E2%80%938.4-777bb4)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b)

Secure-by-default email delivery for WordPress: SMTP, Amazon SES, Postmark, Mailgun, Brevo and SendGrid, with an email log, resend, backup connection and failure alerts. Free, with no Pro tier, upsells or telemetry.

Built and maintained by [Codo Digital](https://cododigital.co.uk). User-facing documentation is in [`readme.txt`](readme.txt) (the WordPress.org listing).

## How it works

```
wp_mail()
  └─ pre_wp_mail filter ─► Dispatcher
                              ├─ MessageFactory   parses wp_mail args exactly as core does
                              ├─ primary Transport ─┐
                              ├─ backup Transport  ─┴─ SMTP (PHPMailer) | SES v2 (SigV4) | Postmark | Mailgun | Brevo | SendGrid
                              ├─ LogRepository     redacted via Redactor
                              └─ AlertNotifier     email + webhook, throttled, recursion-safe
```

- Nothing changes until a primary connection is saved; until then `pre_wp_mail` returns `null` and core sends as usual.
- Core hooks keep working: `wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type`, `wp_mail_charset`, `phpmailer_init` (SMTP), `wp_mail_succeeded`, `wp_mail_failed`.
- SES and Mailgun receive raw MIME built by WordPress's bundled PHPMailer, with Bcc passed only as envelope recipients.

## Naming and collisions

Everything is namespaced or prefixed so it cannot clash with core or other plugins:

| Kind | Name |
| --- | --- |
| PHP namespace | `CodoDigital\Mailer\` (PSR-4, own autoloader, no Composer at runtime) |
| Constants | `CODO_MAILER_*` |
| Options / transients | `codo_mailer_settings`, `codo_mailer_db_version`, `codo_mailer_alert_lock`, `codo_mailer_notice_{user}` |
| Database table | `{prefix}codo_mailer_log` |
| Hooks we add | `codo_mailer_*` |
| Admin actions / page | `codo_mailer_*`, `codo-mailer` |
| Text domain / slug | `codo-mailer` |

## Development

```bash
composer install
composer lint                 # WordPress Coding Standards + PHPCompatibility (7.4+)
composer test                 # PHPUnit
composer coverage             # needs pcov or Xdebug
php bin/coverage-gate.php build/clover.xml 95
```

Tests are unit tests with [Brain Monkey](https://giacomogaliano.github.io/brain-monkey/) standing in for WordPress, and cover the failure paths as well as the happy path: provider HTTP errors, network errors, bad credentials, malformed responses, unreadable attachments, invalid UTF-8, header injection, tampered ciphertext, missing nonces and capabilities, alert recursion, and redaction.

## Releasing

1. In a pull request, bump the version in `codo-mailer.php` (header and `CODO_MAILER_VERSION`) and `readme.txt` (`Stable tag` and a `= x.y.z =` changelog entry). CI fails if they disagree.
2. Merge to `main`.

The [Release workflow](.github/workflows/release.yml) then runs every check, deploys the release to the WordPress.org SVN repository, tags `vX.Y.Z` and publishes a GitHub release with the zip. WordPress.org offers the update to every site running the plugin. Pushes that don't change the version only sync `readme.txt` and `.wordpress-org/` assets.

Repository secrets required: `SVN_USERNAME`, `SVN_PASSWORD`. Banner and icon images for the directory go in `.wordpress-org/`.

## Security

See [SECURITY.md](SECURITY.md).

## Licence

GPL-2.0-or-later.
