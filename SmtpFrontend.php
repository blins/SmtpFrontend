<?php
/**
 * @package Applications
 * @subpackage SmtpFrontend
 *
 * @author blins <blins@yandex.ru>
 */

class SmtpFrontend extends AsyncServer {

	public $sessions = array(); // Active sessions

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'     => 'tcp://0.0.0.0',
			// listen port
			'listenport' => 25,
                        //dump protocol
			'protologging' => true,
			// disabled by default
			'enable'     => 0
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			Daemon::log(__CLASS__ . ' up.');

			$this->bindSockets(
				$this->config->listen->value,
				$this->config->listenport->value
			);
		}
	}

	/**
	 * Called when new connection is accepted
	 * @param integer Connection's ID
	 * @param string Address of the connected peer
	 * @return void
	 */
	protected function onAccepted($connId, $addr) {
		$this->sessions[$connId] = new SmtpFrontendSession($connId, $this);
		$this->sessions[$connId]->addr = $addr;
	}

}

class SmtpFrontendSession extends SocketSession {

        //
        public $server_name = 'smtp.subdomains.domain.org';
        public $upstream = null;
        public $commands = array();
        public $CRLF = "\r\n";
        public $us_config = null;

        /**
	 * Called when the session constructed
	 * @return void
	 */
	public function init() {
            $this->reply(220, $this->server_name);
        }
	/**
	 * Send data and appending \n to connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function writeln($s) {
		return $this->write($s.$this->CRLF);
	}

        /**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		//$this->buf .= $buf;
                parent::stdin($buf);
		while (($line = $this->gets()) !== FALSE) {
			$cmd = trim($line);
                        if (!is_object($this->upstream)) {
                            array_push($this->commands, $cmd.$this->CRLF);
                            $cmd = strtolower($cmd);
                            $this->cmd($cmd);
                        } else {
                            $this->toUpstream($line);
                        }
		}
	}

        //parce cmd line and execute this until RCPT TO
        public function cmd($cmd) {
            $cmd = explode(' ', $cmd);
            if ($cmd[0] === 'quit') {
                $this->reply(221, $this->server_name.' closing connection');
                $this->finish();
                return;
            }
            if ($cmd[0] === 'helo') {
                array_shift($cmd);
                //bug: need fix
                array_push($this->commands, 'NOOP'.$this->CRLF);
                $this->reply(250, $this->server_name.' hello '.implode(' ', $cmd));
                return;
            }
            if ($cmd[0] === 'ehlo') {
                array_shift($cmd);
                //bug: need fix
                array_push($this->commands, 'NOOP'.$this->CRLF);
                $this->reply(250, $this->server_name.' hello '.implode(' ', $cmd));
                return;
            }
            if ($cmd[0] === 'mail'){
                $this->reply(250, 'OK');
                return;
            }
            if ($cmd[0] === 'noop'){
                $this->reply(250, 'OK');
                return;
            }
            if ($cmd[0] === 'rcpt'){
                $line = implode(' ', $cmd);
                if (preg_match('/<[^@:]+@([a-zA-Z0-9._-]+\.[a-zA-Z]+)>/', $line, $arr)){
                    $smtp_name = $arr[1];
                    $this->createUpstream($smtp_name);
                }
                if (!is_object($this->upstream)) {
                    $this->reply(550, 'no such user');
                }
                return;
            }

            $this->reply(500, 'Unknown command');
        }

        //reply to clients
        public function reply($code, $message){
            $this->writeln($code.' '.$message);
        }

        //write to far smtp host
        public function toUpstream($buf){
            return $this->upstream->write($buf);
        }

        public function createUpstream($upstream_name){
            Daemon::log('Start upstreaming '.$upstream_name);
            if (!isset($this->appInstance->config->$upstream_name)){
                return;
            }
            $us_config = $this->us_config = $this->appInstance->config->$upstream_name;

            Daemon::log('Creating '.$upstream_name.' address='.$us_config->address->value.' port='.$us_config->port->value);
            $connId = $this->appInstance->connectTo($us_config->address->value, $us_config->port->value);
            if ($connId){
                Daemon::log('Connection created');
            }

            $this->upstream = $this->appInstance->sessions[$connId] = new SmtpFrontendUpstreamSession($connId, $this->appInstance);
            $this->upstream->downstream = $this;

            Daemon::log(Debug::dump($this->commands));
            Daemon::log('---------------');

            $this->upstream->not_write = true;

        }

	public function onFinish() {
		if (is_object($this->upstream)){
                    $this->upstream->finish();
                }
                $this->upstream = null;
	}

        public function onFarReply($str){
            $p = stripos(implode($this->CRLF, $str), 'AUTH PLAIN LOGIN');
            if ($p !== false){
                //need authorization
                //adding command to stack
                array_unshift($this->commands, base64_encode($this->us_config->password->value).$this->CRLF);
                array_unshift($this->commands, base64_encode($this->us_config->login->value).$this->CRLF);
                array_unshift($this->commands, 'AUTH LOGIN'.$this->CRLF);
            Daemon::log(Debug::dump($this->commands));
            Daemon::log('---------------');
            }
            $ncmd = array_shift($this->commands);
            if (is_null($ncmd)) {
                //bug: return two last far replyes
                $this->writeln(implode($this->CRLF, $str));
            }
            return $ncmd;
        }
}

class SmtpFrontendUpstreamSession extends SocketSession {
    
        public $downstream;
        public $not_write;

        public function init() {
            $this->EOL="\r\n";
        }

        /**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		if ($this->appInstance->config->protologging->value) {
			//Daemon::log('SmtpProxy: Server --> Client: ' . Debug::exportBytes($buf) . "\n\n");
		}

		if(!$this->not_write) {
                    $this->downstream->write($buf);
                } else{
                    parent::stdin($buf);
                    //get multiply lines
                    $cmd = array();
                    while (($line = $this->gets()) !== FALSE) {
                            $cmd[] = trim($line);
                            Daemon::log($line);
                    }
                    if (count($cmd) > 0){
                        $next_cmd = $this->downstream->onFarReply($cmd);
                        Daemon::log($next_cmd);
                        if (!is_null($next_cmd)) {
                            $this->write($next_cmd);
                            //sleep(1);
                        } else {
                            $this->not_write = false;
                        }
                    }
                    
                }
	}

	/**
	 * Event of SocketSession (asyncServer).
	 * @return void
	 */
	public function onFinish() {
		$this->downstream->finish();
	}

}

?>
