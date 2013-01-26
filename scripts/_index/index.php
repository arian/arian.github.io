<?php

include_once '_home/lib/Tweets.php';
include_once '_home/lib/GitHub.php';
include_once '_home/lib/Template.php';

$template = new Template;

$tweets = new Tweets(require '_home/twitterAPISecret.php');
$template->tweets = $tweets->getTweets();

$gh = new GitHub;
$template->repos = $gh->getRepos();

echo $template->render('_home/templates/index.php');

?>
