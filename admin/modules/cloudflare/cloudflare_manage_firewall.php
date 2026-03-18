<?php


// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

require_once __DIR__ . "/class/cloudflare_mybb_sync.php";

$page->add_breadcrumb_item("CloudFlare Manager", "index.php?module=cloudflare");
$page->add_breadcrumb_item("Manage Firewall", "index.php?module=cloudflare-manage_firewall");
$page->output_header("CloudFlare Manager - Manage Firewall");

function main_page()
{
	global $cloudflare, $mybb, $db, $page;

	$request = $cloudflare->get_all_access_rules();
	if(isset($request['errors']))
	{
		$page->output_inline_error($request['errors']);
		return;
	}

	$syncAudit = cloudflare_sync_audit($db, $cloudflare, array(
		'backfill_notes' => true,
		'create_missing' => true,
		'overwrite_notes' => false,
	));
	if(isset($syncAudit['errors']))
	{
		$page->output_inline_error($syncAudit['errors']);
		$syncAudit = array(
			'counts' => array(
				'cloudflare_ip_rules' => 0,
				'mybb_ip_banfilters' => 0,
				'update_notes' => 0,
				'create_rules' => 0,
				'cloudflare_only' => 0,
			),
			'suggested_notes' => array(),
		);
	}

		$summary = new Table;
		$summary->construct_header("MyBB Sync", array('colspan' => 2));
		$summary->construct_cell('<strong>CloudFlare exact-IP rules</strong>');
		$summary->construct_cell((string)$syncAudit['counts']['cloudflare_ip_rules']);
		$summary->construct_row();
		$summary->construct_cell('<strong>MyBB IP banfilters</strong>');
		$summary->construct_cell((string)$syncAudit['counts']['mybb_ip_banfilters']);
	$summary->construct_row();
	$summary->construct_cell('<strong>Blank/stale notes ready to backfill</strong>');
	$summary->construct_cell((string)$syncAudit['counts']['update_notes']);
	$summary->construct_row();
		$summary->construct_cell('<strong>MyBB IP banfilters missing from CloudFlare</strong>');
		$summary->construct_cell((string)$syncAudit['counts']['create_rules']);
		$summary->construct_row();
		$summary->construct_cell('<strong>CloudFlare-only exact-IP rules</strong>');
		$summary->construct_cell((string)$syncAudit['counts']['cloudflare_only']);
		$summary->construct_row();
	$summary->construct_cell('<strong>Actions</strong>');
	$summary->construct_cell(
		'<a href="index.php?module=cloudflare-manage_firewall&amp;action=sync_from_mybb&amp;do_notes=1&amp;my_post_key=' . urlencode($mybb->post_code) . '">Backfill blank notes from MyBB</a>'
		. '&nbsp;/&nbsp;'
		. '<a href="index.php?module=cloudflare-manage_firewall&amp;action=sync_from_mybb&amp;do_notes=1&amp;do_missing=1&amp;my_post_key=' . urlencode($mybb->post_code) . '">Sync MyBB IP banfilters to CloudFlare</a>'
	);
	$summary->construct_row();
	$summary->output("MyBB Firewall Sync");

	$table = new Table;
	$table->construct_header("Mode");
	$table->construct_header("Target");
	$table->construct_header("IP Address");
	$table->construct_header("Notes");
	$table->construct_header("Suggested MyBB Note");
	$table->construct_header("Modify");

	foreach($request as $rule)
	{
		if(!isset($rule->configuration) || !is_object($rule->configuration))
		{
			continue;
		}

		$target = (string)$rule->configuration->target;
		if($target !== 'ip' && $target !== 'ip_range')
		{
			continue;
		}

		$ip = (string)$rule->configuration->value;
		$currentNotes = isset($rule->notes) ? trim((string)$rule->notes) : '';
		$suggested = ($target === 'ip' && isset($syncAudit['suggested_notes'][$ip])) ? $syncAudit['suggested_notes'][$ip] : '';
		$modifyLink = "index.php?module=cloudflare-manage_firewall&amp;action=modify_rule_by_ip"
			. "&amp;rule_id=" . urlencode((string)$rule->id)
			. "&amp;target=" . urlencode($target)
			. "&amp;ip=" . urlencode($ip)
			. "&amp;my_post_key=" . urlencode($mybb->post_code)
			. "&amp;current_mode=" . urlencode((string)$rule->mode)
			. "&amp;current_notes=" . urlencode($currentNotes);
		$deleteLink = "index.php?module=cloudflare-manage_firewall&amp;action=delete_rule_by_id"
			. "&amp;rule_id=" . urlencode((string)$rule->id)
			. "&amp;ip_address=" . urlencode($ip)
			. "&amp;my_post_key=" . urlencode($mybb->post_code);

		$table->construct_cell(htmlspecialchars_uni((string)$rule->mode));
		$table->construct_cell(htmlspecialchars_uni($target));
		$table->construct_cell(htmlspecialchars_uni($ip));
		$table->construct_cell($currentNotes === '' ? '<em>None</em>' : htmlspecialchars_uni($currentNotes));
		$table->construct_cell($suggested === '' ? '&nbsp;' : htmlspecialchars_uni($suggested));
		$table->construct_cell("<a href=\"{$modifyLink}\">Modify</a>&nbsp;/&nbsp;<a href=\"{$deleteLink}\">Delete</a>");
		$table->construct_row();
	}

	$table->output("Firewall Rules");

}

function cloudflare_manage_firewall_apply_sync($doNotes, $doMissing)
{
	global $cloudflare, $db;

	$audit = cloudflare_sync_audit($db, $cloudflare, array(
		'backfill_notes' => $doNotes,
		'create_missing' => $doMissing,
		'overwrite_notes' => false,
	));
	if(isset($audit['errors']))
	{
		return array('errors' => $audit['errors']);
	}

	return cloudflare_sync_apply($cloudflare, $audit, array(
		'apply_updates' => $doNotes,
		'apply_creates' => $doMissing,
	));
}

if ($mybb->input['action'] == 'modify_rule_by_ip')
{
	if (isset($mybb->input['update_rule']))
	{
		if(!verify_post_check($mybb->input['my_post_key']))
		{
			flash_message($lang->invalid_post_verify_key2, 'error');
			admin_redirect("index.php?module=cloudflare-manage_firewall");
		}

		$request = $cloudflare->update_access_rule_by_id(
			$mybb->get_input('rule_id'),
			$mybb->get_input('mode'),
			$mybb->get_input('ip_address'),
			$mybb->get_input('notes'),
			$mybb->get_input('target')
		);

		if (!empty($request['success']))
		{
			flash_message("Updated the firewall rule with IP {$mybb->get_input('ip_address')}", "success");
			admin_redirect("index.php?module=cloudflare-manage_firewall");
		}
		else
		{
			flash_message($request['errors'], "error");
			admin_redirect("index.php?module=cloudflare-manage_firewall");
		}
	}

	$form = new Form('index.php?module=cloudflare-manage_firewall&amp;action=modify_rule_by_ip', 'post');
	$form_container = new FormContainer("Modify Firewall Rule");
	$form_container->output_row("IP / CIDR", "The IP address or CIDR range you would like to update", $form->generate_text_box('ip_address', $mybb->get_input('ip')));
	$form_container->output_row('Mode', '', $form->generate_select_box("mode", array("whitelist" => "Whitelist", "block" => "Blacklist", "challenge" => "Challenge"), $mybb->get_input('current_mode')));
	$form_container->output_row("Notes", "Any notes you would like to add", $form->generate_text_box('notes', $mybb->get_input('current_notes')));
	echo $form->generate_hidden_field('rule_id', $mybb->get_input('rule_id'));
	echo $form->generate_hidden_field('target', $mybb->get_input('target'));
	echo $form->generate_hidden_field('update_rule', 'update');
	$form_container->end();
	$buttons[] = $form->generate_submit_button("Submit");
	$form->output_submit_wrapper($buttons);
	$form->end();
}
elseif ($mybb->input['action'] == 'sync_from_mybb')
{
	if(!verify_post_check($mybb->input['my_post_key']))
	{
		flash_message($lang->invalid_post_verify_key2, 'error');
		admin_redirect("index.php?module=cloudflare-manage_firewall");
	}

	$doNotes = (int)$mybb->get_input('do_notes', MyBB::INPUT_INT) === 1;
	$doMissing = (int)$mybb->get_input('do_missing', MyBB::INPUT_INT) === 1;
	if(!$doNotes && !$doMissing)
	{
		flash_message('No sync actions were selected.', 'error');
		admin_redirect("index.php?module=cloudflare-manage_firewall");
	}

	$result = cloudflare_manage_firewall_apply_sync($doNotes, $doMissing);
	if(!empty($result['errors']))
	{
		flash_message(implode(' | ', $result['errors']), 'error');
		admin_redirect("index.php?module=cloudflare-manage_firewall");
	}

	$summary = array();
	if($doNotes)
	{
		$summary[] = (int)$result['updated_notes'] . ' note(s) backfilled';
	}
	if($doMissing)
	{
		$summary[] = (int)$result['created_rules'] . ' rule(s) created';
	}

	flash_message('CloudFlare sync complete: ' . implode(', ', $summary), 'success');
	admin_redirect("index.php?module=cloudflare-manage_firewall");
}
elseif ($mybb->input['action'] == 'delete_rule_by_id')
{
	if(!verify_post_check($mybb->input['my_post_key']))
	{
		flash_message($lang->invalid_post_verify_key2, 'error');
		admin_redirect("index.php?module=cloudflare-manage_firewall");
	}

	$request = $cloudflare->delete_firewall_rule($mybb->get_input('rule_id'));

	if (!empty($request->success))
	{
		flash_message("Deleted the firewall rule with IP {$mybb->get_input('ip_address')}", "success");
		admin_redirect("index.php?module=cloudflare-manage_firewall");
	}
	else
	{
		flash_message($request->errors[0]->message, "error");
		admin_redirect("index.php?module=cloudflare-manage_firewall");
	}
	
}
else
{
	main_page();
}

?>
