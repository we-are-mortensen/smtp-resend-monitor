<?php

/**
 * Plugin Name: SMTP Resend Monitor
 * Description: Monitors SMTP failures and sends alerts via Resend HTTP API.
 * Version: 1.0.0
 * Author: Mortensen
 * Author URI: https://mortensen.cat
 * License: MIT
 */

namespace SmtpResendMonitor;

defined('ABSPATH') || exit;

const CRON_HOOK = 'smtp_resend_monitor_health_check';
const COOLDOWN_TRANSIENT = 'smtp_resend_monitor_cooldown';
const COOLDOWN_SECONDS = 6 * HOUR_IN_SECONDS;
const HEALTH_CHECK_INTERVAL = 'daily';
const RESEND_API_URL = 'https://api.resend.com/emails';


/**
 * Get configuration value from constants or .env.
 */
function get_config(string $key, string $default = ''): string
{
    if (defined($key)) {
        return constant($key);
    }

    if (function_exists('env')) {
        $value = env($key);
        if ($value !== null && $value !== '') {
            return (string) $value;
        }
    }

    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    return $default;
}

/**
 * Check if the plugin is properly configured.
 */
function is_configured(): bool
{
    return ! empty(get_config('SMTP_MONITOR_RESEND_API_KEY'))
        && ! empty(get_config('SMTP_MONITOR_ALERT_TO'))
        && ! empty(get_config('SMTP_MONITOR_ALERT_FROM'));
}

// Exit early if not configured — show admin notice with missing variables.
if (! is_configured()) {
    add_action('admin_notices', __NAMESPACE__ . '\\show_missing_config_notice');
    return;
}

/**
 * Show admin notice listing missing configuration variables.
 */
function show_missing_config_notice(): void
{
    $missing = [];

    if (empty(get_config('SMTP_MONITOR_RESEND_API_KEY'))) {
        $missing[] = 'SMTP_MONITOR_RESEND_API_KEY';
    }
    if (empty(get_config('SMTP_MONITOR_ALERT_TO'))) {
        $missing[] = 'SMTP_MONITOR_ALERT_TO';
    }
    if (empty(get_config('SMTP_MONITOR_ALERT_FROM'))) {
        $missing[] = 'SMTP_MONITOR_ALERT_FROM';
    }

    $vars = implode('</code>, <code>', $missing);

    printf(
        '<div class="notice notice-warning"><p><strong>SMTP Resend Monitor:</strong> Missing required configuration: <code>%s</code>. Add them to your <code>.env</code> file or <code>wp-config.php</code>.</p></div>',
        $vars
    );
}

/**
 * Handle wp_mail failures in real-time.
 */
function handle_mail_failure(\WP_Error $error): void
{
    // During health-check, the error is handled by run_health_check().
    if (doing_health_check()) {
        return;
    }

    $message = $error->get_error_message();
    send_alert($message, 'Real-time');
}

/**
 * Run the scheduled health-check.
 * Sends a test email via wp_mail(). If it fails, sends an alert.
 */
function run_health_check(): void
{
    set_doing_health_check(true);

    $site_name = get_bloginfo('name');
    $to = get_option('admin_email');
    $subject = "[SMTP Monitor] Health Check — {$site_name}";
    $body = "This is an automated SMTP health-check from SMTP Resend Monitor. If you receive this email, SMTP is working correctly.";

    $result = wp_mail($to, $subject, $body);

    if ($result === false) {
        send_alert('Health-check failed: wp_mail() returned false.', 'Health-check');
    }

    set_doing_health_check(false);
}

/**
 * Track whether we are currently running a health-check.
 * Prevents the wp_mail_failed hook from double-alerting during health-checks.
 */
function doing_health_check(): bool
{
    global $smtp_resend_monitor_doing_health_check;
    return ! empty($smtp_resend_monitor_doing_health_check);
}

function set_doing_health_check(bool $value): void
{
    global $smtp_resend_monitor_doing_health_check;
    $smtp_resend_monitor_doing_health_check = $value;
}

/**
 * Send an alert email via Resend HTTP API.
 */
function send_alert(string $error_message, string $type): void
{
    if (is_on_cooldown()) {
        return;
    }

    $api_key = get_config('SMTP_MONITOR_RESEND_API_KEY');
    $to = get_config('SMTP_MONITOR_ALERT_TO');
    $from_email = get_config('SMTP_MONITOR_ALERT_FROM');
    $from = "SMTP Resend Monitor <{$from_email}>";

    $site_name = get_bloginfo('name');
    $site_url = home_url();
    $timestamp = wp_date('Y-m-d H:i:s');

    $subject = "[SMTP Monitor] Fallo en {$site_name}";

    $html = build_alert_html($site_name, $site_url, $error_message, $type, $timestamp);

    $response = wp_remote_post(RESEND_API_URL, [
        'headers' => [
            'Authorization' => "Bearer {$api_key}",
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
        ]),
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        error_log("[SMTP Resend Monitor] Failed to send alert via Resend: " . $response->get_error_message());
        return;
    }

    $code = wp_remote_retrieve_response_code($response);

    if ($code < 200 || $code >= 300) {
        $body = wp_remote_retrieve_body($response);
        error_log("[SMTP Resend Monitor] Resend API returned HTTP {$code}: {$body}");
        return;
    }

    set_cooldown();
}

/**
 * Build the alert email HTML.
 */
function build_alert_html(string $site_name, string $site_url, string $error_message, string $type, string $timestamp): string
{
    $error_message_escaped = esc_html($error_message);
    $site_name_escaped = esc_html($site_name);
    $site_url_escaped = esc_url($site_url);
    $type_escaped = esc_html($type);

    return <<<HTML
    <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background-color: #dc2626; color: white; padding: 16px 20px; border-radius: 8px 8px 0 0;">
            <h1 style="margin: 0; font-size: 18px;">SMTP Failure Detected</h1>
        </div>
        <div style="border: 1px solid #e5e7eb; border-top: none; padding: 20px; border-radius: 0 0 8px 8px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; color: #6b7280; width: 120px;">Site</td>
                    <td style="padding: 8px 0; font-weight: 600;">{$site_name_escaped}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">URL</td>
                    <td style="padding: 8px 0;"><a href="{$site_url_escaped}" style="color: #2563eb;">{$site_url_escaped}</a></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">Detection</td>
                    <td style="padding: 8px 0;">{$type_escaped}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #6b7280;">Timestamp</td>
                    <td style="padding: 8px 0;">{$timestamp}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #6b7280; vertical-align: top;">Error</td>
                    <td style="padding: 8px 0;">
                        <code style="background: #fef2f2; color: #dc2626; padding: 8px 12px; border-radius: 4px; display: block; word-break: break-word;">{$error_message_escaped}</code>
                    </td>
                </tr>
            </table>
        </div>
        <p style="color: #9ca3af; font-size: 12px; margin-top: 16px; text-align: center;">
            SMTP Resend Monitor v1.0.0 — Next alert available in 6 hours.
        </p>
    </div>
    HTML;
}

/**
 * Check if alert cooldown is active.
 */
function is_on_cooldown(): bool
{
    return (bool) get_transient(COOLDOWN_TRANSIENT);
}

/**
 * Set the alert cooldown.
 */
function set_cooldown(): void
{
    set_transient(COOLDOWN_TRANSIENT, true, COOLDOWN_SECONDS);
}

// --- Hooks ---

add_action('wp_mail_failed', __NAMESPACE__ . '\\handle_mail_failure');
add_action(CRON_HOOK, __NAMESPACE__ . '\\run_health_check');

// Schedule health-check cron if not already scheduled.
if (! wp_next_scheduled(CRON_HOOK)) {
    wp_schedule_event(time(), HEALTH_CHECK_INTERVAL, CRON_HOOK);
}
