#!/usr/bin/php -q
<?php
require_once("libyate.php");

Yate::Init();
Yate::Output(true);
Yate::Debug(true);

/* Set tracking name for all installed handlers */
Yate::SetLocal("trackparam","conf_record.php");
Yate::SetLocal("disconnected",true);
//Yate::Install("call.route",35);
//Yate::Install("call.cdr",110,"direction","outgoing");
Yate::Install("chan.connected",35);
Yate::Install("chan.hangup",35);
Yate::Install("chan.notify",35);
Yate::Install("chan.play",35);

//Yate::SetLocal("restart",true);

$filename = '';
$dir = '';
$outfile = '';
$caller = '';
$called = '';
$date = '';
$ourcallid = '';
$room = '';

for (;;) {
    $ev=Yate::GetEvent();
    if ($ev === false)
        break;
    if ($ev === true)
        continue;
    if ($ev->type == "incoming") {
	//Yate::Output("PHP got $ev->name with id " . $ev->params["id"]);
	switch ($ev->name) {
	    case "call.execute":
		//if ($ev->params)
		$room = $ev->params["room"];
		$ourcallid = "confrec/".$room;
		Yate::SetLocal("id", $ourcallid);
		$partycallid = $ev->GetValue("id");
		$ev->params["targetid"] = $ourcallid;
		$ev->handled = true;
		/* We must ACK this message before dispatching a call.answered */
		$ev->Acknowledge();
		
		$dir = "/var/records/".date("Y-m-d");
		mkdir($dir, 0755, true);
		$date = date("Y-m-d H:i:s");
		$caller = $ev->params["caller"];
		$called = $ev->params["called"];
		

		//$m = new Yate("call.answered");
		//$m->params["id"] = $ourcallid;
		//$m->params["targetid"] = $partycallid;
		//$m->Dispatch();

		$filename = "/tmp/conf_record-".$ev->params["billid"].".slin";
		$outfile = $ev->params["billid"];
		$m = new Yate("chan.attach");
		$m->params["consumer"] = "wave/record/".$filename;
		//$m->params["source"] = "moh/default";
		$m->params["notify"] = $ourcallid;
		$m->Dispatch();
		// we already ACKed this message
		$ev = false;
		break;
	    case "chan.notify":
		if ($ev->params["event"] == 'joined' || $ev->params["event"] == 'left')
		  Yate::Output("PHP got chan.notify for conf " . $ev->params["targetid"] . ". --- leg " . $ev->params["id"] . " " . $ev->params["event"]);
		if ($ev->params["targetid"] == $ourcallid) 
		    switch ($ev->params["event"]) {
			case 'destroyed':
			    /* We must ACK this message before dispatching a chan.hangup */
			    $ev->Acknowledge();
			    $ev = false;
			
			    $m = new Yate("chan.hangup");
			    $m->params["id"] = $ourcallid;
			    $m->params["driver"] = 'confrec';
			    $m->params["status"] = 'answered';
			    $m->params["direction"] = 'outgoing';
			    $m->Dispatch();
			    break;
		    }
		break;
            case "chan.play":
                if ($ev->params["id"] == $ourcallid) {
                    $m = new Yate($ev->params["message"]);
                    $m->params["source"] = $ev->params["source"];
                    $m->params["notify"] = $ev->params["notify"];
                    $m->params["single"] = $ev->params["single"];
                    $m->params["room"] = $ev->params["room"];
                    $m->Dispatch();
                }
                break;
	}
	if ($ev)
	    $ev->Acknowledge();
    }
}

exec("oggenc -r -B 16 -C 1 -R 8000 -d \"$date\" -t \"$caller\" -a \"$called\"  -o \"$dir/$outfile.ogg\" \"$filename\" > /tmp/$outfile.log 2>&1 < /dev/null &");
Yate::Debug("PHP confrec exited");
/* vi: set ts=8 sw=4 sts=4 noet: */
?>
