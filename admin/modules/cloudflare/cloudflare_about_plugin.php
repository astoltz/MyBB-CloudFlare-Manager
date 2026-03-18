<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$page->add_breadcrumb_item("CloudFlare Manager", "index.php?module=cloudflare");
$page->add_breadcrumb_item("About Plugin", "index.php?module=cloudflare-about_plugin");

if(!$mybb->input['action'])
{
	$page->output_header("CloudFlare Manager - About Plugin");
	$latestVersion = trim((string)$cloudflare->get_latest_version());
	$versionLabel = CLOUDFLARE_MANAGER_VERSION;
	if($latestVersion !== '')
	{
		$versionLabel .= " (Latest: " . htmlspecialchars_uni($latestVersion) . ")";
	}

	$table = new Table;
	$table->construct_header("Item", array("colspan" => 1));
	$table->construct_header("Value", array("colspan" => 1));

	$table->construct_cell("<strong>Original Author</strong>", array('width' => '25%'));
	$table->construct_cell("<strong><a href=\"https://community.mybb.com/user-27579.html\" target=\"_blank\">Nathan Malcolm</a></strong>", array('width' => '25%'));
	$table->construct_row();

	$table->construct_cell('<strong>Fork Maintainer</strong>', array('width' => '25%'));
	$table->construct_cell('<strong><a href="https://github.com/astoltz" target="_blank">astoltz</a> / <a href="' . htmlspecialchars_uni(CLOUDFLARE_MANAGER_REPOSITORY_URL) . '" target="_blank">Fork Repository</a></strong>');
	$table->construct_row();

	$table->construct_cell("<strong>Support / Issues</strong>", array('width' => '200'));
	$table->construct_cell('<a href="' . htmlspecialchars_uni(CLOUDFLARE_MANAGER_ISSUES_URL) . '" target="_blank">GitHub Issues</a>', array('width' => '200'));
	$table->construct_row();

	$table->construct_cell("<strong>Compatibility</strong>", array('width' => '200'));
	$table->construct_cell("MyBB 1.8 Series", array('width' => '200'));
	$table->construct_row();

	$table->construct_cell("<strong>Version</strong>", array('width' => '200'));
	$table->construct_cell($versionLabel, array('width' => '200'));
	$table->construct_row();

	$table->output("About This Plugin");

	$page->output_footer();
}

?>
