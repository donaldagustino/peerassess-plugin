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
 * shows an analysed view of peerassess
 *
 * @copyright Donald Otto Agustino
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package mod_peerassess
 */

require_once("../../config.php");
require_once("lib.php");

$current_tab = 'graphicanalysis';

$id = required_param('id', PARAM_INT);  // Course module id.

$url = new moodle_url('/mod/peerassess/show_graphical_analysis.php', array('id'=>$id));
$PAGE->set_url($url);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'peerassess');
require_course_login($course, true, $cm);

$peerassess = $PAGE->activityrecord;
$peerassessstructure = new mod_peerassess_structure($peerassess, $cm);

$context = context_module::instance($cm->id);

if (!$peerassessstructure->can_view_analysis()) {
    print_error('error');
}

/// Print the page header

$PAGE->set_heading($course->fullname);
$PAGE->set_title($peerassess->name);
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($peerassess->name));

/// print the tabs
require('tabs.php');

if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
    $groupmode =  $cm->groupmode;
} else {
    $groupmode = $course->groupmode;
}
$groupselect = groups_print_activity_menu($cm, $url->out(), true);
$mygroupid = groups_get_activity_group($cm);

//get students in conjunction with groupmode
if ($groupmode > 0) {
    if ($mygroupid > 0) {
        $usedgroupid = $mygroupid;
    } else {
        $usedgroupid = false;
    }
} else {
    $usedgroupid = false;
}

if(NULL == ($matchcount = $DB->count_records('groups_members', array('groupid'=>$mygroupid)))){
    //get all user who can complete this peerassess
    $cap = 'mod/peerassess:complete';
    $userfieldsapi = \core_user\fields::for_name();
    $allnames = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    $fields = 'u.id, ' . $allnames . ', u.picture, u.email, u.imagealt';
    if (!$allusers = get_users_by_capability($context,
                                            $cap,
                                            $fields,
                                            '',
                                            '',
                                            '',
                                            '',
                                            '',
                                            true)) {
        return false;
    }
    $matchcount = count($allusers);
}


//####### viewreports-start
//print the list of students
echo $OUTPUT->heading(get_string('members_in_current_group', 'peerassess', $matchcount), 4);
echo isset($groupselect) ? $groupselect : '';

//print export to excel button
echo $OUTPUT->container_start('form-buttons');
$aurl = new moodle_url('/mod/peerassess/show_graphical_to_excel.php', ['sesskey' => sesskey(), 'id' => $id]);
echo $OUTPUT->single_button($aurl, get_string('export_to_excel', 'peerassess'));
echo $OUTPUT->container_end();

$students = peerassess_get_all_users_records($cm, $usedgroupid, '', false, false, true);

$student_names = array();
$student_scores = array();

if (empty($students)) {
    echo $OUTPUT->notification(get_string('noexistingparticipants', 'enrol'));
} else {
    foreach ($students as $student) {
        array_push($student_names, fullname($student));
        $peerfactor = $DB->get_field('peerassess_peerfactors','peerfactor', array(
            'userid' => $student->id, 'peerassessid' => $peerassess->id));
            
        array_push($student_scores, $peerfactor);

        $is_started = $DB->record_exists('peerassess_completed', array('peerassess'=>$peerassess->id, 'userid'=>$student->id));

    }
}

if ($mygroupid != 0) {
    $chart = new \core\chart_bar();
    $chart->set_labels($student_names);
    $chart->add_series(new \core\chart_series('Peer Factor', $student_scores));
    $yaxis = $chart->get_yaxis(0, true);
    $yaxis->set_max(2.0);
    
    echo $OUTPUT->render($chart);
} else {
    $groups_average = array();
    $groups = groups_get_activity_allowed_groups($cm);
    foreach ($groups as $group) {
        $groups_average[$group->name] = 0;

        $total = 0;
        $count = 0;

        $students = peerassess_get_all_users_records($cm, $group->id, '', false, false, true);
        foreach ($students as $student) {
            $total = $total + $DB->get_field('peerassess_peerfactors','peerfactor', array(
                'userid' => $student->id, 'peerassessid' => $peerassess->id));
            $count = $count + 1;
        }

        $groups_average[$group->name] = round($total / $count, 3);
    }

    $chart = new \core\chart_bar();
    $chart->set_labels(array_keys($groups_average));
    $chart->add_series(new \core\chart_series('Average Peer Factor', array_values($groups_average)));
    $yaxis = $chart->get_yaxis(0, true);
    $yaxis->set_max(2.0);

    echo $OUTPUT->render($chart);
}

$assignment_grades = $DB->get_fieldset_sql(
    'SELECT psa.assignmentid FROM {peerassess_assignments} psa WHERE psa.peerassessid = ' . $peerassess->id,
    array('peerassessid' => $peerassess->id)
);
 
if ($mygroupid != 0) {
    $grade_count = 0;
    foreach ($assignment_grades as $assignment_grade) {
        $student_assignment_grade = array();
 
        foreach ($students as $student) {
            $assignment_results = $DB->get_fieldset_sql(
                'SELECT pfg.finalgradewithpa FROM  {peerassess_finalgrades} pfg WHERE pfg.peerassessid = ' . $peerassess->id . ' AND pfg.userid = ' . $student->id,
                array('peerassessid' => $peerassess->id)
            );
 
            if (empty($assignment_results)) {
                $student_assignment_grade[fullname($student)] = 0;
            } else {
                $student_assignment_grade[fullname($student)] = $assignment_results[$grade_count];
            }
            
        }
 
        $chart = new \core\chart_bar();
        $chart->set_labels(array_keys($student_assignment_grade));
        $chart->add_series(new \core\chart_series('Assignment ' . $assignment_grade . ' Grade', array_values($student_assignment_grade)));
        $yaxis = $chart->get_yaxis(0, true);
        $yaxis->set_max(100.0);
 
        echo $OUTPUT->render($chart);
 
        $grade_count++;
    }
} else {
    
    $grade_count = 0;
    foreach ($assignment_grades as $assignment_grade) {
        $groups_assignment_average = array();
        $groups = groups_get_activity_allowed_groups($cm);
        foreach ($groups as $group) {
            $groups_assignment_average[$group->name] = 0;
 
            $total = 0;
            $count = 0;
 
            $students = peerassess_get_all_users_records($cm, $group->id, '', false, false, true);
            foreach ($students as $student) {
                $assignment_results = $DB->get_fieldset_sql(
                    'SELECT pfg.finalgradewithpa FROM  {peerassess_finalgrades} pfg WHERE pfg.peerassessid = ' . $peerassess->id . ' AND pfg.userid = ' . $student->id,
                    array('peerassessid' => $peerassess->id)
                );
 
                if (empty($assignment_results)) {
                    $total += 0;
                } else {
                    $total += $assignment_results[$grade_count];
                }
                $count++;
            }
 
            $groups_assignment_average[$group->name] = round($total / $count, 3);
        }
 
        $chart = new \core\chart_bar();
        $chart->set_labels(array_keys($groups_assignment_average));
        $chart->add_series(new \core\chart_series('Assignment ' . $assignment_grade . ' Grade', array_values($groups_assignment_average)));
        $yaxis = $chart->get_yaxis(0, true);
        $yaxis->set_max(100.0);
 
        echo $OUTPUT->render($chart);
 
        $grade_count++;
    }
}

echo $OUTPUT->footer();

