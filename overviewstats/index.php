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
  * Displays some overview statistics for the site
  *
  * @package report_overviewstats
  * @author DualCube <admin@dualcube.com>
  * @copyright 2023 DualCube <admin@dualcube.com>
  * @copyright based on work by 2013 David Mudrak <david@moodle.com>
  * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
  */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/report/overviewstats/locallib.php');

$courseid = optional_param('course', null, PARAM_INT);
$date = optional_param('date', null, PARAM_INT);
$course = null;

if (is_null($courseid)) {
    // Site level reports.
    admin_externalpage_setup('overviewstats', '', null, '', ['pagelayout' => 'report']);
} else {
    // Course level report.
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context = context_course::instance($course->id);

    require_login($course, false);
    require_capability('report/overviewstats:view', $context);

    $PAGE->set_url(new moodle_url('/report/overviewstats/index.php', ['course' => $course->id]));
    $PAGE->set_pagelayout('report');
    $PAGE->set_title($course->shortname . ' - ' . get_string('pluginname', 'report_overviewstats'));
    $PAGE->set_heading($course->fullname . ' - ' . get_string('pluginname', 'report_overviewstats'));

    $strftimedate = get_string("strftimedate");
    $strftimedaydate = get_string("strftimedaydate");

    // Get all the possible dates.
    // Note that we are keeping track of real (GMT) time and user time.
    // User time is only used in displays - all calcs and passing is GMT.
    $timenow = time(); // GMT.

    // What day is it now for the user, and when is midnight that day (in GMT).
    $timemidnight = usergetmidnight($timenow);

    // Put today up the top of the list.
    $dates = array("$timemidnight" => get_string("today").", ".userdate($timenow, $strftimedate) );

    // If course is empty, get it from frontpage.
    $c = get_course($courseid);
    if (!$c->startdate or ($c->startdate > $timenow)) {
        $c->startdate = $c->timecreated;
    }
    $numdates = 1;
    while ($timemidnight > $c->startdate and $numdates < 365) {
        $timemidnight = $timemidnight - 86400;
        $timenow = $timenow - 86400;
        $dates["$timemidnight"] = userdate($timenow, $strftimedaydate);
        $numdates++;
    }
    $path = 'generate.php?course='. $courseid . '&date=' . $date;
    echo $OUTPUT->header();
    echo html_writer::start_tag('form', array('class' => 'logselecform', 'action' => $CFG->wwwroot . '/report/overviewstats/index.php', 'method' => 'get'));
    echo html_writer::empty_tag('input',array('type' => 'hidden', 'name' => 'course', 'value' => $courseid));
    echo html_writer::select($dates,"date");
    echo html_writer::empty_tag('input', array('type' => 'submit',
    'value' => "generate a graph", 'class' => 'btn btn-primary'));
    echo html_writer::end_tag('form');
    if(!is_null($date))
        echo html_writer::img($path, 'Alternative Text');
    echo $OUTPUT->footer();
}

