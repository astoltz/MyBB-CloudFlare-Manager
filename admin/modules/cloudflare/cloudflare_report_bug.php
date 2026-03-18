<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

admin_redirect(defined('CLOUDFLARE_MANAGER_ISSUES_URL') ? CLOUDFLARE_MANAGER_ISSUES_URL : 'https://github.com/astoltz/MyBB-CloudFlare-Manager/issues');

?>
