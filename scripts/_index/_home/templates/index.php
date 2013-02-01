
<div class="twitter">
	<h1>
		<i class="icon-twitter"></i>
		What I&#39;ve got to say <a href="http://twitter.com/astolwijk">@astolwijk</a>
	</h1>
	<div id="twitter">
		<?php if (empty($this->tweets)): ?>
		<ul><?php $i = 0; foreach ($this->tweets as $tweet): if ($i++ > 4){ break; } ?>

			<li><a href="http://twitter.com/astolwijk/status/<?php echo $tweet['id_str'] ?>"><?php echo $tweet['text']; ?></a></li><?php
			endforeach; ?>

		</ul>
		<?php else: ?>
		<p>Nothing too much, probably twitter is failing :)</p>
		<?php endif ?>
	</div>
</div>

<div class="about">
	<h1>
		<i class="icon-question-sign"></i>
		About me
	</h1>
	<p>
		I am a Dutch Web and <a href="http://mootools.net/developers">MooTools</a>
		Developer and I create Websites and WebApps.
	</p>
	<p>
		I&#39;m also an Embedded Systems student at the
		<a href="http://tudelft.nl">TU Delft</a>.
	</p>
	<p>
		You can find me at other cool places too, like
		<a href="http://twitter.com/astolwijk">twitter</a> or
		<a href="http://github.com/arian">github</a>.
	</p>
</div>

<div class="github">
	<h1>
		<i class="icon-github"></i>
		<a href="http://github.com/arian">github.com/arian</a>
	</h1>
	<div id="github">
		<?php
		$featured = array(
			'CoverJS', 'wrapup', 'prime', 'mootools-core', 'elements'
		);
		?>
		<ul><?php foreach ($this->repos as $repo): ?>

			<li><a href="<?php echo $repo['html_url'] ?>"<?php
				echo in_array($repo['name'], $featured) ? ' class="featured"' : ''
			?>><?php echo $repo['name']; ?></a></li><?php
			endforeach; ?>

		</ul>
	</div>
</div>
