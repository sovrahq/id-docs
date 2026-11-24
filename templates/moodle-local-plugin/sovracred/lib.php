<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Callbacks for local_sovracred.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Agrega «Mis credenciales» al perfil del propio usuario.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 * @return void
 */
function local_sovracred_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (!$iscurrentuser) {
        return;
    }
    $node = new \core_user\output\myprofile\node('miscellaneous', 'local_sovracred',
        get_string('mycredentials', 'local_sovracred'), null, new moodle_url('/local/sovracred/credenciales.php'));
    $tree->add_node($node);
}
