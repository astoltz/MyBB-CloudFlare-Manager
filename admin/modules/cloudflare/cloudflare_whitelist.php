<?php


// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

require_once __DIR__ . "/class/cloudflare_access_rule_batch.php";

$page->add_breadcrumb_item("CloudFlare Manager", "index.php?module=cloudflare");
$page->add_breadcrumb_item("White List", "index.php?module=cloudflare-whitelist");
$page->output_header("CloudFlare Manager - White List");

function main_page()
{
	cloudflare_render_access_rule_form(
		'cloudflare-whitelist',
		'Whitelist IPs / CIDRs',
		'Enter one IPv4 address, IPv6 address, or CIDR range per line. Comma- or semicolon-separated lists are also accepted.',
		'Whitelist'
	);
}

if(!$mybb->input['action'])
{
	main_page();
}
elseif($mybb->input['action'] == "run")
{
	if(!verify_post_check($mybb->input['my_post_key']))
	{
		flash_message($lang->invalid_post_verify_key2, 'error');
		admin_redirect("index.php?module=cloudflare-whitelist");
	}

	$targets = cloudflare_parse_access_rule_targets($mybb->get_input('targets'));
	if(empty($targets))
	{
		$page->output_inline_error('Enter at least one IP address or CIDR range.');
		main_page();
		$page->output_footer();
		exit;
	}

	$result = cloudflare_apply_access_rule_targets($cloudflare, 'whitelist', $targets, $mybb->get_input('notes'));

	if(!empty($result['successes']))
	{
		$page->output_success(cloudflare_build_access_rule_success_message('allowlisting', htmlspecialchars_uni($mybb->settings['cloudflare_domain']), $result));
	}
	if(!empty($result['errors']))
	{
		$page->output_inline_error($result['errors']);
	}
	main_page();
}

$page->output_footer();
?>
