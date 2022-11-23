<?php
/* deliver_light.php - WEBHOOK - SWARM data mailer
* Requires: Extra Header 'mailto: MAILADDRESS'
*
* V0.1 - 03.09.2022
* (C)JoEmbedded.de
*
* This is the most simple Version, decodes only STRINGS
*/

error_reporting(E_ALL);

// Helpers
function send_mail($mail, $cont, $subj, $from)
{
	$host = $_SERVER['SERVER_NAME'];
	$header = "From: $from <no-reply@$host>\r\n" . 'X-Mailer: PHP/' . phpversion();
	$res = @mail($mail, $subj, $cont, $header);
	return $res; // OK: true
}

// --- Write alternating Logfiles ('.php' prevents readout) ---
function addlog($xlog)
{
	$logpath = "./";
	if (@filesize($logpath . "log.log.php") > 1000000) {	// Shift Logs
		@unlink($logpath . "_log_old.log.php");
		@rename($logpath . "log.log.php", $logpath . "_log_old.log.php");
		$xlog .= " ('log.log.php' -> '_log_old.log.php')";
	}
	$log = @fopen($logpath . "log.log.php", 'a');
	if ($log) {
		while (!flock($log, LOCK_EX)) usleep(10000);  // Lock File - Is a MUST
		fputs($log, gmdate("d.m.y H:i:s ", time()) . $_SERVER['REMOTE_ADDR']);        // Write file
		fputs($log, " $xlog\n");        // evt. add extras
		flock($log, LOCK_UN);
		fclose($log);
	}
}
function xdie($reason){ // Exit with Error
	global $xlog;
	http_response_code(400); // Bad Request - before any output!
	$xlog .= "(ERROR: $reason)";
	addlog($xlog);
	echo "ERROR: $reason\n";
	exit();
}

// ******* MAIN ********
$xlog = ""; // LogVariable
header("Content-Type: text/plain; charset=UTF-8");

$headers = apache_request_headers();
if (isset($headers['Mailto'])) $mailto=$headers['Mailto']; // optional Auto-Capitals
else if(isset($headers['mailto'])) $mailto=$headers['mailto'];
else xdie("Header 'mailto' required");
if (!filter_var($mailto, FILTER_VALIDATE_EMAIL)) xdie("'$mailto': Invalid email format");

$entityBody = file_get_contents('php://input');
if(!strlen($entityBody)) xdie("JSON entity missing");

$args = json_decode($entityBody, true); //true: Arg. in $args[] as Ass.Array
$xlog .= "(mailto:'$mailto')(Arg:'$entityBody')"; // log Arguments
if(!isset($args['data'])) xdie("No JSON 'data'");

$strdata=base64_decode($args['data']);
$cnt=strlen($strdata);

$txtdata=""; // What was sent by $TD
for($i=0;$i<$cnt;$i++){ // Make data printable
	$c=ord($strdata[$i]);
	if($c<32 || $c>127) $txtdata.= sprintf('\x%02X', $c); 
	else $txtdata.=chr($c);
}

$dstr="PlainMessage: \"$txtdata\"\n\nSourceData:\n"; // $dstr: Mail Content
foreach ($args as $name => $value) { // Add Header Data
    $dstr.= " - $name: $value\n";
}
$dstr.="\n";

if(!isset($args['deviceId'])) xdie("No JSON 'deviceId'");

$deviceId = $args['deviceId'];
$subj = "SWARM DeviceId: $deviceId";
$from = "Jo's SWARM-Mailer";

// Send the Mail (Main receiver)
$res = send_mail($mailto, $dstr, $subj, $from);
if($res!=true) xdie("Mail to '$mailto' failed");

// A Gimmik: If $txtdata starts with '!'+ validMail + ' ', Rest will be sent to this Mail
// e.g. "!joembedded@gmail.com Can You hear me, Major Tom?"
if($txtdata[0]=='!'){
	$wspos=strpos($txtdata,' ',6); // Minimum Length where 
	if($wspos>0){
		$mailto2 = substr($txtdata,1,$wspos-1); 
		$resttxt = substr($txtdata,$wspos+1); 
		if (filter_var($mailto2, FILTER_VALIDATE_EMAIL) && strlen($resttxt)>0){ // Both OK
			$res = send_mail($mailto2, $resttxt, $subj, $from);
			if($res!=true) xdie("Mail2 to '$mailto' failed");
		}
	}
}

addlog($xlog);
echo "OK";
?>
