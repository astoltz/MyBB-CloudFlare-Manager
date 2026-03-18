<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$page->add_breadcrumb_item("CloudFlare Manager", "index.php?module=cloudflare");
$page->add_breadcrumb_item("Browser Integrations", "index.php?module=cloudflare-integrations");
$page->output_header("CloudFlare Manager - Browser Integrations");

function cloudflare_render_browser_integrations(array $status)
{
	global $page;

	echo "<p><em>These controls manage CloudFlare Configuration Rules for browser-side integrations. They do not add or change MyBB CORS headers; they create or remove CloudFlare <code>set_config</code> rules that disable the injected Web Analytics beacon and/or Zaraz.</em></p>";

	$table = new Table;
	$table->construct_header("Item");
	$table->construct_header("Current State");

	$table->construct_cell("<strong>http_config_settings ruleset</strong>");
	if(!empty($status['ruleset_missing']))
	{
		$table->construct_cell("No entry point ruleset exists yet.");
	}
	else
	{
		$table->construct_cell("Present: " . htmlspecialchars_uni($status['ruleset_id']));
	}
	$table->construct_row();

	$table->construct_cell("<strong>Disable CloudFlare Web Analytics (RUM)</strong>");
	$table->construct_cell(
		!empty($status['disable_rum'])
			? "Enabled via rule " . htmlspecialchars_uni($status['disable_rum_rule_id'])
			: "Off"
	);
	$table->construct_row();

	$table->construct_cell("<strong>Disable CloudFlare Zaraz</strong>");
	$table->construct_cell(
		!empty($status['disable_zaraz'])
			? "Enabled via rule " . htmlspecialchars_uni($status['disable_zaraz_rule_id'])
			: "Off"
	);
	$table->construct_row();

	$table->output("Current CloudFlare Rule State");

	$form = new Form('index.php?module=cloudflare-integrations&amp;action=save', 'post');
	$form_container = new FormContainer('Browser-side integrations');

	$form_container->output_row(
		'Disable CloudFlare Web Analytics (RUM)',
		'Turns off the CloudFlare Web Analytics / Browser Insights beacon for all requests in this zone by creating a Configuration Rule with <code>disable_rum=true</code>.',
		$form->generate_yes_no_radio('disable_rum', (!empty($status['disable_rum']) ? '1' : '0'))
	);

	$form_container->output_row(
		'Disable CloudFlare Zaraz',
		'Turns off CloudFlare Zaraz for all requests in this zone by creating a Configuration Rule with <code>disable_zaraz=true</code>.',
		$form->generate_yes_no_radio('disable_zaraz', (!empty($status['disable_zaraz']) ? '1' : '0'))
	);

	$form_container->end();

	$buttons[] = $form->generate_submit_button('Save');
	$form->output_submit_wrapper($buttons);
	$form->end();
}

if($mybb->input['action'] == 'save')
{
	if(!verify_post_check($mybb->input['my_post_key']))
	{
		flash_message($lang->invalid_post_verify_key2, 'error');
		admin_redirect("index.php?module=cloudflare-integrations");
		exit;
	}

	$requestedRum = ($mybb->get_input('disable_rum', MyBB::INPUT_INT) === 1);
	$requestedZaraz = ($mybb->get_input('disable_zaraz', MyBB::INPUT_INT) === 1);

	$results = array(
		$cloudflare->set_managed_http_config_setting('disable_rum', $requestedRum),
		$cloudflare->set_managed_http_config_setting('disable_zaraz', $requestedZaraz),
	);

	$errors = array();
	foreach($results as $result)
	{
		if(!empty($result['errors']) && is_array($result['errors']))
		{
			$errors = array_merge($errors, $result['errors']);
		}
	}

	if(!empty($errors))
	{
		$page->output_inline_error(array_values(array_unique($errors)));
	}
	else
	{
		$page->output_success('Updated CloudFlare browser integration rules.');
	}
}

$status = $cloudflare->get_managed_http_config_status();
if(!empty($status['errors']))
{
	$page->output_inline_error($status['errors']);
}
else
{
	cloudflare_render_browser_integrations($status);
}

$page->output_footer();

?>
