<?php

define('REPOS_CACHE_TIME', 3600 * 6);

class GitHub {

	protected $url;
	protected $file;

	public function __construct(){
		$this->url = 'https://api.github.com/users/arian/repos?sort=pushed';
		$this->file = __DIR__ . '/../tmp/repos.json';
	}

	public function getRepos(){

		if (!file_exists($this->file) || (time() - @filemtime($this->file) > REPOS_CACHE_TIME)){
			return $this->fetchFromAPI();
		}

		return $this->fetchFromCache();
	}

	protected function fetchFromAPI(){

		$ch = curl_init($this->url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
		$json = curl_exec($ch);

		if (curl_errno($ch)){
			$json = null;
		}

		curl_close($ch);

		if ($json){
			$this->saveToCache($json);
			return json_decode($json, true);
		}
	}

	protected function fetchFromCache(){
		return json_decode(file_get_contents($this->file), true);
	}

	protected function saveToCache($json){
		file_put_contents($this->file, $json);
	}

}

