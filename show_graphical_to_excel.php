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
 * prints an analysed excel-spreadsheet of the peerassess
 *
 * @copyright SEGP Group 10A
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package mod_peerassess
 */
global $CFG;
global $DB;
require_once("../../config.php");
require_once("lib.php");
require_once("$CFG->libdir/excellib.class.php");
 
$id = required_param('id', PARAM_INT); // Course module id.
$courseid = optional_param('courseid', '0', PARAM_INT);
$userid = optional_param('userid', false, PARAM_INT);
 
$url = new moodle_url('/mod/peerassess/show_graphical_to_excel.php', array('id' => $id));
if ($courseid) {
  $url->param('courseid', $courseid);
}
$PAGE->set_url($url);
 
list($course, $cm) = get_course_and_cm_from_cmid($id, 'peerassess');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/peerassess:viewreports', $context);
 
$peerassess = $PAGE->activityrecord;
 
// Buffering any output. This prevents some output before the excel-header will be send.
ob_start();
ob_end_clean();
 
// Get the questions (item-names).
$peerassessstructure = new mod_peerassess_structure($peerassess, $cm, $course->id);
if (!$items = $peerassessstructure->get_items(true)) {
  print_error('no_items_available_yet', 'peerassess', $cm->url);
}
 
//Get the effective groupmode of this course and module
if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
  $groupmode =  $cm->groupmode;
} else {
  $groupmode = $course->groupmode;
}
 
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
 
$groupName = $DB->get_field('groups', 'name', array('id' => $mygroupid, 'courseid' => $course->id), $strictness = IGNORE_MISSING);
// Creating a workbook.
$filename = "Peer_Factor_" . clean_filename($groupName) . ".xlsx";
$workbook = new MoodleExcelWorkbook($filename);
 
// Creating the worksheet.
error_reporting(0);
$worksheet1 = $workbook->add_worksheet();
error_reporting($CFG->debug);
$worksheet1->hide_gridlines();
$assignment_grades = $DB->get_fieldset_sql(
  'SELECT psa.assignmentid FROM {peerassess_assignments} psa WHERE psa.peerassessid = ' . $peerassess->id,
  array('peerassessid' => $peerassess->id)
);
$col_nums = count($assignment_grades) + 1;
$column_header_count = 0;
$worksheet1->set_column($column_header_count++, $col_nums, 40);
$worksheet1->set_column($column_header_count++, $col_nums, 40);

foreach ($assignment_grades as $assignment_grade) {
  $worksheet1->set_column($column_header_count++, $col_nums, 40);
}

// Creating the needed formats.
$xlsxformats = new stdClass();
$xlsxformats->head1 = $workbook->add_format(['bold' => 1, 'size' => 12]);
$xlsxformats->head2 = $workbook->add_format(['align' => 'left', 'bold' => 1, 'bottum' => 2]);
$xlsxformats->default = $workbook->add_format(['align' => 'left', 'v_align' => 'top']);
$xlsxformats->value_bold = $workbook->add_format(['align' => 'left', 'bold' => 1, 'v_align' => 'top']);
$xlsxformats->procent = $workbook->add_format(['align' => 'left', 'bold' => 1, 'v_align' => 'top', 'num_format' => '#,##0.00%']);
 
// Writing the table header.
$rowoffset1 = 0;
$worksheet1->write_string($rowoffset1, 0, userdate(time()), $xlsxformats->head1);
 
$rowoffset1++;
if (!$usedgroupid) {
  $worksheet1->write_string($rowoffset1, 0, 'Group\'s name', $xlsxformats->head1);
} else {
  $worksheet1->write_string($rowoffset1, 0, 'Student\'s name', $xlsxformats->head1);
}
 
if (!$usedgroupid) {
  $coloffset = 1;
  $worksheet1->write_string($rowoffset1, $coloffset++, 'Average Peer Factor', $xlsxformats->head1);
 
  foreach ($assignment_grades as $assignment_grade) {
    $worksheet1->write_string($rowoffset1, $coloffset++, 'Average Assignment ' . $assignment_grade . ' Grade', $xlsxformats->head1);
  }
} else {
  $coloffset = 1;
  $worksheet1->write_string($rowoffset1, $coloffset++, 'Peer Factor', $xlsxformats->head1);
 
  foreach ($assignment_grades as $assignment_grade) {
    $worksheet1->write_string($rowoffset1, $coloffset++, 'Assignment ' . $assignment_grade . ' Grade', $xlsxformats->head1);
  }
}
 
$students = peerassess_get_all_users_records($cm, $usedgroupid, '', false, false, true);
 
if (empty($students)) {
  $rowoffset1++;
  $worksheet1->write_string($rowoffset1, 0, get_string('noexistingparticipants', 'enrol'), $xlsxformats->head1);
} else {
  if (!$usedgroupid) {
    $groups_average = array();
    $groups = groups_get_activity_allowed_groups($cm);
    foreach ($groups as $group) {
      $groups_average[$group->name] = array();
 
      $total = 0;
      $count = 0;
 
      $students = peerassess_get_all_users_records($cm, $group->id, '', false, false, true);
      foreach ($students as $student) {
        $total = $total + $DB->get_field('peerassess_peerfactors', 'peerfactor', array(
          'userid' => $student->id, 'peerassessid' => $peerassess->id
        ));
        $count = $count + 1;
      }
 
      $groups_average[$group->name]['peerfactor'] = round($total / $count, 3);
 
      $grade_count = 0;
      foreach ($assignment_grades as $assignment_grade) {
        $total = 0;
        $count = 0;
        foreach ($students as $student) {
          $assignment_results = $DB->get_fieldset_sql(
            'SELECT pfg.finalgradewithpa FROM {peerassess_finalgrades} pfg WHERE pfg.peerassessid = ' . $peerassess->id . ' AND pfg.userid = ' . $student->id,
            array('peerassessid' => $peerassess->id)
          );
 
          if (empty($assignment_results)) {
            $total += 0;
          } else {
            $total += $assignment_results[$grade_count];
          }
          $count++;
        }
        $groups_average[$group->name]['assignment' . $assignment_grade] = round($total / $count, 3);
        $grade_count++;
      }
    }
 
    foreach ($groups_average as $group_name => $group_details) {
      $rowoffset1++;
      $coloffset = 0;
 
      $worksheet1->write_string($rowoffset1, $coloffset++, $group_name, $xlsxformats->head1);
 
      foreach ($group_details as $key => $val) {
        $worksheet1->write_string($rowoffset1, $coloffset++, $val, $xlsxformats->head1);
      }
    }
  } else {
    $students = peerassess_get_all_users_records($cm, $usedgroupid, '', false, false, true);
 
    $students_details = array();
    foreach ($students as $student) {
      $students_details[fullname($student)] = array();
 
      $peerfactor = $DB->get_field('peerassess_peerfactors', 'peerfactor', array(
        'userid' => $student->id, 'peerassessid' => $peerassess->id
      ));
 
      $students_details[fullname($student)]['peerfactor'] = $peerfactor;
 
      $is_started = $DB->record_exists('peerassess_completed', array('peerassess' => $peerassess->id, 'userid' => $student->id));
 
      $assignment_results = $DB->get_fieldset_sql(
        'SELECT pfg.finalgradewithpa FROM {peerassess_finalgrades} pfg WHERE pfg.peerassessid = ' . $peerassess->id . ' AND pfg.userid = ' . $student->id,
        array('peerassessid' => $peerassess->id)
      );
 
      $grade_count = 0;
      foreach ($assignment_grades as $assignment_grade) {
        $students_details[fullname($student)]['assignment' . $assignment_grade] = $assignment_results[$grade_count++];
      }
    }
 
    foreach ($students_details as $student_name => $student_details) {
      $coloffset = 0;
      $rowoffset1++;
      $worksheet1->write_string($rowoffset1, $coloffset++, $student_name, $xlsxformats->head1);
 
      foreach ($student_details as $key => $val) {
        $worksheet1->write_string($rowoffset1, $coloffset++, $val, $xlsxformats->head1);
      }
    }
  }
}
 
$workbook->close();