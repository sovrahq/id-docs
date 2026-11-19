<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Version information for auth_sovra.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'auth_sovra';
$plugin->version   = 2026111900;
$plugin->requires  = 2024100700;      // Moodle 4.5 LTS.
$plugin->supported = [405, 501];      // 4.5 LTS a 5.1.
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.1.0';
