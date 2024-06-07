<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * @package     local_greetings
 * @copyright   2023 NGaku <b206651@hiroshima-u.ac.jp>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot. '/report/overviewstats/generate.php');
require_once($CFG->dirroot . '/report/overviewstats/locallib.php');
$courseid = optional_param('course', null, PARAM_INT);
$starttime = optional_param('date', null, PARAM_INT);
$endtime = $starttime + 86400;
$PAGE->set_url(new
moodle_url('/report/overviewstats/generate.php', ['course' => $courseid,'date' => $starttime]));

$strftimedate = get_string("strftimedate");
$strftimedaydate = get_string("strftimedaydate");

// Get all the possible dates.
// Note that we are keeping track of real (GMT) time and user time.
// User time is only used in displays - all calcs and passing is GMT.
$timenow = time(); // GMT.

// What day is it now for the user, and when is midnight that day (in GMT).
$timemidnight = usergetmidnight($timenow);

// Put today up the top of the list.
$dates = array("$timemidnight" => get_string("today").", ".userdate($timenow, $strftimedate));

// If course is empty, get it from frontpage.
$course = get_course($courseid);
if (!$course->startdate or ($course->startdate > $timenow)) {
    $course->startdate = $course->timecreated;
}
$numdates = 1;
while ($timemidnight > $course->startdate and $numdates < 365) {
    $timemidnight = $timemidnight - 86400;
    $timenow = $timenow - 86400;
    $dates["$timemidnight"] = userdate($timenow, $strftimedaydate);
    $numdates++;
}

//モジュール一覧を取得してsqlを生成
$modules = $DB->get_recordset_sql("SELECT name FROM {modules}");
$case_sql = "";
$leftjoin_sql = "";
foreach($modules as $module){
    $case_sql = $case_sql . " WHEN  m.NAME = '$module->name' THEN $module->name.NAME";
    $leftjoin_sql = $leftjoin_sql . " LEFT JOIN {" . $module->name. "} $module->name ON cm.instance = $module->name.id";
}

//縦軸の目盛を取得
$y_scale_sorted = [];

$sql = "SELECT    t1.NAME    sec_name,
t1.section sec_ord,
t1.parent  sec_parent,
t1.visible sec_visible,
t2.cmid    cm_id,
t2.visible cm_visible,
t3.ordinal cm_ord,
t2.cmname  cm_type,
t2.NAME    cm_name
FROM      (
          SELECT    cm.section,
                    cm.id     cmid,
                    cm.module cmmodule,
                    m.NAME    cmname,
                    cm.visible,
                    cm.instance,
                    CASE $case_sql
                              ELSE 'unknown'
                    END AS NAME
          FROM      {course_modules} cm
          JOIN      {modules} m
          ON        cm.module = m.id $leftjoin_sql
          WHERE cm.course = $courseid) t2
JOIN
(
       SELECT elements.cmid::bigint,
              elements.ordinal
       FROM   {course_sections},
              unnest(string_to_array(sequence, ',')) WITH ordinality AS elements(cmid, ordinal)
       WHERE  course = $courseid) t3
using    (cmid)
FULL JOIN
(
          SELECT    cs.id,
                    cs.section,
                    cfo.value :: bigint parent,
                    cs.visible,
                    cs.NAME
          FROM      {course_sections} cs
          LEFT JOIN {course_format_options} cfo
          ON        (
                              cfo.format = 'flexsections'
                    AND       cfo.NAME = 'parent'
                    AND       cfo.sectionid = cs.id )
          WHERE     cs.course = $courseid
          ORDER BY  cs.section) t1
ON        t2.section = t1.id
ORDER BY  sec_name,cm_ord";

$section0_modules = [];
$modules = [];
$recordset = $DB->get_recordset_sql($sql);
foreach ($recordset as $record) {
    if (!$record->sec_name) {
        $section0_modules[] = [
            'cm_name' => $record->cm_name,
            'sec_name' => $record->sec_name
        ];
    } else {
        $modules[] = [
            'cm_name' => $record->cm_name,
            'sec_name' => $record->sec_name
        ];
    }
}

$y_scale = array_merge($section0_modules,$modules);

$flag = []; //sectionがある番号
$i = 0;
foreach($y_scale as $name) {
    $sec_name = $name['sec_name'];
    $cm_name = $name['cm_name'];
    if($sec_name){
        if (!array_search($sec_name,$y_scale_sorted)) {
        array_push($y_scale_sorted, $sec_name);
        array_push($flag,$i++);
        }
    }
    if (!array_search($cm_name,$y_scale_sorted)) {
        array_push($y_scale_sorted, $cm_name);
        $i++;
    }
}

$i = 0;

$y_scale_sorted = array_reverse($y_scale_sorted);
foreach($flag as $f){
    $flag[$i++] = count($y_scale_sorted) - $f - 1;
}
// 画像と色の初期化
$width = 1200;
$height = 1000;
$image = imagecreatetruecolor($width, $height);
$background = imagecolorallocate($image, 255, 255, 255); // 白に変更
imagefilledrectangle($image, 0, 0, $width, $height, $background);
$pointColor = imagecolorallocate($image, 0, 0, 255);
$grid_color = imagecolorallocate($image, 50, 50, 50);
$text_color = imagecolorallocate($image,0,40,39);
$font_size = 10;
$font = $CFG->dirroot . "/report/overviewstats/sazanami-gothic.ttf";
if($starttime){
    imagestring($image, 4, 0, 20 ,date('Y-m-d',$starttime), $text_color);
}

// Y軸の目盛りの位置を求める
$yAxes = [];
$labelWidths = [0];
for ($i = 0; $i < count($y_scale_sorted); $i++) {
    $name = $y_scale_sorted[$i];
    $yAxes[$name] = 50 + (($height - 100) - ($i * (($height - 100) / (count($y_scale_sorted) - 1))));
    $labelWidth = imagettfbbox($font_size, 0, $font, strip_tags($name))[2]; // ラベルの幅を取得
    array_push($labelWidths,$labelWidth);
}
$labelMargin = 10; // ラベルとグラフの最小マージン
$xstart = max($labelWidths) + $labelMargin;

// 目盛の刻みを入れる
for ($i = 0; $i < count($y_scale_sorted); $i++){
    $name = $y_scale_sorted[$i];
    imagettftext($image, $font_size, 0, max($labelWidths) - imagettfbbox($font_size, 0, $font, strip_tags($name))[2], $yAxes[$name] + 3, $text_color, $font, strip_tags($name));
    if(array_search($i,$flag)){
        imagettftext($image, $font_size, 0, max($labelWidths) - imagettfbbox($font_size, 0, $font, strip_tags($name))[2] + 1, $yAxes[$name] + 3, $text_color, $font, strip_tags($name));
    }
    if($i % 2 == 0){
        imageline($image, $xstart, $yAxes[$name], $width - 50, $yAxes[$name], $grid_color);
    } else {
    imagedashedline($image,$xstart, $yAxes[$name], $width - 50, $yAxes[$name], $grid_color);
    }
}

// X軸およびY軸を描画
$pointColor = imagecolorallocate($image, 0, 0, 255);
imageline($image, $xstart, $height - 50, $width - 50, $height - 50, $pointColor); // X軸
imageline($image, $xstart, 50, $xstart, $height - 50, $pointColor); // Y軸
//0,6,12,18,24時に目盛を打つ
imagestring($image, 4, $xstart, $height - 40, "0", $text_color);
imagestring($image, 4, (($width - 50) - $xstart) / 4 + $xstart, $height - 40, "6", $text_color);
imagestring($image, 4, (($width - 50) - $xstart) / 2 + $xstart, $height - 40, "12", $text_color);
imagestring($image, 4, ((($width - 50) - $xstart) / 4) * 3 + $xstart, $height - 40, "18", $text_color);
imagestring($image, 4, $width - 50, $height - 40, "24", $text_color);

imagestring($image, 5, (($width - 50) - $xstart) / 2 + $xstart - 10, $height - 20, "Time", $text_color);

//指定した日のログを取得
$sql_section = "SELECT name,timecreated,userid
                FROM {logstore_standard_log} AS sl 
                JOIN {course_sections} AS cs 
                ON sl.courseid = cs.course 
                WHERE sl.courseid = $courseid AND CAST(sl.other::json->>'coursesectionnumber' AS bigint) = cs.section";

$sql_context = "SELECT 
sl.timecreated,
sl.userid,
cm.NAME
FROM   {logstore_standard_log} sl
JOIN (SELECT cm.id,
             cm.section,
             cm.module,
             m.NAME module_name,
             cm.instance,
             CASE $case_sql
               ELSE 'unknown'
             END AS NAME
      FROM   {course_modules} cm
             JOIN {modules} m ON cm.module = m.id $leftjoin_sql
WHERE cm.course = $courseid) cm
ON cm.id = sl.contextinstanceid
WHERE sl.contextlevel = 70
AND courseid = $courseid";

$log_context = $DB->get_recordset_sql($sql_context . " AND sl.timecreated >= $starttime 
AND sl.timecreated < $endtime");
$log_section = $DB->get_recordset_sql($sql_section . " AND sl.timecreated >= $starttime
AND sl.timecreated < $endtime");

$log_day = array();

foreach ($log_context as $lc) {
    $log_day[] = array(
        'name' => $lc->name,
        'timecreated' => $lc->timecreated,
        'userid' => $lc->userid
    );
}

foreach ($log_section as $ls) {
    $log_day[] = array(
        'name' => $ls->name,
        'timecreated' => $ls->timecreated,
        'userid' => $ls->userid
    );
}

usort($log_day,function($a,$b){
    return $a['timecreated'] - $b['timecreated'];
});

$log_sort = array();
foreach ($log_day as $item) {
    $userid = $item["userid"];
    if (!isset($log_sort[$userid])) {
        $log_sort[$userid] = array();
    }

    $log_sort[$userid][] = array(
        'name' => $item["name"],
        'timecreated' => $item['timecreated']
    );
}

// データ点と線を描画
foreach($log_sort as $user){
    $prevX = null;
    $prevY = null;
    foreach ($user as $item) {
        $color = imagecolorallocatealpha($image, 255, 0, 0,100);

        $x = $xstart + (($item['timecreated'] - $starttime) / 86400) * ($width - ($xstart + 50));
        $y = $yAxes[$item['name']];
        imagefilledellipse($image, $x, $y, 6, 6, $color);

        // 線を描画
        if ($prevX !== null && $prevY !== null) {
            imageline($image, $prevX, $prevY, $x, $y, $color);
        }

        $prevX = $x;
        $prevY = $y;
    }
}
// 画像を出力
header('Content-Type: image/png');
imagepng($image);

// メモリを解放
imagedestroy($image);