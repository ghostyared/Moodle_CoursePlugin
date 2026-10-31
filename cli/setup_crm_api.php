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
 * Creates the service account, the role and the token used by the CRM.
 *
 * Usage (as the web server user):
 *   php local/ai_yared_course/cli/setup_crm_api.php --categories=12,13 [--ip=10.0.0.5] [--username=svc_crm_docentes]
 *        [--create-token] [--validdays=365] [--dry-run]
 *
 * --categories: category ids where the service account may create courses AND where the template lives.
 * It is safe to run again: what already exists is reused.
 *
 * @package    local_ai_yared_course
 * @copyright  2026 GhostYared Corp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');

[$options, $unrecognised] = cli_get_params([
    'categories' => '',
    'ip' => '',
    'username' => 'svc_crm_docentes',
    'create-token' => false,
    'validdays' => 365,
    'dry-run' => false,
    'help' => false,
], ['h' => 'help']);

if ($options['help'] || $unrecognised || trim($options['categories']) === '') {
    cli_writeln('Usage: php local/ai_yared_course/cli/setup_crm_api.php --categories=12,13 [--ip=IP[,IP]] '
        . '[--username=svc_crm_docentes] [--create-token] [--validdays=365] [--dry-run]');
    exit($options['help'] ? 0 : 1);
}

$dry = !empty($options['dry-run']);
$categoryids = array_values(array_filter(array_map('intval', explode(',', $options['categories']))));
foreach ($categoryids as $categoryid) {
    if (!$DB->record_exists('course_categories', ['id' => $categoryid])) {
        cli_error("Category $categoryid does not exist.");
    }
}

$capabilities = [
    // Web services.
    'webservice/rest:use',
    // Clone and create.
    'moodle/course:create', 'moodle/course:visibility', 'moodle/backup:backupcourse', 'moodle/restore:restorecourse',
    'moodle/course:viewhiddencourses', 'moodle/category:viewcourselist', 'moodle/category:viewhiddencategories',
    // Edit the structure.
    'moodle/course:update', 'moodle/course:changefullname', 'moodle/course:changeshortname',
    'moodle/course:changeidnumber', 'moodle/course:changesummary', 'moodle/course:movesections',
    'moodle/course:sectionvisibility', 'moodle/course:manageactivities', 'mod/label:addinstance',
    // Enrol the teacher.
    'enrol/manual:enrol', 'moodle/role:assign', 'moodle/user:viewdetails',
    // The plugin.
    'local/ai_yared_course:apibuild',
];

$systemcontext = context_system::instance();

// 1. Role.
$role = $DB->get_record('role', ['shortname' => 'crm_api_cursos']);
if (!$role) {
    cli_writeln('Creating role "API Cursos CRM"...');
    if (!$dry) {
        $roleid = create_role('API Cursos CRM', 'crm_api_cursos',
            'Cuenta de servicio del CRM de docentes: crea cursos desde plantilla.');
        $role = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
    }
} else {
    cli_writeln('Role already exists, updating its capabilities.');
}
if (!$dry) {
    set_role_contextlevels($role->id, [CONTEXT_SYSTEM, CONTEXT_COURSECAT]);
    foreach ($capabilities as $capability) {
        if (!$DB->record_exists('capabilities', ['name' => $capability])) {
            cli_writeln("  ! capability not found, skipped: $capability");
            continue;
        }
        assign_capability($capability, CAP_ALLOW, $role->id, $systemcontext->id, true);
    }
    // The role may assign only editing teacher.
    $editingteacher = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
    if (!$DB->record_exists('role_allow_assign', ['roleid' => $role->id, 'allowassign' => $editingteacher->id])) {
        core_role_set_assign_allowed($role->id, $editingteacher->id);
    }
}

// 2. Service account.
$user = $DB->get_record('user', ['username' => $options['username'], 'mnethostid' => $CFG->mnet_localhost_id]);
if (!$user) {
    cli_writeln('Creating user ' . $options['username'] . '...');
    if (!$dry) {
        $userid = user_create_user((object) [
            'username' => $options['username'],
            'password' => bin2hex(random_bytes(24)),
            'firstname' => 'CRM',
            'lastname' => 'Docentes API',
            'email' => $options['username'] . '@invalid.local',
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'emailstop' => 1,
        ]);
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    }
} else {
    cli_writeln('User already exists.');
}

// 3. Role assignment in the categories (the template must live in one of them).
if (!$dry) {
    foreach ($categoryids as $categoryid) {
        $context = context_coursecat::instance($categoryid);
        if (!$DB->record_exists('role_assignments',
                ['roleid' => $role->id, 'userid' => $user->id, 'contextid' => $context->id])) {
            role_assign($role->id, $user->id, $context->id);
            cli_writeln("Role assigned in category $categoryid.");
        }
    }
}

// 4. Authorise the account in the CRM service.
$service = $DB->get_record('external_services', ['shortname' => 'local_ai_yared_course_crm']);
if (!$service) {
    cli_error('The service "Yared.AI CRM Docentes" does not exist. Finish the plugin upgrade first.');
}
if (!$dry && !$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $user->id])) {
    $DB->insert_record('external_services_users', (object) [
        'externalserviceid' => $service->id,
        'userid' => $user->id,
        'iprestriction' => trim($options['ip']) !== '' ? trim($options['ip']) : null,
        'validuntil' => null,
        'timecreated' => time(),
    ]);
    cli_writeln('Account authorised in the service.');
}

// 5. Token (optional). It is printed once and never stored in clear anywhere else.
if (!empty($options['create-token']) && !$dry) {
    $validuntil = time() + max(1, (int) $options['validdays']) * DAYSECS;
    $token = \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $user->id, $systemcontext,
        $validuntil, trim($options['ip']));
    cli_writeln('');
    cli_writeln('TOKEN (copy it now, send it to the CRM over a secure channel):');
    cli_writeln($token);
}

cli_writeln('');
cli_writeln($dry ? 'Dry run: nothing was changed.' : 'Done. Remember to: enable web services and the REST protocol, '
    . 'set the allowed template ids in the plugin settings, and make sure the template has the manual enrolment method.');
