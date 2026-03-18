<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$plugins->add_hook('pre_output_page', 'cloudflare_backlink');

define('CLOUDFLARE_MANAGER_VERSION', '2.3.0');
define('CLOUDFLARE_MANAGER_REPOSITORY_URL', 'https://github.com/astoltz/MyBB-CloudFlare-Manager');
define('CLOUDFLARE_MANAGER_ISSUES_URL', CLOUDFLARE_MANAGER_REPOSITORY_URL . '/issues');
define('CLOUDFLARE_MANAGER_VERSION_SOURCE_URL', 'https://raw.githubusercontent.com/astoltz/MyBB-CloudFlare-Manager/main/inc/plugins/cloudflare.php');

function cloudflare_info()
{
	return array(
		'name'			=> 'CloudFlare Manager',
		'description'	=> 'An advanced plugin for managing CloudFlare from your forum\'s admin control panel.',
		'website'		=> CLOUDFLARE_MANAGER_REPOSITORY_URL,
		'author'		=> 'Original maintenance by MyBB Security Group and Nathan (dequeues).<br />Fork maintained by <a href="https://github.com/astoltz">astoltz</a>.',
		'authorsite'	=> CLOUDFLARE_MANAGER_REPOSITORY_URL,
		'version'		=> CLOUDFLARE_MANAGER_VERSION,
		"compatibility" => '18*'
	);
}

function cloudflare_has_api_credentials(array $settings = array())
{
	global $mybb;

	if (empty($settings))
	{
		$settings = $mybb->settings;
	}

	$hasToken = !empty($settings['cloudflare_api_token']);
	$hasLegacyKey = !empty($settings['cloudflare_api']) && !empty($settings['cloudflare_email']);

	return !empty($settings['cloudflare_domain']) && ($hasToken || $hasLegacyKey);
}

function cloudflare_setting_definitions()
{
	global $mybb, $plugins;

	$parse = parse_url($mybb->settings['bburl']);
	$domain = !empty($parse['host']) ? $parse['host'] : '';
	$domain = str_replace('www.', '', $domain);

	$dispnum = 0;

	$settings = array(
		"cloudflare_domain" => array(
			"title"			=> "Domain",
			"description"	=> "The domain (of this forum) that is active under CloudFlare",
			"optionscode"	=> "text",
			"value"			=> $domain,
			"disporder"		=> ++$dispnum
		),
		"cloudflare_email" => array(
			"title"			=> "Email",
			"description"	=> "Your email address linked to your CloudFlare account",
			"optionscode"	=> "text",
			"value"			=> $mybb->user['email'],
			"disporder"		=> ++$dispnum
		),
		"cloudflare_api_token" => array(
			"title"			=> "API Token",
			"description"	=> "Recommended. A scoped CloudFlare API token for this zone. If provided, the plugin will prefer this over the legacy global API key/email pair.",
			"optionscode"	=> "text",
			"value"			=> "",
			"disporder"		=> ++$dispnum
		),
		"cloudflare_api" => array(
			"title"			=> "Legacy API Key",
			"description"	=> "Legacy fallback only. Global CloudFlare API key used when no API token is configured.",
			"optionscode"	=> "text",
			"value"			=> "",
			"disporder"		=> ++$dispnum
		),
		"cloudflare_showdns" => array(
			"title"			=> "Show DNS?",
			"description"	=> "Do you want to show the IP address host on Recent Visitors? *May slow down process if enabled.",
			"optionscode"	=> "yesno",
			"value"			=> "0",
			"disporder"		=> ++$dispnum
		),
		"cloudflare_backlink" => array(
			"title"			=> "Show \"Enhanced By CloudFlare\" message?",
			"description"	=> "Do you want to show the enchanced by CloudFlare message in your board footer? It helps to expand the CloudFlare network and speed up more websites on the internet.",
			"optionscode"	=> "yesno",
			"value"			=> "1",
			"disporder"		=> ++$dispnum
		)
	);

	if (isset($plugins))
	{
		$settings = $plugins->run_hooks('cloudflare_setting_definitions', $settings);
	}

	return $settings;
}

function cloudflare_sync_settings_schema()
{
	global $db;

	$group = array(
		"name" => "cloudflare",
		"title" => "CloudFlare Manager",
		"description" => "Configures options for the CloudFlare Manager plugin.",
		"disporder" => "1",
	);

	$query = $db->simple_select("settinggroups", "gid", "name='cloudflare'", array("limit" => 1));
	$existingGroup = $db->fetch_array($query);
	if (!empty($existingGroup['gid']))
	{
		$gid = (int)$existingGroup['gid'];
		$db->update_query("settinggroups", $group, "gid='{$gid}'");
	}
	else
	{
		$gid = $db->insert_query("settinggroups", $group);
	}

	$settings = cloudflare_setting_definitions();
	foreach($settings as $name => $setting)
	{
		$setting['gid'] = $gid;
		$setting['name'] = $name;

		$query = $db->simple_select("settings", "sid,value", "name='" . $db->escape_string($name) . "'", array("limit" => 1));
		$existing = $db->fetch_array($query);
		if (!empty($existing['sid']))
		{
			$setting['value'] = $existing['value'];
			$db->update_query("settings", $setting, "sid='" . (int)$existing['sid'] . "'");
			continue;
		}

		$db->insert_query("settings", $setting);
	}

	rebuild_settings();

	return $gid;
}

function cloudflare_install()
{
	$gid = cloudflare_sync_settings_schema();

	admin_redirect("index.php?module=config-settings&action=change&gid={$gid}");
}

function cloudflare_activate()
{
	global $db, $mybb;

	include MYBB_ROOT."/inc/adminfunctions_templates.php";
	cloudflare_sync_settings_schema();

	$db->delete_query("templates", "title = 'cloudflare_postbit_spam'");

	find_replace_templatesets('footer', '#<!-- End powered by --><cfb>#', '<!-- End powered by -->');
	find_replace_templatesets('footer', '#<!-- End powered by -->#', '<!-- End powered by --><cfb>');

	rebuild_settings();
}

function cloudflare_deactivate()
{
	global $db, $mybb;

	include MYBB_ROOT."/inc/adminfunctions_templates.php";

	find_replace_templatesets('footer', '#<!-- End powered by --><cfb>#', '<!-- End powered by -->');

	$db->delete_query("templates", "title = 'cloudflare_postbit_spam'");

	rebuild_settings();
}

function cloudflare_is_installed()
{
    global $db;

	$query = $db->query("SELECT * FROM ".TABLE_PREFIX."settinggroups WHERE name='cloudflare'");

	if($db->num_rows($query) == 0)
	{
		return false;
	}
	return true;
}

function cloudflare_uninstall()
{
	global $db;

	$db->query("DELETE FROM ".TABLE_PREFIX."settinggroups WHERE name='cloudflare'");
	$db->query("DELETE FROM ".TABLE_PREFIX."settings WHERE name LIKE 'cloudflare_%'");

	$db->query("DELETE FROM ".TABLE_PREFIX."datacache WHERE title='cloudflare_calls'");

	rebuild_settings();
}

function cloudflare_backlink(&$page)
{
	global $mybb, $cfb, $plugins;

	if($mybb->settings['cloudflare_backlink'] == 1)
	{
		$cfb = "Enhanced By <a href=\"https://www.cloudflare.com/\" target=\"_blank\" rel=\"noopener noreferrer\">CloudFlare</a>.";
	}
	else
	{
		$cfb = "";
	}

	if (isset($plugins))
	{
		$cfb = $plugins->run_hooks('cloudflare_backlink_html', $cfb);
	}

	$page = str_replace("<cfb>", $cfb, $page);

	return $page;
}

?>
