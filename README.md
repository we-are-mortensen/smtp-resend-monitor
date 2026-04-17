# SMTP Resend Monitor

WordPress mu-plugin that monitors SMTP failures and sends alerts via [Resend](https://resend.com) HTTP API.

## The Problem

When SMTP fails on a WordPress site, there is no way to be notified — because the notification mechanism (email) is itself broken. Sites can go months with broken SMTP until someone reports that forms aren't working.

## How It Works

1. **Real-time detection**: Hooks into `wp_mail_failed` to catch SMTP failures as they happen
2. **Proactive health-check**: Sends a test email every 24 hours via WP-Cron — if it fails, you get an alert
3. **Alert via HTTP**: Bypasses the broken SMTP entirely by sending alerts through Resend's HTTP API
4. **Cooldown**: Maximum 1 alert per 6 hours per site to prevent spam

Works with any SMTP setup: WP Mail SMTP plugin, roots/acorn-mail, or native WordPress.

## Requirements

- PHP >= 8.0
- WordPress
- A [Resend](https://resend.com) account with a verified domain

## Installation

```bash
composer require mortensen/smtp-resend-monitor
```

The package type is `wordpress-muplugin`, so Composer places it automatically in `mu-plugins/`.

## Configuration

Add these variables to your `.env` file (Bedrock) or define them in `wp-config.php`:

| Variable | Required | Description |
|----------|----------|-------------|
| `SMTP_MONITOR_RESEND_API_KEY` | **Yes** | Your Resend API key |
| `SMTP_MONITOR_ALERT_TO` | **Yes** | Alert recipient email |
| `SMTP_MONITOR_ALERT_FROM` | **Yes** | Alert sender email (must be from a verified Resend domain) |

### Bedrock (.env)

```env
SMTP_MONITOR_RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
SMTP_MONITOR_ALERT_TO=dev@mortensen.cat
SMTP_MONITOR_ALERT_FROM=no-reply@mortensen.cat
```

### wp-config.php

```php
define('SMTP_MONITOR_RESEND_API_KEY', 're_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SMTP_MONITOR_ALERT_TO', 'dev@mortensen.cat');
define('SMTP_MONITOR_ALERT_FROM', 'no-reply@mortensen.cat');
```

## How It Detects Failures

### Real-time (`wp_mail_failed`)

Every time `wp_mail()` fails, WordPress fires the `wp_mail_failed` action with a `WP_Error` object. This plugin hooks into it and sends an alert via Resend's HTTP API.

### Health-check (WP-Cron)

Every 24 hours, the plugin sends a test email via `wp_mail()` to the site's admin email. If the send fails, it triggers an alert. This catches silent failures where no user activity triggers `wp_mail()`.

## Alert Email

You receive an email with:

- **Site name** and **URL**
- **Error message** from WordPress
- **Detection type**: "Real-time" or "Health-check"
- **Timestamp**

## Cooldown

To prevent alert spam (e.g., a form being submitted repeatedly while SMTP is broken), only 1 alert is sent per 6 hours per site.

## Behavior When Not Configured

If `SMTP_MONITOR_RESEND_API_KEY` is not set, the plugin does nothing — no errors, no warnings, no performance impact.

## License

MIT
