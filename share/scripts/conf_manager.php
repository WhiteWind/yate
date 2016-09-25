#!/usr/bin/php -q
<?php

require_once("libyate.php");

$rooms = array();

class Room {
    public $name;
    public $legs = array();
    public $peers = array();
    public $recorder;
    public $recorderleg;
    public $state;

    function __construct($m)
    {
	$this->name = $m->params['room'];
	$this->state = 'speak';
    }
    
    function __destruct()
    {
	Yate::Debug('conf_manager.php: room '.$this->name.' terminated');
    }
    
    static function process($m)
    {
	global $rooms;
	$name = $m->params['room'];
	if (!isset($rooms[$name])) {
	    $rooms[$name] = new Room($m);
	}
	$room = $rooms[$name];
	switch ($m->params['event']) {
	    case 'created':
		break;
	    case 'recording':
		$room->recorderleg = $m->params['id'];
		break;
	    case 'joined':
		$room->joined($m->params['id'], $m->params['users']);
		break;
	    case 'left':
		$room->leave($m->params['id'], $m->params['users']);
		break;
	    case 'destroyed':
		Yate::Debug("conf_manager.php: room ".$m->params['room']," destroyed");
		if (isset($rooms[$m->params['room']]))
		    unset($rooms[$m->params['room']]);
		break;
	}
    }
    
    static function chanMsg($m)
    {
	global $rooms;
	if ($m->name == 'chan.connected') {
	    if (!isset($rooms[$m->params['address']])) {
		Yate::Output('conf_manager.php: got '.$m->name.' for unknown room '. $m->params['address']);
		return;
	    }
	    $room = $rooms[$m->params['address']];
	    $room->connected($m->params['id'], $m->params['peerid']);
	}
	else if ($m->name == 'chan.hangup') {
	    Yate::Debug("conf_manager.php: got chan.hangup");
	    foreach ($rooms as $key => $room) {
		$room->forgetChan($m->params['id']);
		if ($room->state == 'terminating')
		    unset($rooms[$key]);
	    }
	}
    }
    
    function activeLegs()
    {
	$i = 0;
	foreach ($this->legs as $leg)
	    if ($leg == 'active')
		$i++;
	return $i;
    }
    
    function connected($id, $peerid)
    {
        if ($this->recorderleg == $id) {
            $this->recorder = $peerid;
            Yate::Debug("conf_manager.php: recorder connected: $peerid as $id");
        } else {
            $old_key = array_search($id, $this->peers);
            if ($old_key) {
                Yate::Debug("conf_manager.php: Warning: leg $id: replaced $old_key by $peerid");
                unset($this->peers[$old_key]);
            }
            $this->peers[$peerid] = $id;
        }
    }
    
    function joined($leg, $count)
    {
	if ($this->recorder == $leg)
	    return;
	$this->legs[$leg] = 'active';
	if ($count > 1)
	    $this->silence();
    }
    
    function leave($leg, $count)
    {
	unset($this->legs[$leg]);
	if ($count == 1)
	    $this->music();
    }
    
    function music()
    {
	if ($this->state == 'music' || $this->state == 'terminating')
	    return;
	$this->state = 'music';
	$m = new Yate("chan.locate");
	$m->params["id"] = $this->recorder;
	$m->Dispatch();
	
	$m = new Yate("chan.play");
	$m->params["message"] = "chan.attach";
	//$m = new Yate("chan.attach");
	$m->params["id"] = $this->recorder;
	$m->params["source"] = "moh/default";
	$m->params["notify"] = "confmgr";
	//$m->params["single"] = true;
	$m->params["room"] = $this->name;
	$m->Dispatch();
    }
    
    function silence()
    {
	if ($this->state == 'speak' || $this->state == 'terminating')
	    return;
	$this->state = 'speak';
	$m = new Yate("chan.play");
	$m->params["message"] = "chan.attach";
	$m->params["id"] = $this->recorder;
	$m->params["source"] = "tone/silence";
	$m->params["notify"] = "confmgr";
	//$m->params["single"] = true;
	$m->params["room"] = $this->name;
	$m->Dispatch();
    }
    
    function forgetChan($chan)
    {
        if (count($this->peers) <= 0) {
            Yate::Debug("conf_manager.php: Room $this->name is abandoned, removing");
            $this->state = 'terminating';
            $msg = new Yate('call.drop');
            $msg->params["id"] = "conf/".$this->name;
            $msg->params["reason"] = 'hangup';
            $msg->Dispatch();
        }
	if (isset($this->peers[$chan])) {
	    $leg = $this->peers[$chan];
	    Yate::Debug("conf_manager.php: Chan $chan hangup, forgetting it for room $this->name");
	    unset($this->peers[$chan]);
	    unset($this->legs[$leg]);
	    Yate::Debug("conf_manager.php: room $this->name has ".count($this->legs)."/".count($this->peers)." legs");
	    if (count($this->peers) <= 1) {
		Yate::Debug("conf_manager.php: terminating room $this->name");
		$this->state = 'terminating';
		$msg = new Yate('call.drop');
		$msg->params["id"] = "conf/".$this->name;
		$msg->params["reason"] = 'hangup';
		$msg->Dispatch();
	    }
	}
    }
}

Yate::Init();
Yate::Output(true);
Yate::Debug(true);

/* Set tracking name for all installed handlers */
Yate::SetLocal("trackparam","conf_manager.php");
//Yate::SetLocal("disconnected",true);
Yate::Install("chan.connected",35);
Yate::Install("chan.disconnected",35);
Yate::Install("chan.hangup",35);
Yate::Install("chan.notify",35);
Yate::Install("engine.command",35);

Yate::SetLocal("restart",true);

/* Create and dispatch an initial test message */
/* The main loop. We pick events and handle them */
for (;;) {
    $ev=Yate::GetEvent();
    /* If Yate disconnected us then exit cleanly */
    if ($ev === false)
        break;
    /* Empty events are normal in non-blocking operation.
       This is an opportunity to do idle tasks and check timers */
    if ($ev === true) {
        continue;
    }
    /* If we reached here we should have a valid object */
    switch ($ev->type) {
	case "incoming":
	    switch ($ev->name) {
		case "chan.notify":
		    if ($ev->params['targetid'] != 'confmgr')
			break;
		    if (isset($ev->params['room'])) {
		      Room::process($ev);
		    }
		    break;
		case "chan.connected":
		    if ($ev->params['module'] == 'conf')
			Room::chanMsg($ev);
		    break;
		case "chan.hangup":
		    //if ($m->params['module'] == 'conf')
			Room::chanMsg($ev);
		    break;
		case "engine.command": {
		    if ($ev->params['line'] == 'confmgr list') {
			$retval = '';
			foreach ($rooms as $room) {
			    $retval .= ("Room ".$room->name." recorder: ".$room->recorder." state: ".$room->state."\r\n");
			    foreach ($room->legs as $k => $v)
				$retval .= ("    $k => $v\r\n");
			    $retval .= ("Peers:\r\n");
			    foreach ($room->peers as $k => $v)
				$retval .= ("    $k => $v\r\n");
			}
			$ev->retval = $retval;
			$ev->handled = true;
		    }
		}
	    }
	    if ($ev)
		$ev->Acknowledge();
	    break;
	case "answer":
	    // Yate::Debug("PHP Answered: " . $ev->name . " id: " . $ev->id);
	    break;
	case "installed":
	    // Yate::Debug("PHP Installed: " . $ev->name);
	    break;
	case "uninstalled":
	    // Yate::Debug("PHP Uninstalled: " . $ev->name);
	    break;
	default:
	    Yate::Output("PHP Event: " . $ev->type);
    }
}

Yate::Output("PHP: bye!");

/* vi: set ts=8 sw=4 sts=4 noet: */
?>
