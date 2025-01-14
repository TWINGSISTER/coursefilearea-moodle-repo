<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This script fetches files from the dataroot directory
 * You should use the get_file_url() function, available in lib/filelib.php, to link to file.php.
 * This ensures proper formatting and offers useful options.
 * Syntax:      file.php/courseid/dir/dir/dir/filename.ext
 *              file.php/courseid/dir/dir/dir/filename.ext?forcedownload=1 (download instead of inline)
 *              file.php/courseid/dir (returns index.html from dir)
 * Workaround:  file.php?file=/courseid/dir/dir/dir/filename.ext
 * Test:        file.php/testslasharguments
 *
 *
 * TODO: Blog attachments do not have access control implemented - anybody can read them!
 *       It might be better to move the code to separate file because the access
 *       control is quite complex - see bolg/index.php
 *
 * This is the Moodle 1.9 file.php slightly modified for Moodle 2.0 by Tim Williams
 *
 * @package    repository_coursefilearea
 * @copyright  Moodle and AutoTrain
 * @author     Tim Williams <tmw@autotrain.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('../../lib/filelib.php');

global $DB;

if (!isset($CFG->filelifetime)) {
    $lifetime = 86400; // Seconds for files to remain in caches.

} else {
    $lifetime = $CFG->filelifetime;
}

$relativepath = get_file_argument('file.php');
$forcedownload = optional_param('forcedownload', 0, PARAM_BOOL);
// Relative path must start with '/', because of backup/restore!!!.
if (!$relativepath) {
    error('No valid arguments supplied or incorrect server configuration');
} else if ($relativepath[0] != '/') {
    error('No valid arguments supplied, path does not start with slash!');
}
$pathname = $CFG->dataroot . $relativepath;
// Extract relative path components.
$args = explode('/', trim($relativepath, '/'));
if (count($args) == 0) { // Always at least courseid, may search for index.html in course root.
    error('No valid arguments supplied');
}
// Security: limit access to existing course subdirectories.
if (($args[0] != 'blog') and (!$course = $DB->get_record("course", array("id" => (int)$args[0])))) {
    error('Invalid course ID');
}
// Security: prevent access to "000" or "1 something" directories hack for blogs, needs proper security check too.
if (($args[0] != 'blog') and ($args[0] != $course->id)) {
    error('Invalid course ID');
}
// Security: login to course if necessary.
// Note: file.php always calls require_login() with $setwantsurltome=false.in order to avoid messing redirects. MDL-14495.
if ($args[0] == 'blog') {
    if (empty($CFG->bloglevel)) {
        error('Blogging is disabled!');
    } else if ($CFG->bloglevel < BLOG_GLOBAL_LEVEL) {
        require_login(0, true, null, false);
    } else if ($CFG->forcelogin) {
        require_login(0, true, null, false);
    }
} else if ($course->id != SITEID) {
    require_login($course->id, true, null, false);
} else if ($CFG->forcelogin) {
        require_login(0, true, null, false);
}
// Security: only editing teachers can access backups.
if ((count($args) >= 2) and (strtolower($args[1]) == 'backupdata')) {
    if (!has_capability('moodle/site:backup', get_context_instance(CONTEXT_COURSE, $course->id))) {
        error('Access not allowed');
    } else {
        $lifetime = 0; // Disable browser caching for backups.

    }
}
if (is_dir($pathname)) {
    if (file_exists($pathname . '/index.html')) {
        $pathname = rtrim($pathname, '/') . '/index.html';
        $args[] = 'index.html';
    } else if (file_exists($pathname . '/index.htm')) {
        $pathname = rtrim($pathname, '/') . '/index.htm';
        $args[] = 'index.htm';
    } else if (file_exists($pathname . '/Default.htm')) {
        $pathname = rtrim($pathname, '/') . '/Default.htm';
        $args[] = 'Default.htm';
    } else {
        // Security: do not return directory node!.
        not_found($course->id);
    }
}
// Security: teachers can view all assignments, students only their own.
if ((count($args) >= 3) and (strtolower($args[1]) == 'moddata')
    and (strtolower($args[2]) == 'assignment')) {

    $lifetime = 0; // Do not cache assignments, students may reupload them.
    if ($args[4] != $USER->id) {
        $instance = (int)$args[3];
        if (!$cm = get_coursemodule_from_instance('assignment', $instance, $course->id)) {
            not_found($course->id);
        }
        if (!has_capability('mod/assignment:grade', get_context_instance(CONTEXT_MODULE, $cm->id))) {
            error('Access not allowed');
        }
    }
}
// Security: force download of all attachments submitted by students.
if (count($args) >= 3 and strtolower($args[1]) === 'moddata') {
    $mod = clean_param($args[2], PARAM_SAFEDIR);
    if (file_exists("$CFG->dirroot/mod/$mod/lib.php")) {
        if (!$forcedownload) {
            require_once("$CFG->dirroot/mod/$mod/lib.php");
            $trustedfunction = $mod . '_is_moddata_trusted';
            if (function_exists($trustedfunction)) {
                // Force download of all attachments that are not trusted.
                $forcedownload = !$trustedfunction();
            } else {
                $forcedownload = 1;
            }
        }
    } else {
        // Module is not installed - better not serve file at all.
        not_found($course->id);
    }
}
if ($args[0] == 'blog') {
    $forcedownload = 1; // Force download of all attachments.

}
// Security: some protection of hidden resource files.
// Warning: it may break backwards compatibility.
if ((!empty($CFG->preventaccesstohiddenfiles)) and (count($args) >= 2) and (!(strtolower($args[1]) == 'moddata'
    and strtolower($args[2]) != 'resource')) // Do not block files from other modules!.
    and (!has_capability('moodle/course:viewhiddenactivities', get_context_instance(CONTEXT_COURSE, $course->id)))) {
    $rargs = $args;
    array_shift($rargs);
    $reference = implode('/', $rargs);
    $sql = "SELECT COUNT(r.id) " . "FROM {$CFG->prefix}resource r, " . "{$CFG->prefix}course_modules cm, " .
    "{$CFG->prefix}modules m WHERE r.course    = '{$course->id}' " . "AND m.name      = 'resource' " .
    "AND cm.module   = m.id " . "AND cm.instance = r.id " .
    "AND cm.visible  = 0 " . "AND r.type      = 'file' " . "AND r.reference = '{$reference}'";

    if ($DB->count_records_sql($sql)) {
        error('Access not allowed');
    }
}
// Check that file exists.
if (!file_exists($pathname)) {
    not_found($course->id);
}
// ...========================================.
// Finally send the file.
// ...========================================.
session_write_close(); // Unlock session during fileserving.
$filename = $args[count($args) - 1];
send_file($pathname, $filename, $lifetime, $CFG->filteruploadedfiles, false, $forcedownload);

/*
* Send a 404 error.
* @param int $courseid The ID of the current course
 */
function not_found($courseid) {
    global $CFG;
    header('HTTP/1.0 404 not found');
    print_error('filenotfound', 'error', $CFG->wwwroot . '/course/view.php?id=' . $courseid); // This is not displayed on IIS??.

}
