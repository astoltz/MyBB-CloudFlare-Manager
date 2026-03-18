<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$page->add_breadcrumb_item("CloudFlare Manager", "index.php?module=cloudflare");
$page->add_breadcrumb_item("News", "index.php?module=cloudflare-news");

if(!$mybb->input['action'])
{
	$page->output_header("CloudFlare Manager - News");

	$sub_tabs['overview'] = array(
		'title' => "Overview",
		'link' => "index.php?module=cloudflare-overview",
		'description' => "A general overview and summary of statistics and updates."
	);

	$sub_tabs['news'] = array(
		'title' => "CloudFlare News",
		'link' => "index.php?module=cloudflare-news",
		'description' => "The latest news from the CloudFlare blog."
	);

	$page->output_nav_tabs($sub_tabs, 'news');

	$table = new Table;

	$table->construct_cell(get_feed());
	$table->construct_row();

	$table->output("Latest News From the <a href=\"https://blog.cloudflare.com/\" target=\"_blank\" rel=\"noopener noreferrer\">CloudFlare Blog</a>");

	$page->output_footer();
}

function get_feed()
{
	global $cache;

	$cacheKey = 'cloudflare_blog_feed';
	$cacheTtl = 1800;
	$now = defined('TIME_NOW') ? TIME_NOW : time();
	$cached = isset($cache) ? $cache->read($cacheKey) : null;
	if(
		is_array($cached) &&
		!empty($cached['html']) &&
		isset($cached['expires_at']) &&
		(int)$cached['expires_at'] >= $now
	)
	{
		return $cached['html'];
	}

	$feedUrls = array(
		'https://blog.cloudflare.com/rss/',
		'https://blog.cloudflare.com/rss.xml',
	);

	foreach($feedUrls as $feedUrl)
	{
		$rawFeed = fetch_remote_file($feedUrl);
		$html = cloudflare_build_feed_html($rawFeed);
		if($html === false)
		{
			continue;
		}

		if(isset($cache))
		{
			$cache->update($cacheKey, array(
				'html' => $html,
				'expires_at' => $now + $cacheTtl,
				'source_url' => $feedUrl,
			));
		}

		return $html;
	}

	if(is_array($cached) && !empty($cached['html']))
	{
		return $cached['html'] . "<br /><br /><em>Showing cached CloudFlare blog data because the live feed could not be refreshed.</em>";
	}

	return "Error: Could not retrieve data.";
}

function cloudflare_build_feed_html($rawFeed)
{
	if(!is_string($rawFeed) || trim($rawFeed) === '')
	{
		return false;
	}

	libxml_use_internal_errors(true);
	$feed = simplexml_load_string($rawFeed);
	libxml_clear_errors();

	if(!$feed || empty($feed->channel->item))
	{
		return false;
	}

	foreach($feed->channel->item as $entry)
	{
		$title = isset($entry->title) ? htmlspecialchars_uni((string)$entry->title) : '';
		$pubDate = isset($entry->pubDate) ? htmlspecialchars_uni((string)$entry->pubDate) : '';
		$description = isset($entry->description) ? (string)$entry->description : '';
		return "<span style=\"font-size: 16px;\"><strong>{$title} - {$pubDate}</strong></span><br /><br />{$description}<br /><br />";
	}

	return false;
}

?>
