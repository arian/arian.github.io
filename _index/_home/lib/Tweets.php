<?php

require __DIR__ . '/tmhOAuth/tmhOAuth.php';
require __DIR__ . '/tmhOAuth/tmhUtilities.php';

define('TWEETS_CACHE_TIME', 3600 * 6);

class Tweets {

	protected $file;
	protected $config;

	public function __construct(array $config){
		$this->file = __DIR__ . '/../tmp/tweets.json';
		$this->config = $config;
	}

	public function getTweets(){

		if (!file_exists($this->file) || time() - @filemtime($this->file) > TWEETS_CACHE_TIME){
			return $this->fetchFromAPI();
		}

		return $this->fetchFromCache();

	}

	protected function fetchFromAPI(){

		$tmhOAuth = new tmhOAuth($this->config);

		$code = $tmhOAuth->request('GET', $tmhOAuth->url('1/statuses/user_timeline', 'json'));

		if ($code == 200){
			$response = $tmhOAuth->response['response'];
			$this->saveToCache($response);
			return json_decode($response, true);
		}

		return array();
	}

	protected function fetchFromCache(){
		return json_decode(file_get_contents($this->file), true);
	}

	protected function saveToCache($json){
		file_put_contents($this->file, $json);
	}

}
