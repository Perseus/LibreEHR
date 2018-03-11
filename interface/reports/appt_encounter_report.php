<?php
/*
 *
 * Copyright (C) 2016-2017 Terry Hill <teryhill@librehealth.io> 
 * Copyright (C) 2005-2010 Rod Roark <rod@sunsetsystems.com>
 *
 * LICENSE: This program is free software; you can redistribute it and/or 
 * modify it under the terms of the GNU General Public License 
 * as published by the Free Software Foundation; either version 3 
 * of the License, or (at your option) any later version. 
 * This program is distributed in the hope that it will be useful, 
 * but WITHOUT ANY WARRANTY; without even the implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the 
 * GNU General Public License for more details. 
 * You should have received a copy of the GNU General Public License 
 * along with this program. If not, see <http://opensource.org/licenses/gpl-license.php>;. 
 * 
 * LICENSE: This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
 * See the Mozilla Public License for more details.
 * If a copy of the MPL was not distributed with this file, You can obtain one at https://mozilla.org/MPL/2.0/.
 *
 * @package LibreHealth EHR 
 * @author Rod Roark <rod@sunsetsystems.com> 
 * 
 * @link http://librehealth.io 
 *
 * This report cross-references appointments with encounters.
 * For a given date, show a line for each appointment with the
 * matching encounter, and also for each encounter that has no
 * matching appointment.  This helps to catch these errors:
 *
 * Appointments with no encounter
 * Encounters with no appointment
 * Codes not justified
 * Codes not authorized
 * Procedure codes without a fee
 * Fees assigned to diagnoses (instead of procedures)
 * Encounters not billed
 *
 * For decent performance the following indexes are highly recommended:
 *   libreehr_postcalendar_events.pc_eventDate
 *   forms.encounter
 *   billing.pid_encounter
 */

require_once("../globals.php");
require_once("../../library/report_functions.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/formatting.inc.php");
require_once("../../custom/code_types.inc.php");
require_once("$srcdir/billing.inc");
$DateFormat = DateFormatRead();
$DateLocale = getLocaleCodeForDisplayLanguage($GLOBALS['language_default']);

 $errmsg  = "";
 $alertmsg = ''; // not used yet but maybe later
 $grand_total_charges    = 0;
 $grand_total_copays     = 0;
 $grand_total_encounters = 0;

function postError($msg) {
  global $errmsg;
  if ($errmsg) $errmsg .= '<br />';
  $errmsg .= $msg;
}

 function bucks($amount) {
  if ($amount) echo oeFormatMoney($amount);
 }

 function endDoctor(&$docrow) {
  global $grand_total_charges, $grand_total_copays, $grand_total_encounters;
  if (!$docrow['docname']) return;

  echo " <tr class='report_totals'>\n";
  echo "  <td colspan='5'>\n";
  echo "   &nbsp;" . xl('Totals for','','',' ') . $docrow['docname'] . "\n";
  echo "  </td>\n";
  echo "  <td align='right'>\n";
  echo "   &nbsp;" . $docrow['encounters'] . "&nbsp;\n";
  echo "  </td>\n";
  echo "  <td align='right'>\n";
  echo "   &nbsp;"; bucks($docrow['charges']); echo "&nbsp;\n";
  echo "  </td>\n";
  echo "  <td align='right'>\n";
  echo "   &nbsp;"; bucks($docrow['copays']); echo "&nbsp;\n";
  echo "  </td>\n";
  echo "  <td colspan='2'>\n";
  echo "   &nbsp;\n";
  echo "  </td>\n";
  echo " </tr>\n";

  $grand_total_charges     += $docrow['charges'];
  $grand_total_copays      += $docrow['copays'];
  $grand_total_encounters  += $docrow['encounters'];

  $docrow['charges']     = 0;
  $docrow['copays']      = 0;
  $docrow['encounters']  = 0;
 }

 $form_facility  = isset($_POST['form_facility']) ? $_POST['form_facility'] : '';
 $from_date = fixDate($_POST['form_from_date'], date('Y-m-d'));
 $to_date = fixDate($_POST['form_to_date'], date('Y-m-d'));
 if ($_POST['form_refresh']) {
  $from_date = fixDate($_POST['form_from_date'], date('Y-m-d'));
  $to_date = fixDate($_POST['form_to_date'], "");

  // MySQL doesn't grok full outer joins so we do it the hard way.
  //
  $query = "( " .
   "SELECT " .
   "e.pc_eventDate, e.pc_startTime, " .
   "fe.encounter, fe.date AS encdate, " .
   "f.authorized, " .
   "p.fname, p.lname, p.pid, " .
   "CONCAT( u.lname, ', ', u.fname ) AS docname " .
   "FROM libreehr_postcalendar_events AS e " .
   "LEFT OUTER JOIN form_encounter AS fe " .
   "ON fe.date = e.pc_eventDate AND fe.pid = e.pc_pid " .
   "LEFT OUTER JOIN forms AS f ON f.pid = fe.pid AND f.encounter = fe.encounter AND f.formdir = 'patient_encounter' " .
   "LEFT OUTER JOIN patient_data AS p ON p.pid = e.pc_pid " .
   // "LEFT OUTER JOIN users AS u ON BINARY u.username = BINARY f.user WHERE ";
   "LEFT OUTER JOIN users AS u ON u.id = fe.provider_id WHERE ";
  if ($to_date) {
   $query .= "e.pc_eventDate >= '$from_date' AND e.pc_eventDate <= '$to_date' ";
  } else {
   $query .= "e.pc_eventDate = '$from_date' ";
  }
  if ($form_facility !== '') {
   $query .= "AND e.pc_facility = '" . add_escape_custom($form_facility) . "' ";
  }
  // $query .= "AND ( e.pc_catid = 5 OR e.pc_catid = 9 OR e.pc_catid = 10 ) " .
  $query .= "AND e.pc_pid != '' AND e.pc_apptstatus != '?' " .
   ") UNION ( " .
   "SELECT " .
   "e.pc_eventDate, e.pc_startTime, " .
   "fe.encounter, fe.date AS encdate, " .
   "f.authorized, " .
   "p.fname, p.lname, p.pid, " .
   "CONCAT( u.lname, ', ', u.fname ) AS docname " .
   "FROM form_encounter AS fe " .
   "LEFT OUTER JOIN libreehr_postcalendar_events AS e " .
   "ON fe.date = e.pc_eventDate AND fe.pid = e.pc_pid AND " .
   // "( e.pc_catid = 5 OR e.pc_catid = 9 OR e.pc_catid = 10 ) " .
   "e.pc_pid != '' AND e.pc_apptstatus != '?' " .
   "LEFT OUTER JOIN forms AS f ON f.pid = fe.pid AND f.encounter = fe.encounter AND f.formdir = 'patient_encounter' " .
   "LEFT OUTER JOIN patient_data AS p ON p.pid = fe.pid " .
   // "LEFT OUTER JOIN users AS u ON BINARY u.username = BINARY f.user WHERE ";
   "LEFT OUTER JOIN users AS u ON u.id = fe.provider_id WHERE ";
  if ($to_date) {
   // $query .= "LEFT(fe.date, 10) >= '$from_date' AND LEFT(fe.date, 10) <= '$to_date' ";
   $query .= "fe.date >= '$from_date 00:00:00' AND fe.date <= '$to_date 23:59:59' ";
  } else {
   // $query .= "LEFT(fe.date, 10) = '$from_date' ";
   $query .= "fe.date >= '$from_date 00:00:00' AND fe.date <= '$from_date 23:59:59' ";
  }
  if ($form_facility !== '') {
   $query .= "AND fe.facility_id = '" . add_escape_custom($form_facility) . "' ";
  }
  $query .= ") ORDER BY docname, IFNULL(pc_eventDate, encdate), pc_startTime";

  $res = sqlStatement($query);
 }
?>
<html>
<head>
<?php html_header_show();?>
<link rel="stylesheet" href="<?php echo $css_header;?>" type="text/css">
<style type="text/css">

/* specifically include & exclude from printing */
@media print {
    #report_parameters {
        visibility: hidden;
        display: none;
    }
    #report_parameters_daterange {
        visibility: visible;
        display: inline;
    }
    #report_results table {
       margin-top: 0px;
    }
}

/* specifically exclude some from the screen */
@media screen {
    #report_parameters_daterange {
        visibility: hidden;
        display: none;
    }
}

</style>
<link rel="stylesheet" href="../../library/css/jquery.datetimepicker.css">
<title><?php  xl('Appointments and Encounters','e'); ?></title>

<script type="text/javascript" src="../../library/js/jquery-1.9.1.min.js"></script>

<script language="JavaScript">

 $(document).ready(function() {
  var win = top.printLogSetup ? top : opener.top;
  win.printLogSetup(document.getElementById('printbutton'));
 });

</script>

</head>

<body class="body_top">

<span class='title'><?php xl('Report','e'); ?> - <?php xl('Appointments and Encounters','e'); ?></span>

<div id="report_parameters_daterange">
    <?php date("d F Y", strtotime(oeFormatDateForPrintReport($_POST['form_from_date'])))
    . " &nbsp; to &nbsp; ". date("d F Y", strtotime(oeFormatDateForPrintReport($_POST['form_to_date']))); ?>
</div>

<form method='post' id='theform' action='appt_encounter_report.php'>

<div id="report_parameters">

<table>
 <tr>
  <td width='630px'>
    <div style='float:left'>

    <table class='text'>
        <tr>
            <td class='label'>
                <?php xl('Facility','e'); ?>:
            </td>
            <td>
              <?php // Build a drop-down list of facilities.
                dropDownFacilities();
              ?>
            </td>
            <?php showFromAndToDates(); ?>
        </tr>
        <tr>
            <td>&nbsp;</td>
            <td>
               <input type='checkbox' name='form_details'
                value='1'<?php if ($_POST['form_details']) echo " checked"; ?>><?php xl('Details','e') ?>
            </td>
        </tr>
    </table>

    </div>

  </td>
  <?php showSubmitPrintButtons(); ?>
 </tr>
</table>

</div> <!-- end apptenc_report_parameters -->

<?php
 if ($_POST['form_refresh'] ) {
?>
<div id="report_results">
<table>

 <thead>
  <th> &nbsp;<?php  xl('Practitioner','e'); ?> </th>
  <th> &nbsp;<?php  xl('Date/Appt','e'); ?> </th>
  <th> &nbsp;<?php  xl('Patient','e'); ?> </th>
  <th> &nbsp;<?php  xl('ID','e'); ?> </th>
  <th align='right'> <?php  xl('Chart','e'); ?>&nbsp; </th>
  <th align='right'> <?php  xl('Encounter','e'); ?>&nbsp; </th>
  <th align='right'> <?php  xl('Charges','e'); ?>&nbsp; </th>
  <th align='right'> <?php  xl('Copays','e'); ?>&nbsp; </th>
  <th> <?php  xl('Billed','e'); ?> </th>
  <th> &nbsp;<?php  xl('Error','e'); ?> </th>
 </thead>
 <tbody>
<?php
 if ($res) {
  $docrow = array('docname' => '', 'charges' => 0, 'copays' => 0, 'encounters' => 0);

  while ($row = sqlFetchArray($res)) {
   $patient_id = $row['pid'];
   $encounter  = $row['encounter'];
   $docname    = $row['docname'] ? $row['docname'] : xl('Unknown');

   if ($docname != $docrow['docname']) {
    endDoctor($docrow);
   }

   $errmsg  = "";
   $billed  = "Y";
   $charges = 0;
   $copays  = 0;
   $gcac_related_visit = false;

   // Scan the billing items for status and fee total.
   //
   $query = "SELECT code_type, code, modifier, authorized, billed, fee, justify " .
    "FROM billing WHERE " .
    "pid = '$patient_id' AND encounter = '$encounter' AND activity = 1";
   $bres = sqlStatement($query);
   //
   while ($brow = sqlFetchArray($bres)) {
    $code_type = $brow['code_type'];
    if ($code_types[$code_type]['fee'] && !$brow['billed'])
      $billed = "";
    if (!$GLOBALS['simplified_demographics'] && !$brow['authorized'])
      postError(xl('Needs Auth'));
    if ($code_types[$code_type]['just']) {
     if (! $brow['justify']) postError(xl('Needs Justify'));
    }
    if ($code_types[$code_type]['fee']) {
     $charges += $brow['fee'];
     if ($brow['fee'] == 0 && !$GLOBALS['ippf_specific']) postError(xl('Missing Fee'));
    } else {
     if ($brow['fee'] != 0) postError(xl('Fee is not allowed'));
    }

    // Custom logic for IPPF to determine if a GCAC issue applies.
    if ($GLOBALS['ippf_specific']) {
      if (!empty($code_types[$code_type]['fee'])) {
        $query = "SELECT related_code FROM codes WHERE code_type = '" .
          $code_types[$code_type]['id'] . "' AND " .
          "code = '" . $brow['code'] . "' AND ";
        if ($brow['modifier']) {
          $query .= "modifier = '" . $brow['modifier'] . "'";
        } else {
          $query .= "(modifier IS NULL OR modifier = '')";
        }
        $query .= " LIMIT 1";
        $tmp = sqlQuery($query);
        $relcodes = explode(';', $tmp['related_code']);
        foreach ($relcodes as $codestring) {
          if ($codestring === '') continue;
          list($codetype, $code) = explode(':', $codestring);
          if ($codetype !== 'IPPF') continue;
          if (preg_match('/^25222/', $code)) $gcac_related_visit = true;
        }
      }
    } // End IPPF stuff

   } // end while
   
   $copays -= getPatientCopay($patient_id,$encounter);

   // The following is removed, perhaps temporarily, because gcac reporting
   // no longer depends on gcac issues.  -- Rod 2009-08-11
   /******************************************************************
   // More custom code for IPPF.  Generates an error message if a
   // GCAC issue is required but is not linked to this visit.
   if (!$errmsg && $gcac_related_visit) {
    $grow = sqlQuery("SELECT l.id, l.title, l.begdate, ie.pid " .
      "FROM lists AS l " .
      "LEFT JOIN issue_encounter AS ie ON ie.pid = l.pid AND " .
      "ie.encounter = '$encounter' AND ie.list_id = l.id " .
      "WHERE l.pid = '$patient_id' AND " .
      "l.activity = 1 AND l.type = 'ippf_gcac' " .
      "ORDER BY ie.pid DESC, l.begdate DESC LIMIT 1");
    // Note that reverse-ordering by ie.pid is a trick for sorting
    // issues linked to the encounter (non-null values) first.
    if (empty($grow['pid'])) { // if there is no linked GCAC issue
      if (empty($grow)) { // no GCAC issue exists
        $errmsg = "GCAC issue does not exist";
      }
      else { // there is one but none is linked
        $errmsg = "GCAC issue is not linked";
      }
    }
   }
   ******************************************************************/
   if ($gcac_related_visit) {
      $grow = sqlQuery("SELECT COUNT(*) AS count FROM forms " .
        "WHERE pid = '$patient_id' AND encounter = '$encounter' AND " .
        "deleted = 0 AND formdir = 'LBFgcac'");
      if (empty($grow['count'])) { // if there is no gcac form
        postError(xl('GCAC visit form is missing'));
      }
   } // end if
   /*****************************************************************/

   if (!$billed) postError($GLOBALS['simplified_demographics'] ?
     xl('Not checked out') : xl('Not billed'));
   if (!$encounter) postError(xl('No visit'));

   if (! $charges) $billed = "";

   $docrow['charges'] += $charges;
   $docrow['copays']  += $copays;
   if ($encounter) ++$docrow['encounters'];

   if ($_POST['form_details']) {
?>
 <tr>
  <td>
   &nbsp;<?php  echo ($docname == $docrow['docname']) ? "" : $docname ?>
  </td>
  <td>
   &nbsp;<?php
    /*****************************************************************
    if ($to_date) {
        echo $row['pc_eventDate'] . '<br>';
        echo substr($row['pc_startTime'], 0, 5);
    }
    *****************************************************************/
    if (empty($row['pc_eventDate'])) {
      echo oeFormatShortDate(substr($row['encdate'], 0, 10));
    }
    else {
      echo oeFormatShortDate($row['pc_eventDate']) . ' ' . substr($row['pc_startTime'], 0, 5);
    }
    ?>
  </td>
  <td>
   &nbsp;<?php  echo $row['fname'] . " " . $row['lname'] ?>
  </td>
  <td>
   &nbsp;<?php  echo $row['pid'] ?>
  </td>
  <td align='right'>
   <?php  echo $row['pid'] ?>&nbsp;
  </td>
  <td align='right'>
   <?php  echo $encounter ?>&nbsp;
  </td>
  <td align='right'>
   <?php  bucks($charges) ?>&nbsp;
  </td>
  <td align='right'>
   <?php  bucks($copays) ?>&nbsp;
  </td>
  <td>
   <?php  echo $billed ?>
  </td>
  <td style='color:#cc0000'>
   <?php echo $errmsg; ?>&nbsp;
  </td>
 </tr>
<?php
   } // end of details line

   $docrow['docname'] = $docname;
  } // end of row

  endDoctor($docrow);

  echo " <tr class='report_totals'>\n";
  echo "  <td colspan='5'>\n";
  echo "   &nbsp;" . xl('Grand Totals') . "\n";
  echo "  </td>\n";
  echo "  <td align='right'>\n";
  echo "   &nbsp;" . $grand_total_encounters . "&nbsp;\n";
  echo "  </td>\n";
  echo "  <td align='right'>\n";
  echo "   &nbsp;"; bucks($grand_total_charges); echo "&nbsp;\n";
  echo "  </td>\n";
  echo "  <td align='right'>\n";
  echo "   &nbsp;"; bucks($grand_total_copays); echo "&nbsp;\n";
  echo "  </td>\n";
  echo "  <td colspan='2'>\n";
  echo "   &nbsp;\n";
  echo "  </td>\n";
  echo " </tr>\n";

 }
?>
</tbody>
</table>
</div> <!-- end the apptenc_report_results -->
<?php } else { ?>
<div class='text'>
    <?php echo xl('Please input search criteria above, and click Submit to view results.', 'e' ); ?>
</div>
<?php } ?>

<input type='hidden' name='form_refresh' id='form_refresh' value=''/>

</form>
<script>
<?php if ($alertmsg) { echo " alert('$alertmsg');\n"; } ?>
</script>
</body>

<script type="text/javascript" src="../../library/js/jquery.datetimepicker.full.min.js"></script>
<script>
    $(function() {
        $("#form_from_date").datetimepicker({
            timepicker: false,
            format: "<?= $DateFormat; ?>"
        });
        $("#form_to_date").datetimepicker({
            timepicker: false,
            format: "<?= $DateFormat; ?>"
        });
        $.datetimepicker.setLocale('<?= $DateLocale; ?>');
    });
</script>

</html>
