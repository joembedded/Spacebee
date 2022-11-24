<?php
/* deliver.php - WEBHOOK - SWARM data mailer
* Requires: Extra Header 'mailto: MAILADDRESS'
*
* V0.2 - 23.11.2022
* (C)JoEmbedded.de
*
* Added support for binary payload
* Please note: 'deliver_light.php' is a smaller version: message only, without payload decoder
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
// Payload Functions START
function get_i16($valstr){
	return unpack('n',$valstr)[1];
}
function get_u32($valstr){
	return unpack('N',$valstr)[1];
}
function get_ef32($valstr){
	$hval=intval(unpack('N',$valstr)[1]);
	if(($hval>>24)==0xFD) {
		$errno = $hval&0xFFFFFF;
		return "Error:$errno";
	}
	return round(binary32Decode($hval),8); // Float hat max. 8 Stellen
}
function binary32Decode($bin)
{
    $sign = ($bin & 0x80000000) > 0 ? -1 : 1;
    $exp = (($bin & 0x7F800000) >> 23);
    $mantis = ($bin & 0x7FFFFF);

    if ($mantis == 0 && $exp == 0) {
        return 0;
    }
    if ($exp == 255) {
        if ($mantis == 0) return INF;
        if ($mantis != 0) return NAN;
    }

    if ($exp == 0) { // denormalisierte Zahl
        $mantis /= 0x800000;
        return $sign * pow(2, -126) * $mantis;
    } else {
        $mantis |= 0x800000;
        $mantis /= 0x800000;
        return $sign * pow(2,$exp - 127) * $mantis;
    }
}
// Payload Functions END

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
	if($c<32 || $c>127) {
		switch($c){	// Isolate PLHDRS - Try to get as much as possible, even fragments
		case 128:	// PLHDR_RTN - PayloadHeader
			$txtdata.= "=== Payload(RTN):";
			if($cnt-$i>=2){
				$flags=get_i16(substr($strdata,$i+1,2));
				$txtdata.= sprintf(' Flags:%d', $flags);
				$i+=2;
			}
			if($cnt-$i>=4){
				$unixsecs=get_u32(substr($strdata,$i+1,4));
				$txtdata.= sprintf(' Time(UTC):%s', gmdate("d.m.Y H:i:s",$unixsecs));
				$i+=4;
				// Generate Traveltime:
				$traveltime=time()-$unixsecs;
				$th=floor($traveltime/3600); $traveltime -= $th*3600;
				$tmin=floor($traveltime/60);  $traveltime -= $tmin*60;
				$txtdata.= sprintf(' Traveltime:%dh,%dmin,%dsec',$th,$tmin,$traveltime);
			}
			if($cnt-$i>=1){
				$anzn=ord($strdata[$i+1]);
				$txtdata.= sprintf(" N(#):%d ===\n", $anzn); // No Of Values following
				$i++;
				for($j=0;$j<$anzn;$j++){	// Channel-Values
					if($cnt-$i>=4){
						$chanstr = get_ef32(substr($strdata,$i+1,4));
						$txtdata.= "#$j: $chanstr\n";
						$i+=4;
					}
				}
			}
			while($cnt-$i>=3){	// HK-Values
				$hkno=ord($strdata[$i+1]);
				if($hkno==90){
					$hkvbat=get_i16(substr($strdata,$i+2,2));
					$fval=round($hkvbat/1000,3);
					$txtdata.= "HK_Bat: $fval V\n";
				}else if($hkno==91){
					$hktemp=get_i16(substr($strdata,$i+2,2));
					$fval=round($hktemp/100,2);
					$txtdata.= "HK_Temp: $fval oC\n";
				}else break;	
				$i+=3;
			}
			$txtdata.= "===";
			
			break;
		default:
			$txtdata.= sprintf('\x%02X', $c); 
		}
	}
	else $txtdata.=chr($c);
}

$dstr="Message: \"$txtdata\"\n\nSourceData:\n"; // $dstr: Mail Content
foreach ($args as $name => $value) { // Add Header Data
    $dstr.= " - $name: $value\n";
}
$dstr.="\n";

if(!isset($args['deviceId'])) xdie("No JSON 'deviceId'");

$deviceId = $args['deviceId'].'/0x'.dechex($args['deviceId']);
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
