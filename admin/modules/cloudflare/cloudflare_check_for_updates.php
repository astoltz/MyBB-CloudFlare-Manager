<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$latest_version = $cloudflare->get_latest_version();

$latest_version = trim((string)$latest_version);

if($latest_version === '')
{
	flash_message('The CloudFlare Manager update server did not return a version string. Current installed version: ' . CLOUDFLARE_MANAGER_VERSION . '.', 'notice');
}
elseif(CLOUDFLARE_MANAGER_VERSION == $latest_version)
{
	flash_message('Congratulations! You are using the latest version of this plugin (' . CLOUDFLARE_MANAGER_VERSION . ")", 'success');
}
else
{
	flash_message('You are not using the latest version of this plugin. You are using ' . CLOUDFLARE_MANAGER_VERSION . ', and the latest version is ' . $latest_version, "error");
}

admin_redirect("index.php?module=cloudflare");

?>
