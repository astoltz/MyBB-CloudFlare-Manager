<?php

if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

function cloudflare_render_access_rule_form($module, $title, $description, $submitLabel)
{
	global $mybb;

	$form = new Form("index.php?module={$module}&amp;action=run", "post");
	$form_container = new FormContainer($title);
	$form_container->output_row(
		"IPs / CIDRs",
		$description,
		$form->generate_text_area('targets', $mybb->get_input('targets'), array('rows' => 10, 'style' => 'width: 100%;'))
	);
	$form_container->output_row("Notes", "Any notes you would like to add to every submitted rule", $form->generate_text_box('notes', $mybb->get_input('notes'), array('style' => 'width: 100%;')));
	$form_container->end();
	$buttons[] = $form->generate_submit_button($submitLabel);
	$form->output_submit_wrapper($buttons);
	$form->end();
}

function cloudflare_parse_access_rule_targets($input)
{
	$targets = array();
	$parts = preg_split('/[\r\n,;]+/', (string)$input);

	foreach($parts as $part)
	{
		$value = trim((string)$part);
		if($value === '' || strpos($value, '#') === 0)
		{
			continue;
		}

		$targets[$value] = $value;
	}

	return array_values($targets);
}

function cloudflare_apply_access_rule_targets($cloudflare, $mode, array $targets, $notes = '')
{
	$result = array(
		'processed' => 0,
		'successes' => array(),
		'errors' => array(),
	);

	foreach($targets as $target)
	{
		$result['processed']++;
		$response = $cloudflare->upsert_ip_or_range_access_rule($mode, $target, $notes);
		if(!empty($response['success']))
		{
			$result['successes'][] = $target;
			continue;
		}

		$errorMessage = is_array($response) && !empty($response['errors']) ? $response['errors'] : 'Unknown CloudFlare error.';
		$result['errors'][] = $target . ': ' . $errorMessage;
	}

	return $result;
}

function cloudflare_build_access_rule_success_message($verb, $domain, array $result)
{
	$processed = (int)$result['processed'];
	$successes = count($result['successes']);
	$errors = count($result['errors']);

	return "<p><em>CloudFlare processed {$processed} entr" . ($processed === 1 ? "y" : "ies") . " for {$verb} on {$domain}: {$successes} succeeded, {$errors} failed.</em></p>";
}
