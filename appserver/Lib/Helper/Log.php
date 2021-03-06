<?php

namespace Zengym\Lib\Helper;
use Zengym\Lib\Protocols\UdpWritePackage;

class Log{
	
	public static function Log($fname, $fcontent, $file_append = true,$err=1) {
		
		clearstatcache();
		$file = SWOOLE_ROOT . '/data/' . $fname .  '.php';
		$dir = dirname($file);
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		$prefix_header = "<?php (isset(\$_GET['p']) && (md5('&%$#'.\$_GET['p'].'**^')==='8b1b0c76f5190f98b1110e8fc4902bfa')) or die();?>\n";
		if ($file_append) {
			$size = file_exists($file) ? filesize($file) : 0;
			$flag = $size < 1 * 1024 * 1024; //标志是否附加文件.文件控制在1M大小
			$prefix = $size && $flag ? '' : $prefix_header; //有文件内容并且非附加写
			file_put_contents($file, $prefix . $fcontent . "\n", $flag ? FILE_APPEND : null );
		} else {
			file_put_contents($file, $prefix_header . $fcontent . "\n", null);
		}
	}
	
	public static function processEnd() {
		//debug_backtrace();
		$error = error_get_last();
		if ($error) {
			$error['date'] = date('Ymd H:i:s', time());
			self::Log('run_error', var_export($error, true));
		}
	}
	
	/**
	 * 输出调试日志
	 * @param String/Array $params 要记录的数据
	 * @param String $fname 文件名.该记录会保存到 data 目录下
	 * @param Int $fsize 文件大小M为单位.默认为1M
	 * @param Bool $isudplog 是否通过udp记录日志，如果要记录IP，此参数传字符'ip'
	 * @return null
	 */
	public static function debug($params, $fname = '', $fsize = 1, $isudplog = true){
		if(!$params){
			return false;
		}
		$d = (array)debug_backtrace();
		if(isset($d[1]) && $d[1]){
			if($d[1] && $d[1]['function'] && ($d[1]['class'] === 'ModelLogs' && $d[1]['function'] === 'debug' || $d[1]['function'] === 'debugDemo')){//兼容老方法logs.php:debug
				$d[0] = $d[1];
				$d[1] = $d[2];
			}
			if($d[1] && $d[1]['function']){//方法中调用
				if($dir = $d[1]['class']){//类中的方法
					$dir = str_replace('\\', '_', $dir);
				}else{//普通方法
					$dir = 'debug';
				}
				$fn = $d[1]['function'];
				$line = $d[0]['line'];
				if(!$fname){
					$fname = $dir . '/' . ($fname ? $fname : $fn); //自动按类进行分子目录
					$prefix = '[' . $fn . ':' . $line . "]\t";
				}else if($d[1]['class']){
					$prefix = '[' . $d[1]['class'] . $d[1]['type'] . $fn . ':' . $line . "]\t";
				}
			}
		}else{//文件直接调用
			$file = str_replace(SWOOLE_ROOT, '', $d[0]['file']);
			$prefix = '[' . $file . ':' . $d[0]['line'] . "]\t";
			if(!$fname){
				$fname = 'debug_' . str_replace('.php', '', $file);
			}
		}
		if(!$fname || !is_string($fname)){
			return false;
		}
		if(!is_scalar($params)){
			$params = self::_var_export($params);
		}
		
		$params = $prefix . $params;
		$ext = [];
		$ext['bak'] = $isudplog === 'bak';
		if(!$isudplog){//非本机走udp模式
			return self::swooleDebug($params, $fname, $fsize, $ext);
		}else{
			return self::debugWrite($params, $fname, $fsize, $ext);
		}
	}
	
	
	/**
	 * 获取堆栈信息,排除掉一些无用信息，如swoole启相关的
	 * @return string
	 */
	public static function debug_backtrace(){
		$aTrc = debug_backtrace();
		$sErrMsg = '';
		foreach((array)$aTrc as $k => $v){
			if($v['function'] == 'onTask'){
				continue;
			}
			if($v['function'] == 'debug_backtrace'){
				continue;
			}
			if($v['class'] == 'swoole_server'){
				continue;
			}
			if(isset($v['args'][4]['GLOBALS'])){//var_export does not handle circular references 错误处理
				unset($v['args'][4]);
			}
			$sErrMsg .= $k . '-file:' . $v['file'] . '; line:' . $v['line'] . '; function:' . $v['function'] . '; args:' . var_export((array)$v['args'], true) . "\n";
		}
		return $sErrMsg;
	}
	
	/**
	 * 执行日志写入文件，udp执行也会调用
	 * @param string $params
	 * @param string $fname
	 * @param int $fsize
	 * @param array $ext
	 * @return int
	 */
	public static function debugWrite($params, $fname, $fsize, $ext = []){
		clearstatcache();
		$file = PATH_DAT . $fname . '.php';
		$dir = dirname($file);
		if(!is_dir($dir)){
			mkdir($dir, 0775, true);
		}
		$size = file_exists($file) ? @filesize($file) : 0;
		$flag = $size < max(1, $fsize) * 1024 * 1024; //标志是否附加文件.文件控制在1M大小
		if(!$flag && $ext['bak']){//文件超过大小自动备份
			$bak = $dir . '/bak/';
			if(!is_dir($bak)){
				mkdir($bak, 0775, true);
			}
			$fname = explode('/', $fname);
			$fname = $fname[count($fname) - 1];
			$bak .= $fname . '-' . self::date('YmdHis') . '.php';
			copy($file, $bak);
		}
		$prefix = $size && $flag ? '' : "<?php (isset(\$_GET['p']) && (md5('&%$#'.\$_GET['p'].'**^')==='8b1b0c76f5190f98b1110e8fc4902bfa')) or die();?>\n";
		$prefix .= '[' . date('Y-m-d H:i:s') . "]\t";
		if(isset($ext['ip']) && $ext['ip']){
			$prefix .= '[' . $ext['ip'] . "]\t";
		}
		return file_put_contents($file, $prefix . $params . "\n", $flag ? FILE_APPEND : null);
	}
	
	/**
	 * @desc 数据格式化
	 * @param unknown $var
	 * @param string $prefix
	 * @param string $init
	 * @param number $count
	 * @return string|mixed
	 */
	private static function _var_export($var, $prefix = '', $init = true, $count = 0){
		if($count > 3){
			return '...';
		}
		
		if(is_object($var)){
			$output = method_exists($var, 'export') ? $var->export() : self::_var_export((array)$var, '', false, $count + 1);
		}else if(is_array($var)){
			if(empty($var)){
				$output = 'array()';
			}else{
				$output = "array(\n";
				foreach($var as $key => $value){
					$output .= "  " . var_export($key, true) . " => " . self::_var_export($value, '  ', false, $count + 1) . ",\n";
				}
				$output .= ')';
			}
		}else if(is_bool($var)){
			$output = $var ? 'true' : 'false';
		}else if(is_int($var)){
			$output = intval($var);
		}else if(is_numeric($var)){
			$floatval = floatval($var);
			if(is_string($var) && ((string)$floatval !== $var)){
				$output = var_export($var, true);
			}else{
				$output = $floatval;
			}
		}else if(is_string($var) && strpos($var, "\n") !== false){
			$var = str_replace("\n", "***BREAK***", $var);
			$output = var_export($var, true);
		}else{
			$output = var_export($var, true);
		}
		
		if($prefix){
			$output = str_replace("\n", "\n$prefix", $output);
		}
		
		if($init){
			$output = str_replace("***BREAK***", "\n", $output);
		}
		
		return $output;
	}
	
	
	/**
	 * 新版udp-log
	 * @param string $params
	 * @param int $fname
	 * @param int $fsize
	 * @param array $ext
	 * @return boolean
	 */
	private static function swooleDebug($params, $fname, $fsize = 1, $ext = []){
		if(!$params || !is_string($params)){
			return;
		}
		
		//长度超过8000进行截取(因为udp包 包含命令号，文件名字等等，除去大概300长度)
		if(strlen($params) > 7700){
			$params = substr($params, 0, 7700) . '该Log日志已超过8k，已被截取';
		}
		
		self::_sendDataToUdpServer($params, $fname, $fsize, $ext);
	}
	
	/**
	 * 将日志发送到udp server服务
	 * @param string $params
	 * @param string $fname
	 * @param number $fsize
	 * @param array $ext
	 * @return boolean
	 */
	private static function _sendDataToUdpServer($params, $fname, $fsize = 1, $ext = []){
		if(!$params){
			return false;
		}
		if(!$content = self::_getUdpContent($fname, $fsize, $ext, $params)){
			$content = self::_getUdpContent($fname, $fsize, $ext, '该Log日志已超过8k，记录失败！');
		}
		$updLogServer = self::logServerSwitch();
		$sSocket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		socket_sendto($sSocket, $content, strlen($content), 0, $updLogServer['ip'], $updLogServer['port']);
	}
	
	/**
	 * 获取udp内容，解包逻辑：crontab/Swoole/Apps/Log/LogDebugHandle.php
	 * @param string $fname
	 * @param int $fsize
	 * @param array $ext
	 * @param string $params
	 * @return bite
	 */
	private static function _getUdpContent($fname, $fsize, $ext, $params){
		$udp = new UdpWritePackage();
		$udp->WriteBegin(0x0100);
		$udp->WriteString($fname);
		$udp->WriteInt($fsize);
		$udp->WriteByte($ext['bak'] ? 1 : 0);
		$udp->WriteByte(1);
		$udp->WriteString($params);
		return $udp->WriteEnd();
	}
	
	/**
	 * @desc upd log server切换
	 * @return string[]|number[]|mixed[]
	 */
	private static function logServerSwitch(){
		return array('ip' => "127.0.0.1", 'port' => 9852);
	}
}
