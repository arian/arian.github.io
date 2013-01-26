<?php

class Template implements Iterator, Countable {

	protected $params = array();

	public function render($tpl_file, $params=array()){
		$this->params = array_merge($this->params,$params);
		ob_start();
        include $tpl_file;
		return ob_get_clean();
	}

	public function __set($key,$value){
		$this->params[$key] = $value;
	}

	public function __get($key){
		if (isset($this->params[$key])){
			return $this->params[$key];
		}
		trigger_error("This variable doesn't exist",E_USER_NOTICE);
	}

	public function __isset($key){
		return isset($this->params[$key]);
	}

	public function count(){
		return count($this->params);
	}

	public function rewind(){
		reset($this->params);
	}

	public function current(){
		return current($this->params);
	}

	public function key(){
		return key($this->params);
	}

	public function next(){
		return next($this->params);
	}

	public function valid(){
		return $this->current() !== false;
	}

}

