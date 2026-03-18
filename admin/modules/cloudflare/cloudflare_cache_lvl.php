<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

function cloudflare_cache_level_error_message($request)
{
	if(is_object($request) && !empty($request->errors) && is_array($request->errors))
	{
		foreach($request->errors as $error)
		{
			if(!empty($error->message))
			{
				return $error->message;
			}
		}
	}

	return 'Unable to read the current CloudFlare cache level.';
}

$page->add_breadcrumb_item("CloudFlare Manager", "index.php?module=cloudflare");
$page->add_breadcrumb_item("Cache Level", "index.php?module=cloudflare-cache_lvl");
$page->output_header("CloudFlare Manager - Cache Level");

function main_page($current_cache_level, $modified_on)
{
	$form = new Form('index.php?module=cloudflare-cache_lvl&amp;action=change', 'post');
	$form_container = new FormContainer('Modify Cache Level');
	$form_container->output_row('Cache Level',
		"Cache Level functions based off the setting level. The basic setting will cache most static resources (i.e., css, images, and JavaScript). The simplified setting will ignore the query string when delivering a cached resource. The aggressive setting will cache all static resources, including ones with a query string. ",
		$form->generate_select_box('cache_level',
			array(
				'basic' => 'Basic',
				'simplified' => 'Simplified',
				'aggressive' => 'Aggressive'
			),
			$current_cache_level
		)
	);
	$form_container->end();
	$buttons[] = $form->generate_submit_button('Submit');
	$form->output_submit_wrapper($buttons);
	$form->end();
}

$errors = [];
$dn = '';
$current_cache_level = 'unknown';
if ($mybb->input['action'] == 'change')
{
	if(!verify_post_check($mybb->input['my_post_key']))
	{
		flash_message($lang->invalid_post_verify_key2, 'error');
		admin_redirect("index.php?module=cloudflare-cache_lvl");
	}

	$request = $cloudflare->cache_level($mybb->get_input('cache_level'));
	if ($request->success)
	{
		$dn = $cloudflare->get_readable_dt(date());
		$page->output_success("Cache level is now as {$mybb->get_input('cache_level')}");
	}
	else
	{
		$errors[] = cloudflare_cache_level_error_message($request);
		$page->output_error(cloudflare_cache_level_error_message($request));
	}
}

if (!isset($mybb->input['cache_level']) && empty($errors))
{
	$request = $cloudflare->cache_level();
	if(is_object($request) && !empty($request->success) && isset($request->result) && isset($request->result->value))
	{
		$current_cache_level = $request->result->value;
		if(!empty($request->result->modified_on))
		{
			$dn = $cloudflare->get_readable_dt($request->result->modified_on);
		}
		else
		{
			$dn = 'unknown';
		}
	}
	else
	{
		$errors[] = cloudflare_cache_level_error_message($request);
		$dn = 'unknown';
	}
}
else
{
	$current_cache_level = $mybb->input['cache_level'];
}

if(!empty($errors))
{
	$page->output_inline_error(array_values(array_unique($errors)));
}

$page->output_alert("The cache level is currently set to {$current_cache_level} (Modified on: {$dn})");

main_page($current_cache_level, $dn);

$page->output_footer();

?>
