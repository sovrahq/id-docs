<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * English strings for local_sovracred.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apikey'] = 'API key';
$string['apikey_desc'] = 'Workspace API key (<code>sovra_sk_…</code>), from Settings → API keys in the Sovra console. Stored encrypted.';
$string['apiurl'] = 'API URL';
$string['apiurl_desc'] = 'Test: <code>https://test-api-sovra.flagonsa.com</code>. Production: <code>https://api.sovra.io</code>. The host must be allowed in Site administration → Security → HTTP security.';
$string['check_api'] = 'API URL, API key and schema configured.';
$string['check_lastrejected'] = 'Last webhook rejected for an invalid signature: {$a}. Check that the webhook secret matches the Sovra console.';
$string['check_webhook'] = 'Webhook secret configured. Webhook URL to paste in the Sovra console: <code>{$a}</code> (optional: the scheduled task confirms issuances without it).';
$string['completedon'] = 'Completed on {$a}';
$string['completedon_short'] = 'Completed';
$string['courseids'] = 'Course IDs';
$string['courseids_desc'] = 'Comma-separated course IDs that issue a credential, when the scope is "Only listed courses".';
$string['coursescope'] = 'Courses that issue';
$string['coursescope_all'] = 'All courses';
$string['coursescope_desc'] = 'Which completed courses give the right to a credential.';
$string['coursescope_list'] = 'Only listed courses';
$string['documentfield'] = 'Document number field';
$string['documentfield_desc'] = 'User profile field that holds the student\'s document number (claim <code>documento</code>). <strong>It must not be editable by the student</strong>, and neither should first and last name: the institution signs these values. Lock them in the authentication plugin settings ("Lock user fields") or make the profile field not editable.';
$string['error_api'] = 'We could not prepare your credential right now. Please try again in a few minutes.';
$string['error_missingdata'] = 'Your credential is almost ready, but some information is missing. The platform administrators have been informed.';
$string['lasterror'] = 'Last error';
$string['messageprovider:credentialavailable'] = 'A course credential is ready to receive';
$string['mycredentials'] = 'My credentials';
$string['mycredentials_intro'] = 'Each completed course gives you a verifiable credential that you keep in your wallet and can show to anyone.';
$string['nocredentials'] = 'You have no credentials yet. When you complete a course that issues one, it will appear here.';
$string['notified'] = 'Notification sent to the student.';
$string['notify'] = 'Resend notification';
$string['notifybody'] = 'You completed {$a->course}. Your credential is ready: open {$a->url} and tap "Receive in my wallet".';
$string['notifybodyhtml'] = '<p>You completed <strong>{$a->course}</strong>. Your credential is ready.</p><p><a href="{$a->url}">Go to My credentials</a> and tap "Receive in my wallet".</p>';
$string['notifysmall'] = 'Your credential for {$a->course} is ready to receive.';
$string['notifysubject'] = 'Your credential for {$a->course} is ready';
$string['offerexpires'] = 'This code is valid until {$a} and can be used only once. If it expires, request it again.';
$string['offerexpires_short'] = 'Offer expires';
$string['openwallet'] = 'Open my wallet';
$string['openwallet_help'] = 'If you are on your phone, this button opens the wallet app directly.';
$string['pluginname'] = 'Sovra ID course credentials';
$string['privacy:metadata:issuance'] = 'The right of a student to the credential of a completed course, and its offer in Sovra.';
$string['privacy:metadata:issuance:courseid'] = 'The completed course.';
$string['privacy:metadata:issuance:credentialid'] = 'The credential identifier in Sovra.';
$string['privacy:metadata:issuance:holderdid'] = 'The decentralized identifier of the wallet that received the credential.';
$string['privacy:metadata:issuance:lasterror'] = 'The last error when preparing the credential (for example, a missing field).';
$string['privacy:metadata:issuance:offerexpires'] = 'When the open offer expires.';
$string['privacy:metadata:issuance:offeruri'] = 'The open offer link. Whoever has it can receive the credential until it expires or is used.';
$string['privacy:metadata:issuance:status'] = 'Whether the credential is available, offered, issued, suspended or revoked.';
$string['privacy:metadata:issuance:timecompleted'] = 'When the course was completed.';
$string['privacy:metadata:issuance:timeissued'] = 'When the credential reached the wallet.';
$string['privacy:metadata:issuance:userid'] = 'The student.';
$string['privacy:metadata:issuance:verificationcode'] = 'The verification code carried by the credential.';
$string['privacy:metadata:sovra'] = 'When the student asks to receive a credential, its data is sent to Sovra ID, which signs it and delivers it to the student\'s wallet.';
$string['privacy:metadata:sovra:codigo'] = 'The verification code of the credential.';
$string['privacy:metadata:sovra:curso'] = 'The course name.';
$string['privacy:metadata:sovra:documento'] = 'The student\'s document number.';
$string['privacy:metadata:sovra:entidad'] = 'The name of the issuing institution.';
$string['privacy:metadata:sovra:fecha'] = 'The completion date.';
$string['privacy:metadata:sovra:holdername'] = 'The student\'s full name.';
$string['privacy:metadata:sovra:horas'] = 'The course hours.';
$string['privacy:metadata:sovra:nivel'] = 'The course level, if any.';
$string['privacy:metadata:sovra:programa'] = 'The programme (course category).';
$string['qr_alt'] = 'QR code to receive the credential';
$string['receive'] = 'Receive in my wallet';
$string['refresh'] = 'Check status';
$string['refreshed'] = 'Status checked: {$a}.';
$string['report'] = 'Course credentials (Sovra ID)';
$string['scanqr'] = 'Scan this code with your wallet:';
$string['schemaid'] = 'Schema';
$string['schemaid_desc'] = 'The schema slug (<code>schema_id</code>) in the Sovra console, not its UUID or display name.';
$string['settings_connection'] = 'Connection with Sovra ID';
$string['settings_connection_desc'] = 'Webhook URL to configure in the Sovra console: <code>{$a->webhookurl}</code>. Status and connection test: <a href="{$a->reporturl}">report</a>.';
$string['settings_issuing'] = 'What issues a credential';
$string['sovracred:viewreport'] = 'View the course credentials report';
$string['status_available'] = 'Available';
$string['status_issued'] = 'Issued';
$string['status_issued_student'] = 'It is in your wallet since {$a}.';
$string['status_issued_student_nodate'] = 'It is in your wallet.';
$string['status_offered'] = 'Offered';
$string['status_revoked'] = 'Revoked';
$string['status_revoked_student'] = 'This credential was revoked.';
$string['status_suspended'] = 'Suspended';
$string['status_suspended_student'] = 'This credential is suspended.';
$string['taskreconcile'] = 'Confirm Sovra ID credential offers';
$string['test_created'] = 'The connection works, but the schema has no required fields, so the test created an offer. It will expire unused in 24 hours.';
$string['test_hostblocked'] = 'The API host is blocked by Site administration → Security → HTTP security.';
$string['test_invalid_api_key'] = 'Sovra rejected the API key. Generate a new one in the console and save it again.';
$string['test_network'] = 'Moodle could not reach the API. Check the URL, DNS and outbound firewall.';
$string['test_notconfigured'] = 'API URL, API key or schema missing.';
$string['test_ok'] = 'Connection OK: the API key is valid and the schema exists. No offer was created.';
$string['test_other'] = 'Unexpected answer from the API: {$a}';
$string['test_organization_inactive'] = 'The organization is deactivated in Sovra. Until it is reactivated, the API refuses everything.';
$string['test_schema_not_found'] = 'The schema does not exist in this workspace. Use its slug, not its UUID.';
$string['test_schema_registration_failed'] = 'The schema\'s credential type could not be registered on-chain, so nothing can be issued from it. Check it in the Sovra console.';
$string['test_schema_registration_pending'] = 'The schema is still being registered on-chain; nothing can be issued until that finishes. Try again in a few seconds.';
$string['test_too_many_requests'] = 'Sovra is rate limiting the requests: the cap is 120 offers per minute per site. Try again in a minute.';
$string['test_workspace_inactive'] = 'The workspace is deactivated in Sovra. Until it is reactivated, the API refuses everything.';
$string['test_workspace_not_provisioned'] = 'The workspace is still being provisioned. Try again in a few minutes.';
$string['test_wrong_schema_kind'] = 'That schema is of kind document and cannot be offered to a wallet. Use a credential schema slug.';
$string['testconnection'] = 'Test connection';
$string['verificationcode'] = 'Verification code: {$a}';
$string['webhooksecret'] = 'Webhook secret';
$string['webhooksecret_desc'] = 'From Settings → Workspace → Webhook secret in the Sovra console (shown only once). Stored encrypted. Optional: without it, issuances are confirmed by the scheduled task.';
