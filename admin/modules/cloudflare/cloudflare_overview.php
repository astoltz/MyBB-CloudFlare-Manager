<?php


// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

function cloudflare_collect_error_messages($request, $fallback)
{
	$errors = array();

	if(is_object($request) && !empty($request->errors) && is_array($request->errors))
	{
		foreach($request->errors as $error)
		{
			if(!empty($error->message))
			{
				$errors[] = $error->message;
			}
		}
	}

	if(empty($errors))
	{
		$errors[] = $fallback;
	}

	return $errors;
}

function cloudflare_build_statistics_summary($request, $cloudflare, $label, array &$errors)
{
	if(!is_object($request) || empty($request->success) || !isset($request->result->totals) || !is_object($request->result->totals))
	{
		$errors = array_merge($errors, cloudflare_collect_error_messages($request, "CloudFlare {$label} statistics are unavailable."));

		return array(
			'requests' => 0,
			'visits' => 0,
			'bandwidth' => $cloudflare->get_formatted_size_by_bytes(0),
		);
	}

	$totals = $request->result->totals;

	$requests = (isset($totals->requests) && isset($totals->requests->all)) ? (int)$totals->requests->all : 0;
	$visits = (isset($totals->visits) && isset($totals->visits->all)) ? (int)$totals->visits->all : 0;
	$bandwidth = (isset($totals->bandwidth) && isset($totals->bandwidth->all)) ? (float)$totals->bandwidth->all : 0;

	return array(
		'requests' => $requests,
		'visits' => $visits,
		'bandwidth' => $cloudflare->get_formatted_size_by_bytes($bandwidth),
	);
}

function cloudflare_request_has_statistics($request)
{
	return (
		is_object($request) &&
		!empty($request->success) &&
		isset($request->result->totals) &&
		is_object($request->result->totals)
	);
}

function cloudflare_request_has_only_message($request, $message)
{
	if(!is_object($request) || empty($request->errors) || !is_array($request->errors))
	{
		return false;
	}

	$messages = array();
	foreach($request->errors as $error)
	{
		if(!empty($error->message))
		{
			$messages[] = $error->message;
		}
	}

	return !empty($messages) && count(array_diff($messages, array($message))) === 0;
}

function cloudflare_build_statistics_success_message($request, $label)
{
	if(!cloudflare_request_has_statistics($request))
	{
		return "CloudFlare {$label} traffic cache refreshed.";
	}

	$totals = $request->result->totals;
	$requests = (isset($totals->requests->all)) ? my_number_format((int)$totals->requests->all) : '0';
	$visits = (isset($totals->visits->all)) ? my_number_format((int)$totals->visits->all) : '0';

	return "CloudFlare {$label} traffic cache refreshed ({$requests} requests, {$visits} visits).";
}

function cloudflare_overview_statistics_link($label, $refresh = false, $scope = '')
{
	$url = "index.php?module=cloudflare-overview";
	if($refresh)
	{
		$url .= "&refresh_stats=1";
		if($scope !== '')
		{
			$url .= "&refresh_scope=" . rawurlencode($scope);
		}
	}

	return '<a href="' . htmlspecialchars_uni($url) . '">' . htmlspecialchars_uni($label) . '</a>';
}

$page->add_breadcrumb_item("CloudFlare Manager", "index.php?module=cloudflare");
$page->add_breadcrumb_item("CloudFlare Overview", "index.php?module=cloudflare-overview");

if(!$mybb->input['action'])
{
	$plugins->run_hooks("admin_cloudflare_overview_start");

	if($mybb->request_method === 'post' && $mybb->get_input('warm_weekly_stats', MyBB::INPUT_INT) === 1)
	{
		if(!verify_post_check($mybb->input['my_post_key']))
		{
			flash_message($lang->invalid_post_verify_key2, 'error');
			admin_redirect('index.php?module=cloudflare-overview');
		}

		$zoneContext = $cloudflare->ensure_zone_context();
		if(is_array($zoneContext) && isset($zoneContext['errors']))
		{
			flash_message(implode('<br />', $zoneContext['errors']), 'error');
			admin_redirect('index.php?module=cloudflare-overview');
		}

		$weeklyWarmRequest = $cloudflare->get_statistics(-10080, true);
		if(cloudflare_request_has_statistics($weeklyWarmRequest))
		{
			flash_message(cloudflare_build_statistics_success_message($weeklyWarmRequest, 'weekly'), 'success');
		}
		else
		{
			$weeklyWarmErrors = cloudflare_collect_error_messages($weeklyWarmRequest, 'CloudFlare weekly statistics are unavailable.');
			flash_message(implode('<br />', $weeklyWarmErrors), 'error');
		}

		admin_redirect('index.php?module=cloudflare-overview');
	}

	$page->output_header("CloudFlare Manager - Overview");

	$refreshStats = ($mybb->get_input('refresh_stats', MyBB::INPUT_INT) === 1);
	$refreshScope = $refreshStats ? strtolower(trim((string)$mybb->get_input('refresh_scope'))) : '';
	if(!in_array($refreshScope, array('daily', 'weekly'), true))
	{
		$refreshScope = 'daily';
	}
	$refreshDaily = ($refreshStats && $refreshScope === 'daily');
	$zoneId = $cloudflare->get_cached_zone_id();
	$accountId = $cloudflare->get_cached_account_id();
	$zoneContextErrors = array();
	$weeklyRefreshMoved = ($refreshStats && $refreshScope === 'weekly');

	if ($refreshDaily && (!$zoneId || !$accountId))
	{
		$zoneContext = $cloudflare->ensure_zone_context();
		if (is_array($zoneContext) && isset($zoneContext['errors']))
		{
			$zoneContextErrors = $zoneContext['errors'];
		}

		$zoneId = $cloudflare->get_cached_zone_id();
		$accountId = $cloudflare->get_cached_account_id();
	}


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

	$page->output_nav_tabs($sub_tabs, 'overview');

	$dnsStatusActive = $cloudflare->dns_status(false);
	if($dnsStatusActive === null)
	{
		$dns_status = "<span style=\"color:#999;font-weight:bold;\">Cached Status Unavailable</span>";
	}
	elseif($dnsStatusActive)
	{
		$dns_status = "<a href=\"index.php?module=cloudflare-dns_active\"><span style=\"color:green;font-weight:bold;\">Active</span></a>";
	}
	else
	{
		$dns_status = "<a href=\"index.php?module=cloudflare-dns_not_active\"><span style=\"color:red;font-weight:bold;\">Not Active</span></a>";
		flash_message("Your nameservers are not set correctly. Please change them to match the ones provided to you by CloudFlare.", "error");
	}

	$statistics_errors = $zoneContextErrors;
	$cacheMissMessage = 'No cached CloudFlare statistics are available yet for this range.';
	$today_results = array(
		'requests' => 0,
		'visits' => 0,
		'bandwidth' => $cloudflare->get_formatted_size_by_bytes(0),
	);
	$week_results = $today_results;
	$today_request = $cloudflare->get_statistics(-1440, ($refreshDaily && !empty($zoneId)));
	$week_request = $cloudflare->get_statistics(-10080, false);
	$today_has_statistics = cloudflare_request_has_statistics($today_request);
	$week_has_statistics = cloudflare_request_has_statistics($week_request);
	$today_cache_miss = cloudflare_request_has_only_message($today_request, $cacheMissMessage);
	$week_cache_miss = cloudflare_request_has_only_message($week_request, $cacheMissMessage);

	if($today_has_statistics)
	{
		$today_summary_errors = array();
		$today_results = cloudflare_build_statistics_summary($today_request, $cloudflare, 'daily', $today_summary_errors);
	}
	elseif(!$today_cache_miss)
	{
		$statistics_errors = array_merge($statistics_errors, cloudflare_collect_error_messages($today_request, 'CloudFlare daily statistics are unavailable.'));
	}

	if($week_has_statistics)
	{
		$week_summary_errors = array();
		$week_results = cloudflare_build_statistics_summary($week_request, $cloudflare, 'weekly', $week_summary_errors);
	}
	elseif(!$week_cache_miss)
	{
		$statistics_errors = array_merge($statistics_errors, cloudflare_collect_error_messages($week_request, 'CloudFlare weekly statistics are unavailable.'));
	}

	if($refreshDaily && !$zoneId)
	{
		$statistics_errors[] = 'CloudFlare zone details could not be loaded, so the live statistics refresh was skipped.';
	}

	$statistics_errors = array_values(array_unique($statistics_errors));
	if(!empty($statistics_errors))
	{
		$page->output_inline_error($statistics_errors);
	}

	$table = new Table;
	$table->construct_header("API Details", array("colspan" => 2));
	$table->construct_header("", array("colspan" => 2));

	$table->construct_cell("<strong>API URL</strong>", array('width' => '25%'));
	$table->construct_cell("https://api.cloudflare.com/client/v4/", array('width' => '25%'));
	$table->construct_cell("<strong>Plugin Version</strong>", array('width' => '200'));
	$table->construct_cell(CLOUDFLARE_MANAGER_VERSION, array('width' => '200'));
	$table->construct_row();

	$table->construct_cell("<strong>Domain</strong>", array('width' => '25%'));
	$table->construct_cell(htmlspecialchars_uni($mybb->settings['cloudflare_domain']), array('width' => '25%'));
	$table->construct_cell("<strong>DNS Status</strong>", array('width' => '25%'));
	$table->construct_cell($dns_status, array('width' => '25%'));
	$table->construct_row();

	$table->construct_cell("<strong>Email Address</strong>", array('width' => '25%'));
	$table->construct_cell(htmlspecialchars_uni($mybb->settings['cloudflare_email']), array('width' => '25%'));
	$table->construct_cell('<strong>Zone ID</strong>', array('width' => '25%'));
	$table->construct_cell($zoneId ? htmlspecialchars_uni($zoneId) : '<em>Refresh overview to load zone context.</em>', array('width' => '25%'));
	$table->construct_row();

	$table->construct_cell("<strong>API Key</strong>", array('width' => '200'));
	$table->construct_cell(htmlspecialchars_uni($mybb->settings['cloudflare_api']), array('width' => '200'));
	$table->construct_cell("<strong>CloudFlare Settings</strong>", array('width' => '25%'));
	if($accountId)
	{
		$cloudflareSettingsLink = "<a href=\"https://dash.cloudflare.com/" . htmlspecialchars_uni($accountId) . "/" . htmlspecialchars_uni($mybb->settings['cloudflare_domain']) . "\" target=\"_blank\" rel=\"noopener noreferrer\">View/Modify</a>";
	}
	else
	{
		$cloudflareSettingsLink = "<em>Refresh overview to load account context.</em>";
	}
	$table->construct_cell($cloudflareSettingsLink, array('width' => '25%'));
	$table->construct_row();

	$table->output("General Information");

	echo "<p><em>Traffic statistics are cached for five minutes. "
		. cloudflare_overview_statistics_link('Load cached stats')
		. ", "
		. cloudflare_overview_statistics_link('refresh daily from CloudFlare now', true, 'daily')
		. ".</em></p>";
	if($weeklyRefreshMoved)
	{
		echo "<p><em>Weekly live refresh was moved out of the overview page to avoid ACP timeouts. Use the warm-cache action below instead.</em></p>";
	}
	echo "<p><em>Traffic metrics below are sourced from the CloudFlare GraphQL adaptive requests dataset and report requests, visits, and bandwidth for eyeball traffic.</em></p>";

	if($today_has_statistics)
	{
		$table = new Table;
		$table->construct_header("Requests");
		$table->construct_cell(my_number_format($today_results['requests']));

		$table->construct_header("Visits");
		$table->construct_cell(my_number_format($today_results['visits']));

		$table->construct_header("Bandwidth Usage");
		$table->construct_cell($today_results['bandwidth']);
		$table->construct_row();

		$table->output("Todays Traffic");
	}
	else
	{
		echo "<p><em>Today's traffic is not cached yet. Use the daily refresh link above to fetch it on demand.</em></p>";
	}

	if($week_has_statistics)
	{
		$table = new Table;
		$table->construct_header("Requests");
		$table->construct_cell(my_number_format($week_results['requests']));

		$table->construct_header('Visits');
		$table->construct_cell(my_number_format($week_results['visits']));

		$table->construct_header('Bandwidth Usage');
		$table->construct_cell($week_results['bandwidth']);
		$table->construct_row();

		$table->output('Weekly Traffic');
	}
	else
	{
		echo "<p><em>Weekly traffic is not cached yet.</em></p>";
	}

	echo '<form action="index.php?module=cloudflare-overview" method="post">'
		. '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '" />'
		. '<input type="hidden" name="warm_weekly_stats" value="1" />'
		. '<div class="buttons">'
		. '<input type="submit" class="button" value="Warm Weekly Traffic Cache" />'
		. '</div>'
		. '</form>';


	$page->output_footer();
}

?>
