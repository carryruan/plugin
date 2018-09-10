<?php
/**
 * Created by SxdStorm.
 * User: yuemingzeng
 * Date: 2018/8/6
 * Time: 10:53
 */
include_once ROOT."Packet.php";

function test(){
	try{
//		$imobj = new imserverTest('127.0.0.1', 9501);
		$imobj = new imserverTest('172.16.10.244', 9501);
		return $imobj->Start();
	}catch (Exception $ex){
		return false;
	}
}

class imserverTest
{
	private $packet ='';
	private $ip;
	private $port;
	private $url;

	public function __construct($ip, $port, $url='')
	{
		$this->ip = $ip;
		$this->port = $port;
		$this->url = $url;
		$this->packet = new Packet();
	}

	public function Start() {
		$socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		@socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 5, "usec" => 0));
		@socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array("sec" => 5, "usec" => 0));
		@socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		@socket_connect($socket, $this->ip, $this->port);
		$this->client = $socket;
		$this->_login();

		@socket_close($this->client);
		$this->client = null;
		return true;
	}

	public function _login(){
		$rule =[
			"MainCmdID"=>109,
			"SubCmdID"=>1,
			"DataType"=>2,
			"TimeStamp"=>1,
			"ExtCmd"=>1
		];
		$uid = randAiUid();
		$array = array($uid, 1, time());
		$token = authcode(json_encode($array), 'ENCODE');
		$string = http_build_query(['token'=>$token,'code'=>'560640b8a6',
									'mcid'=>3000,'roomid'=>2
			]);
		$this->packet->writeBegin($string, $rule);
		$this->send($this->packet->buffer);
		$rev = $this->recv();

		$data = $this->packet->readBeginV2($rev);
		write("read.log",$data);
	}

	private function send($send_buff) {
		@socket_write($this->client, $send_buff, strlen($send_buff));
	}

	private function recv() {
		socket_recv($this->client, $buf, 1024 * 64, 0);
		return $buf;
	}


}

function authcode($string, $operation = 'DECODE', $key = '^#fa45a8sdf!', $expiry = 0) {
	// 动态密匙长度，相同的明文会生成不同密文就是依靠动态密匙
	$ckey_length = 4;
	// 密匙
	$key = md5($key);
	// 密匙a会参与加解密
	$keya = md5(substr($key, 0, 16));
	// 密匙b会用来做数据完整性验证
	$keyb = md5(substr($key, 16, 16));
	// 密匙c用于变化生成的密文
	$keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length):
		substr(md5(microtime()), -$ckey_length)) : '';
	// 参与运算的密匙
	$cryptkey = $keya.md5($keya.$keyc);
	$key_length = strlen($cryptkey);
	// 明文，前10位用来保存时间戳，解密时验证数据有效性，10到26位用来保存$keyb(密匙b)，
	//解密时会通过这个密匙验证数据完整性
	// 如果是解码的话，会从第$ckey_length位开始，因为密文前$ckey_length位保存 动态密匙，以保证解密正确
	$string = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) :
		sprintf('%010d', $expiry ? $expiry + time() : 0).substr(md5($string.$keyb), 0, 16).$string;
	$string_length = strlen($string);
	$result = '';
	$box = range(0, 255);
	$rndkey = array();
	// 产生密匙簿
	for($i = 0; $i <= 255; $i++) {
		$rndkey[$i] = ord($cryptkey[$i % $key_length]);
	}
	// 用固定的算法，打乱密匙簿，增加随机性，好像很复杂，实际上对并不会增加密文的强度
	for($j = $i = 0; $i < 256; $i++) {
		$j = ($j + $box[$i] + $rndkey[$i]) % 256;
		$tmp = $box[$i];
		$box[$i] = $box[$j];
		$box[$j] = $tmp;
	}
	// 核心加解密部分
	for($a = $j = $i = 0; $i < $string_length; $i++) {
		$a = ($a + 1) % 256;
		$j = ($j + $box[$a]) % 256;
		$tmp = $box[$a];
		$box[$a] = $box[$j];
		$box[$j] = $tmp;
		// 从密匙簿得出密匙进行异或，再转成字符
		$result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
	}
	if($operation == 'DECODE') {
		// 验证数据有效性，请看未加密明文的格式
		if((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0) &&
			substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16)) {
			return substr($result, 26);
		} else {
			return '';
		}
	} else {
		// 把动态密匙保存在密文里，这也是为什么同样的明文，生产不同密文后能解密的原因
		// 因为加密后的密文可能是一些特殊字符，复制过程可能会丢失，所以用base64编码
		return $keyc.str_replace('=', '', base64_encode($result));
	}
}

function randAiUid(){
	return rand(11000000,11002399);
}

function write($file, $content){
	$filename = ROOT.$file;
	is_scalar( $content ) or ($content = var_export( $content, true )); //是简单数据
	file_put_contents($filename, $content. "\n", FILE_APPEND);
}