<?php

/**
 * https://www2.htw-dresden.de/~wiki_sn/index.php/HR20#Informationen_zum_Paketablaufplan
 * https://github.com/OpenHR20/OpenHR20/tree/master/doc
 */

// config part
$RRD_HOME="/media/heizung/";
$TIMEZONE="Europe/Berlin";

$deviceMap = [
	8 => 'wohnzimmer-rechts',
	9 => 'wohnzimmer-links',
	10 => 'kuche',
	11 => 'balkon',
	12 => 'schlafzimmer-rechts',
	13 => 'schlafzimmer-links',
	14 => 'badezimmer',
	15 => 'gaby'
];

// NOTE: this file is hudge dirty hack, will be rewriteln
echo "OpenHR20 PHP Daemon". PHP_EOL;
date_default_timezone_set($TIMEZONE);
$maxDebugLines = 1000;

function weights($char) {
	$weights_table = array (
		'D' => 10,
		'S' => 4,
		'W' => 4,
		'G' => 2,
		'R' => 2,
		'T' => 2
	);
	if (isset($weights_table[$char]))
		return $weights_table[$char];
	else 
		return 10;
}
function sendRTC($fp) {
	list($usec, $sec) = explode(" ", microtime());
	$items = getdate($sec);
	$time = sprintf("H%02x%02x%02x%02x" . PHP_EOL,
		$items['hours'], $items['minutes'], $items['seconds'], round($usec*100));
	$date = sprintf("Y%02x%02x%02x" . PHP_EOL,
		$items['year']-2000, $items['mon'], $items['mday']);
	echo $time ." ". $date;
	fwrite($fp,$date); fwrite($fp,$time);  // was other way around
}

$mqttHost = '192.168.3.19';
$mqttPort = 1883;
$mqttBaseTopic = 'stat/openhr20/RESULT/';
$mqttDebug = false;
$mqttRetainStat = true;
$mqttQos = 0;
$mosquitto = 'mosquitto_pub';
$mqttHome = '/home/pi'; # 

function mqttSend($addr, $data, $retain = false, $qos = 0) {
	global $mqttHost, $mqttPort, $mqttBaseTopic, $mqttDebug,
		$mqttRetain, $mqttQos, $mosquitto, $mqttHome;

	$command = [];
	if ($mqttHome) {
		$command[] = "HOME=$mqttHome";
	}
	$command[] = $mosquitto;

	$args = [
		"-h $mqttHost",
		"-p $mqttPort",
		"-t $mqttBaseTopic$addr",
		"-m " . '"' . addslashes(json_encode($data)) . '"'
	];

	if ($retain) {
		$args[] = '-r';
	}

	if ($qos) {
		$args[] = "-q $qos";
	}

	$cmnd = implode(' ', $command) . ' ' . implode(' ', $args);
	if ($mqttDebug) {
		echo $cmnd . PHP_EOL;
	}
	system($cmnd);
}

$rrdDebug = false;
function updateRrd($addr, $data, $time) {
	global $rrdDebug, $RRD_HOME;
	$rrd_file = rtrim($RRD_HOME, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "openhr20_".$addr.".rrd";
	if (file_exists ($rrd_file)) {
		$cmnd = "rrdtool update ".$rrd_file." ".$time.":".(int)$data['real'].":".(int)$data['wanted'].":".(int)$data['valve'].":".(int)(isset($data['window'])?:0);
		if ($rrdDebug) {
			echo $cmnd . PHP_EOL;
		}
		system($cmnd); 
	}
}

$db = new SQLite3("/media/heizung/openhr20.sqlite");
$db->query("PRAGMA synchronous=OFF");

//$fp=fsockopen("192.168.62.230",3531);
//$fp=fopen("php://stdin","r"); 
$fp=fopen("/dev/openhr20","w+"); 

//while(($line=stream_get_line($fp,256,PHP_EOL))!=FALSE) {

$addr=-1;
$trans=false;
$locked = [];

echo " <Starting>.." . PHP_EOL;
sendRTC($fp);
while(($line=fgets($fp,256))!==FALSE) {
	$line=trim($line);
	if ($line == "") {
		continue; // ignore empty lines
	}
	$debug=true;
	echo " < ".$line;
	$force=false;
	$ts=microtime(true);

	// preparation
	if ($line{0}=='(' && $line{3}==')') {
		// get address
		$addr = hexdec(substr($line,1,2));
		echo (isset($deviceMap[$addr]) ? ' ' . $deviceMap[$addr] : '') . PHP_EOL;
		$data = substr($line,4);
		if ($line{4}=='{') {
			// something interesting coming next
			if (!$trans) {
				$db->query("BEGIN TRANSACTION");
			}
			$trans=true;
		}

	} else if ($line{0}=='*') {
		// last send command for address success?
		$db->query("DELETE FROM command_queue WHERE id=(SELECT id FROM command_queue WHERE addr=$addr AND send>0 ORDER BY send LIMIT 1)");
		if ($result = $db->query("SELECT COUNT(id) as count FROM command_queue WHERE addr = $addr")) {
			if ($result && ($row = $result->fetchArray()) && 0 === $row['count']) {
				echo PHP_EOL . 'Unlock device ' . $addr . PHP_EOL;
				$message = ['addr' => $addr, 'synced' => true]; 
				$items = explode(' ', substr($line, 1));
				foreach ($items as $item) {
                                        switch ($item{0}) {
						case 'A':
							$message['mode'] = 'AUTO';
							break;
						case '-':
							$message['mode'] = '-';
							break;
						case 'M':
							$message['mode'] = 'MANU';
							break;
						case 'S':
							$message['wanted'] = (int)(substr($item,1)) / 100;
							break;
					}
				}
				mqttSend($addr, $message, $mqttRetainStat);
			}
		}
		$force=true;
		$data = substr($line,1);
	} else if ($line{0}=='-') { // incoming message?
		$data = substr($line,1);
	} else if ($line=='}') { 
		if ($trans) {
			$db->query("COMMIT TRANSACTION");
		}
		$trans=false;
		$data = substr($line,1);
		$addr=0;
	} else {
		$addr=0;
	}
	echo PHP_EOL;

	// What's up?
	if ($line=="RTC?") { // RTC requested
		sendRTC($fp);
		$debug=false;
	} else if (($line=="OK") || (($line{0}=='d') && ($line{2}==' '))) { // done or datetime
		$debug=false;
	} else if (($line=="N0?") || ($line=="N1?")) { // Sync packages
		$req = array(0,0,0,0);
		if ($result = $db->query("SELECT addr,count(*) AS c FROM command_queue GROUP BY addr ORDER BY c")) {
			$v = "O0000" . PHP_EOL;
			$pr = 0;
			while ($row = $result->fetchArray()) {
				$addr = $row['addr'];
				if (($addr>0) && ($addr<30)) {
					unset($v);
					if (($line=="N1?")&&($row['c']>20)) {
						$v=sprintf("O%02x%02x" . PHP_EOL, $addr, $pr);
						$pr=$addr;
						continue;
					}
					$req[(int)$addr/8] |= (int)pow(2,($addr%8));
				}
			}
		}
		if (!isset($v)) {
			$v = sprintf("P%02x%02x%02x%02x" . PHP_EOL, $req[0], $req[1], $req[2], $req[3]);
		}
		echo 'Sync: '. $v;
		fwrite($fp,$v);
		//fwrite($fp,"P14000000" . PHP_EOL);
		$debug=false;
	} else {
		if ($addr>0) {
			if ($data{0}=='?') { // send command
				$debug=false;
				// echo "data req addr $addr" . PHP_EOL;
				$cTrans = $db->query("BEGIN TRANSACTION");
				if ($result = $db->query("SELECT id,data FROM command_queue WHERE addr=".($addr&0x7f)." ORDER BY time LIMIT 20")) {
					$weight=0;
					$bank=0;
					$send=0;
					$q='';
					while ($row = $result->fetchArray()) {
						$cw = weights($row['data']{0});
						$weight += $cw;
						weights($row['data']{0});
						if ($weight>10) {
							if (++$bank>=7) break;
							$weight=$cw;
						}
						$r = sprintf("(%02x-%x)%s" . PHP_EOL, $addr, $bank, $row['data']);
						$q.=$r;
						echo $r;
						$send++;
						$db->query("UPDATE command_queue SET send=$send WHERE id=".$row['id']);
						echo 'Send Command: ' . $q . PHP_EOL;
						echo 'Lock device ' . $addr. PHP_EOL;
						mqttSend($addr, ['addr' => $addr, 'synced' => false], $mqttRetainStat);
					}
					fwrite($fp,$q);
				}
				if ($cTrans) {
					$db->query("COMMIT");
				}
				//$debug=false;
			} else if (strlen($data) >= 5 && $data{1}=='[' && $data{4}==']' && $data{5}=='=') { // settings, timers usw.
				$idx=hexdec(substr($data,2,2));
				$value=hexdec(substr($data,6));
				switch ($data{0}) {
				case 'G':
				case 'S':
					$table='eeprom';
					break;
				case 'R':
				case 'W':
					$table='timers';
					break;
				case 'T': // Debugs
					$table='trace';
					break;
				default:
					$table=null;
				}
				echo " table $table" . PHP_EOL;
				if ($table!==null) {
					$db->query("UPDATE $table SET time=".time().",value=$value WHERE addr=$addr AND idx=$idx");
					$changes=$db->changes();
					if ($changes==0) {
						$db->query("INSERT INTO $table (time,addr,idx,value) VALUES (".time().",$addr,$idx,$value)");
					}				}
			} else if ($data{0}=='V') {
				$db->query("UPDATE versions SET time=".time().",data='$data' WHERE addr=$addr");
				$changes=$db->changes();
				if ($changes==0) {
					$db->query("INSERT INTO versions (addr,time,data) VALUES ($addr,".time().",'$data')");
				}
			} else if (($data{0}=='D'||$data{0}=='A') && $data{1}==' ') {
				$items = explode(' ',$data);
				unset($items[0]);
				$t=0;
				$st=array();
				$mqttMessage = ['addr' => $addr, 'window' => 'closed', 'force' => false];
				foreach ($items as $item) {
					switch ($item{0}) {
					case 'm':
						$t+=60*(int)(substr($item,1));
						break;
					case 's':
						$t+=(int)(substr($item,1));
						break;
					case 'A':
						$st['mode']='AUTO';
						$mqttMessage['mode'] = 'AUTO';
						break;
					case '-':
						$st['mode']='-';
						$mqttMessage['mode'] = '-';
						break;
					case 'M':
						$st['mode']='MANU';
						$mqttMessage['mode'] = 'MANU';
						break;
					case 'V':
						$st['valve']=(int)(substr($item,1));
						$mqttMessage['valve'] = $st['valve'];
						break;
					case 'I':
						$st['real']=(int)(substr($item,1));
						$mqttMessage['real'] = $st['real'] / 100;
						break;
					case 'S':
						$st['wanted']=(int)(substr($item,1));
						$mqttMessage['wanted'] = $st['wanted'] / 100;
						break;
					case 'B':
						$st['battery']=(int)(substr($item,1));
						$mqttMessage['battery'] = $st['battery'] / 1000;
						break;
					case 'E':
						$st['error']=hexdec(substr($item,1));
						$mqttMessage['error'] = $st['error'];
						break;
					case 'W':
						$st['window']=1;
						$mqttMessage['window'] = 'open';
						break;
					case 'X':
						$st['force']=1;
						$mqttMessage['force'] = true;
						break;
					}
					if ($force) {
						$st['force']=1;
						$mqttMessage['force'] = true;
					}
				}
				$vars=""; $val="";
				foreach ($st as $k=>$v) {
					$vars.=",".$k;
					if (is_int($v)) {
						$val.=",".$v;
					} else {
						$val.=",'".$v."'";
					}
				}
				$time = time();
				if (($time % 3600)<$t) {
					$time-=3600;
				}
				$time = (int)($time/3600)*3600+$t;
				$db->query("INSERT INTO log (time,addr$vars) VALUES ($time,$addr$val)");
				$mqttMessage['time'] = $time;
				if ( 
					($result = $db->query("SELECT COUNT(id) as count FROM command_queue WHERE addr = $addr")) && 
					($row = $result->fetchArray())
				) {
					$mqttMessage['synced'] = 0 === $row['count'];
				}
				mqttSend($addr, $mqttMessage, $mqttRetainStat);
				updateRrd($addr, $st, $time);
			}
		}
	}


	if ($debug) { //debug log
		echo $line . PHP_EOL; 
		$db->query("INSERT INTO debug_log (time,addr,data) VALUES (".time().",$addr,\"$line\")");
		$deleteThld = $db->lastInsertRowid()-$maxDebugLines;
		$db->query("DELETE FROM debug_log WHERE id<$deleteThld");	
	}
	// echo "         duration ".(microtime(true)-$ts) . PHP_EOL;
} 
echo " <STOPPED>";
