<?php
error_reporting(E_ALL & ~E_NOTICE);
ob_implicit_flush();
set_time_limit(0);
$room = new ChatRoom('192.168.1.113', 9999);
$room->run();
class ChatRoom {
	public $master;
	public $users;
	public $sockets;
	public $while_flag = 1;
	public $foreach_flag = 1;

	public function __construct($ip, $port) {
		$this->webSocket($ip, $port);
		$this->sockets = array('master' => $this->master);
	}

	/**
	 * 1.把所有的socket放在一个change数组里面，如果有变化执行使用socket_select选择之后执行foreach循环
	 * 2.首先判断是否为主进程master，如果是的话表示是刚开始接入第一个客户端，当有新用户进来的时候会==master
	 * 3.然后接受其他消息，先判断用户组里面是否已经握手
	 * @return [type] [description]
	 */
	public function run() {
		while (true) {
			echo "--------this is the $this->while_flag times WHILE circle!--------\n";
			echo "--------this->sockets----------\n";
			var_dump($this->sockets);
			echo "-------------------------------\n";
			echo "--------this->users----------\n";
			var_dump($this->users);
			echo "-------------------------------\n";

			$changes = $this->sockets; //放在一个变化数组里面等待其变化，是文件描述符集合
			// $changes[] = $this->master; //放在一个变化数组里面等待其变化，是文件描述符集合
			echo "#36-------------changes------------\n";
			var_dump($changes);
			echo "-------------------------------\n";
			$select_check = socket_select($changes, $write = NULL, $except = NULL, NULL); //socket_select函数可以等待套接字改变状态
			if (!$select_check) {
				$this->log("socket_select() failed ,reason:" . socket_strerror(socket_last_error()));
				die;
			}
			foreach ($changes as $k => $sock) {
				echo "--------this is the $this->foreach_flag times FOREACH circle!--------";
				echo "#46---------sock--------\n";
				var_dump($sock);
				echo "-------------------------------\n";
				echo "#49?????????sock==this->master??????????\n";
				var_dump($sock == $this->master);
				echo "-------------------------------\n";
				if ($sock == $this->master) {
					//如果请求来自监听端口的套接字，则创建一个接受下来创建一个新的套接字用来通信
					//每次新增一个用户的时候都走这一条
					$client = socket_accept($sock);
					echo "#56------------client------------\n";
					var_dump($client);
					echo "-------------------------------\n";
					$this->sockets[] = $client;
					$this->users[] = array(
						'socket' => $client,
						'is_hand_shake' => false,
					);
				} else {
					$searched_key = $this->searchUser($sock); //寻找用户组是否已经存在该socket，如果不存在创建，如果存在选取k
					if ($searched_key !== false) {
						$k = $searched_key;
					}
					echo "#69-------------searched_key and k--------------\n";
					var_dump($searched_key);
					echo "\n";
					var_dump($k);
					echo "-------------------------------\n";

					echo "#75-------------0  buffer-----------\n";
					var_dump($buffer);
					echo "-------------------------------\n";

					$recv_bytes = socket_recv($sock, $buffer, 4096, 0);
					if (!$recv_bytes) {
						$this->log("socket_recv() failed ,reason:" . socket_strerror(socket_last_error($sock)));
					}

					echo "#84------------1  buffer-----------\n";
					var_dump($buffer);
					echo "-------------------------------\n";

					echo "#75-------------recv_bytes--------------\n";
					var_dump($recv_bytes);
					echo "-------------------------------\n";
					$this->log("socket_recv() the recv_bytes : $recv_bytes");
					if ($recv_bytes < 0 || $recv_bytes < 7) {
//TODO为什么是7？
						//接收到的消息字节为零
						$this->close($sock);
						$this->dealMsg($k, 'type=remove');
						// continue;
					} else {
						//接受到了消息
						if (!$this->users[$k]['is_hand_shake']) {
							//没有握手去握手
							echo "#102---------握手 buffer--------\n";
							var_dump($buffer);
							echo "-------------------------------\n";
							$ret = $this->handShake($k, $buffer);
							if (!$ret) {
								die('handShake failed');
							}
						} else {
							//握手之后解码消息
							echo "#111---------消息 buffer--------\n";
							var_dump($buffer);
							echo "-------------------------------\n";
							$ret = $this->decode($buffer);
							echo "#115--------解码 ret--------\n";
							var_dump($ret);
							echo "-------------------------------\n";
							if ($ret) {
								$this->dealMsg($k, $ret);
							} else {
								// $this->close($sock);
								// die('decode msg failed');
								$this->log('why decode msg ??? ' . $ret);
							}
						}
					}
				}
				$this->foreach_flag++;
			}
			$this->while_flag++;
		}
	}

	/**
	 * webSocket方法，创建webSocket
	 */
	public function webSocket($ip, $port) {
		if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) < 0) {
			$this->log("socket_create() 失败的原因是:" . socket_strerror($sock));
		}

		if (($ret = socket_bind($sock, $ip, $port)) < 0) {
			$this->log("socket_bind() 失败的原因是:" . socket_strerror($ret));
		}

		if (($ret = socket_listen($sock, 4)) < 0) {
			$this->log("socket_listen() 失败的原因是:" . socket_strerror($ret));
		}
		$this->master = $sock;
	}

	/**
	 * handShake方法，用于握手实现协议升级
	 */
	public function handShake($k, $agreement) {
		$buffer = substr($agreement, strpos($agreement, 'Sec-WebSocket-Key:') + 18);
		$key = trim(substr($buffer, 0, strpos($buffer, "\r\n")));

		$new_key = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

		$new_agreement = "HTTP/1.1 101 Switching Protocols\r\n";
		$new_agreement .= "Upgrade: websocket\r\n";
		$new_agreement .= "Sec-WebSocket-Version: 13\r\n";
		$new_agreement .= "Connection: Upgrade\r\n";
		$new_agreement .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";

		$write_check = socket_write($this->users[$k]['socket'], $new_agreement, strlen($new_agreement));
		if (!$write_check) {
			$this->log("socket_write() (@handShake()) failed ,reason : " . socket_strerror(socket_last_error($this->users[$k]['socket'])));
			return false;
		}
		$this->users[$k]['is_hand_shake'] = true;
		return true;
	}

	/**
	 * 寻找用户组方法
	 */
	public function searchUser($sock) {
		foreach ($this->users as $k => $v) {
			if ($sock == $v['socket']) {
				return $k;
			}
		}
		return false;
	}

	/**
	 * 消息解码方法
	 */
	public function decode($buffer) {
		$mask = array();
		$data = '';
		$msg = unpack('H*', $buffer);
		$this->log('@decode() unpacked msg:' . $msg);
		$head = substr($msg[1], 0, 2);
		$this->log('@decode() substr head:' . $head);
		$this->log('@decode() hexdec($head{1}):' . hexdec($head{1}) . 'this is $head{1}' . $head{1});
		if (hexdec($head{1}) === 8) {
			$data = false;
		} else if (hexdec($head{1}) === 1) {
			$mask[] = hexdec(substr($msg[1], 4, 2));
			$mask[] = hexdec(substr($msg[1], 6, 2));
			$mask[] = hexdec(substr($msg[1], 8, 2));
			$mask[] = hexdec(substr($msg[1], 10, 2));
			$this->log('@decode() $mask is :' . json_encode($mask));
			$s = 12;
			$e = strlen($msg[1]) - 2;
			$n = 0;
			for ($i = $s; $i <= $e; $i += 2) {
				$data .= chr($mask[$n % 4] ^ hexdec(substr($msg[1], $i, 2)));
				$n++;
			}
		}
		return $data;
		//
		//
		//从http://stackoverflow.com/questions/7945040/html5-websocket-with-hybi-17找到的
		//
		//
		// $len = $masks = $data = $decoded = null;

		// $len = ord($buffer[1]) & 127;

		// if ($len === 126) {
		// 	$masks = substr($buffer, 4, 4);
		// 	$data = substr($buffer, 8);
		// } else if ($len === 127) {
		// 	$masks = substr($buffer, 10, 4);
		// 	$data = substr($buffer, 14);
		// } else {
		// 	$masks = substr($buffer, 2, 4);
		// 	$data = substr($buffer, 6);
		// }

		// for ($index = 0; $index < strlen($data); $index++) {
		// 	$decoded .= $data[$index] ^ $masks[$index % 4];
		// }
		// return $decoded;
	}

	/**
	 * 消息编码方法
	 */
	public function encode($msg) {
		$msg = preg_replace(array('/\r$/', '/\n$/', '/\r\n$/'), '', $msg);
		$this->log('@encode() preg_replace msg : ' . $msg);
		$frame = array();
		$frame[0] = '81';
		$len = strlen($msg);
		$this->log('@encode() msg length :' . $len . 'and msg dechex(length):' . dechex($len));
		$frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
		$frame[2] = $this->ordHex($msg);
		$this->log('@encode() frame  :' . json_encode($frame));
		$data = implode('', $frame);
		return pack("H*", $data);
	}

	public function ordHex($data) {
		$msg = '';
		$l = strlen($data);
		for ($i = 0; $i < $l; $i++) {
			$msg .= dechex(ord($data{$i}));
		}
		return $msg;
	}

	/**
	 * 获取所有用户的方法
	 */
	public function getUsers() {
		$ar = array();
		foreach ($this->users as $k => $v) {
			$ar[$k] = array(
				'name' => $v['name'],
				'head' => $v['head'],
			); //这里不可以，否则会出现A server must not mask any frames that it sends to the client.的错误
			// $ar[$k] = $v['name'];
		}
		return $ar;
	}

	/**
	 * 统一发送消息的格式
	 */
	public function dealMsg($k, $msg) {
		parse_str($msg, $msgArr);
		$this->log('@dealMsg() parse_str msg');
		$retArr = array();
		if ($msgArr['type'] == 'add') {
			$this->users[$k]['name'] = $msgArr['name'] == NULL ? '匿名用户' . $k : $msgArr['name'];
			$this->users[$k]['head'] = $msgArr['head'];
			$retArr['type'] = 'add';
			$retArr['content'] = $this->users[$k]['name'];
			// $retArr['head'] = $this->users[$k]['head'];
			// $retArr['k'] = $k;
			$retArr['users'] = $this->getUsers();
			$to = 'all';
		} else if ($msgArr['type'] == 'talk') {
			$retArr['type'] == 'talk';
			$retArr['content'] = $msgArr['content'];
			$retArr['from'] = $msgArr['from'];
			$retArr['to'] = $msgArr['to'];
			$to = $msgArr['to'];
		} else {
			$retArr['type'] == 'remove';
			$retArr['removekey'] = $k;
			$retArr['content'] = $this->users[$k]['name'] . '退出聊天室';
			$to = 'all';
		}
		$msg = $this->encode(json_encode($retArr));
		$this->log('@dealMsg() final msg : ' . $msg);
		$this->sendMsg($k, $to, $msg);
	}

	/**
	 * 向socket写入消息方法
	 */
	public function sendMsg($from, $to, $str) {
		if ($to == null) {
			$to = 'all';
		}
		if ($to == 'all') {
			foreach ($this->users as $v) {
				$write_check = socket_write($v['socket'], $str, strlen($str));
				if (!$write_check) {
					$this->log('@sendMsg() socket_write(to == all) failed ,reason : ' . socket_strerror(socket_last_error($v['socket'])));
				}
			}
		} else {
			if ($from != $to) {
				$write_check = socket_write($this->users[$to]['socket'], $str, strlen($str));
				if (!$write_check) {
					$this->log('@sendMsg() socket_write(from--' . $from . '--to--' . $all . ') failed ,reason : ' . socket_strerror(socket_last_error($this->users[$to]['socket'])));
				}
			}
			$write_check = socket_write($this->users[$from]['socket'], $str, strlen($str));
			if (!$write_check) {
				$this->log('@sendMsg() socket_write(from--' . $from . '--to--' . $all . ') failed ,reason : ' . socket_strerror(socket_last_error($this->users[$from]['socket'])));
			}
		}
	}

	/**
	 * 关闭一个socket链接
	 */
	public function close($sock) {
		$k = array_search($sock, $this->sockets);
		socket_close($sock);
		$head = $this->users[$k]['head'];
		unset($this->sockets[$k]);
		unset($this->users[$k]);
		$this->log("key:$k closed");
		$this->log('head::::::::' . $head);
		//TODO $this->delDir($head);
	}

	/**
	 * log方法，记录日志
	 */
	public function log($log_data) {
		$fd = fopen('./log/' . date('Ym') . '.log', "a");
		$str = "[#@" . date("Y/m/d h:i:s", time()) . "#]------" . $log_data;
		fwrite($fd, $str . "\n");
		fclose($fd);
	}

	/**
	 * 删除用户头像
	 */
	function delDir($dir) {
		$dh = opendir($dir);
		while ($file = readdir($dh)) {
			if ($file != "." && $file != "..") {
				$fullpath = $dir . "/" . $file;
				if (!is_dir($fullpath)) {
					unlink($fullpath);
				} else {
					deldir($fullpath);
				}
			}
		}
		closedir($dh);
	}
}
?>