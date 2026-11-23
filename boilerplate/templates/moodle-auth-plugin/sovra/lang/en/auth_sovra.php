<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Strings for auth_sovra (English).
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allowadmins'] = 'Allow site administrators';
$string['allowadmins_desc'] = 'When off (recommended), site administrator accounts cannot log in with the SuperApp: they keep logging in with their password.';
$string['allowedauths'] = 'Accepted authentication methods';
$string['allowedauths_desc'] = 'Only accounts using one of these methods can log in with the SuperApp. Accounts with "No login" (nologin) never can.';
$string['allowedissuers'] = 'Allowed issuers';
$string['allowedissuers_desc'] = 'DIDs of the issuers whose credentials are accepted, one per line (for example, the Bogotá City Hall one: <code>did:sovra:0x…</code>). <strong>Required:</strong> with an empty list the button is hidden and nobody can log in. Without this list, anyone could issue themselves a credential with someone else\'s document.';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'The verifier workspace API key (<code>sovra_sk_…</code>). Stored encrypted.';
$string['apiurl'] = 'API URL';
$string['apiurl_desc'] = 'Test: <code>https://test-api-sovra.flagonsa.com</code>. Production: <code>https://api.sovra.io</code>.';
$string['auth_sovradescription'] = 'Log in with the Identity Credential from the wallet (Sovra ID). Identifies the person by their document number; never creates accounts.';
$string['back'] = 'Back to login';
$string['claimdocnumber'] = 'Document number claim';
$string['claimdocnumber_desc'] = 'Exact claim name in the credential. Bogotá Identity Credential: <code>num_doc</code>.';
$string['claimdoctype'] = 'Document type claim';
$string['claimdoctype_desc'] = 'Exact claim name in the credential. Bogotá Identity Credential: <code>tipo_doc</code>. Only requested when the type is compared.';
$string['credentialtypes'] = 'Accepted credential types';
$string['credentialtypes_desc'] = 'Accepted <code>vct</code> values, comma separated. Bogotá Identity Credential: <code>IdentidadMigrante</code>. Empty: any type from an allowed issuer.';
$string['doctypemap'] = 'Document type equivalences';
$string['doctypemap_desc'] = 'One equivalence per line: <code>value in the credential = value in Moodle</code>. Compared ignoring case and accents. A type with no line is compared as is.';
$string['documentfield'] = 'Document number field';
$string['documentfield_desc'] = 'Where Moodle stores each account\'s document number. Compared without dots, spaces or hyphens.';
$string['documenttypefield'] = 'Document type field';
$string['documenttypefield_desc'] = 'Profile field holding the document type. Only used when the type is compared.';
$string['matchdoctype'] = 'Also compare the document type';
$string['matchdoctype_desc'] = 'Besides the number, compares the document type in the credential with the one in "Document type field", using the equivalences. Prevents a document of another type with the same digits from opening someone else\'s account. Only takes effect if that field is chosen; the type is then also requested from the wallet.';
$string['logs'] = 'SuperApp login log';
$string['logs_action'] = 'Call, event or source';
$string['logs_empty'] = 'Nothing logged yet.';
$string['logs_http'] = 'HTTP';
$string['logs_intro'] = 'The latest 300 entries: calls to the Sovra API, webhook deliveries and the outcome of every login attempt. They are kept for 30 days. Document numbers and types are never stored.';
$string['logs_kind'] = 'Kind';
$string['logs_result'] = 'Result';
$string['logs_time'] = 'Date';
$string['logs_user'] = 'Account';
$string['logs_verification'] = 'Verification';
$string['loginbutton'] = 'Log in with the SuperApp';
$string['login_connection'] = 'We are having connection problems. Still trying…';
$string['login_error'] = 'We could not start the SuperApp login right now. Please try again in a few minutes.';
$string['login_expired'] = 'The code expired. Generate a new one to try again.';
$string['login_expiresin'] = 'The code expires in {$a}.';
$string['login_intro'] = 'Scan the code with the Bogotá SuperApp and share your Identity Credential. Only your document details are requested.';
$string['login_newcode'] = 'Generate a new code';
$string['login_start'] = 'Show the code';
$string['login_throttled'] = 'You asked for many codes in a row. Wait a few minutes and try again.';
$string['login_openwallet'] = 'Open in the SuperApp';
$string['login_openwallet_hint'] = 'On your phone? Tap the button instead of scanning.';
$string['login_title'] = 'Log in with the SuperApp';
$string['login_unavailable'] = 'Logging in with the SuperApp is not available right now.';
$string['login_waiting'] = 'Waiting for you to share your credential…';
$string['login_success'] = 'Done, opening your account…';
$string['pluginname'] = 'SuperApp login (Sovra ID)';
$string['platformname'] = 'Platform name';
$string['platformname_desc'] = 'How the platform is named in the notice for people without an account: "You do not have an account on <em>name</em>". Empty: the site short name.';
$string['privacy:metadata:auth_sovra_log'] = 'Log of SuperApp login attempts.';
$string['privacy:metadata:auth_sovra_log:result'] = 'The outcome of the attempt.';
$string['privacy:metadata:auth_sovra_log:timecreated'] = 'When it happened.';
$string['privacy:metadata:auth_sovra_log:userid'] = 'The account that logged in or was found.';
$string['privacy:metadata:auth_sovra_log:verificationid'] = 'The verification identifier in Sovra.';
$string['privacy:metadata:auth_sovra_session'] = 'Login sessions in progress. They are deleted about an hour after they expire.';
$string['privacy:metadata:auth_sovra_session:userid'] = 'The account found for the verification.';
$string['privacy:metadata:sovra'] = 'To verify the credential, the person\'s wallet sends Sovra ID the issuer, the credential type and the document number (and the document type, if it is compared); Sovra returns them to Moodle, which uses them to find the account and does not store them.';
$string['privacy:metadata:sovra:document'] = 'Document type and number.';
$string['qr_alt'] = 'QR code to share your credential with the SuperApp';
$string['result_admin'] = 'This account cannot log in with the SuperApp. Log in with your username and password.';
$string['result_authnotallowed'] = 'This account cannot log in with the SuperApp. Log in with your username and password, or contact support.';
$string['result_duplicate'] = 'We found more than one account with your document, so we cannot tell which one is yours. Contact support to sort it out.';
$string['result_expired'] = 'The login took too long and expired. Please try again.';
$string['result_failed'] = 'We could not validate your credential. Check it is valid in the SuperApp and try again.';
$string['result_generic'] = 'We could not complete the login. Please try again.';
$string['result_issuernotallowed'] = 'That credential is not accepted here. Share the Identity Credential from the Bogotá SuperApp.';
$string['result_misconfigured'] = 'SuperApp login is not configured correctly. The platform administrators can check it.';
$string['result_nodocument'] = 'Your credential has no document number. Share the Identity Credential from the Bogotá SuperApp.';
$string['result_notfound'] = 'You do not have an account on {$a}';
$string['result_notfound_nosignup'] = 'There is no account with your document. Contact support to help you create one.';
$string['result_notfound_signup'] = 'There is no account with your document. Sign up, and then you can log in with the SuperApp.';
$string['result_unconfirmed'] = 'Your account is not confirmed yet. Confirm it from the email we sent you when you signed up, and then you can log in with the SuperApp.';
$string['result_suspended'] = 'Your account is suspended. Contact support.';
$string['retry'] = 'Try again';
$string['secret_undecryptable'] = 'Cannot be decrypted: {$a}. This happens, for example, after migrating the site. SuperApp login stays off until it is saved again in the settings.';
$string['settings_accounts'] = 'Which accounts can log in';
$string['settings_connection'] = 'Connection to Sovra ID';
$string['settings_connection_desc'] = 'Webhook URL for the Sovra console (events <code>presentation.verified</code> and <code>presentation.failed</code>): <code>{$a->webhookurl}</code>. The webhook is optional: the login page polls for the result while it waits. Log and connection test: <a href="{$a->logsurl}">{$a->logsurl}</a>.';
$string['settings_credential'] = 'Accepted credential';
$string['settings_credential_desc'] = 'Only the issuer, the credential type and the document number (and the document type, if it is compared) are requested.';
$string['settings_matching'] = 'How the account is found';
$string['settings_matching_desc'] = 'The person logs in only if exactly one account has their document. If there is none, it is not created: they see "You do not have an account on …" and the sign-up link.';
$string['signupurl'] = 'Sign-up page';
$string['signupurl_desc'] = 'Where people without an account are sent. Empty: Moodle\'s own sign-up, if enabled.';
$string['signup'] = 'Go to sign up';
$string['task_cleanup'] = 'Clean up expired SuperApp login sessions and old log entries';
$string['test_button'] = 'Test the connection';
$string['test_error'] = 'The test failed: {$a}';
$string['test_issuers_missing'] = 'There are no allowed issuers: the button is hidden and nobody can log in.';
$string['test_ok'] = 'Connection works: the API accepted the query and opened a test verification (it expires on its own in 10 minutes). API key {$a}.';
$string['warn_idnumber_editable'] = 'The document number is in the ID number and it is not locked for these authentication methods: {$a}. Each person can change it from their profile and enter someone else\'s document. Lock it ("Lock value" for ID number) in the settings of each method.';
$string['warn_profilefield_editable'] = 'The profile field "{$a}" is not locked: each person can change it from their profile and enter someone else\'s document. Mark it as locked in the field settings.';
$string['webhooksecret'] = 'Webhook secret';
$string['webhooksecret_desc'] = 'The secret Sovra signs deliveries with. Optional. Stored encrypted.';
$string['workspaceid'] = 'Workspace ID';
$string['workspaceid_desc'] = 'Optional. When set, deliveries from other workspaces are ignored.';
