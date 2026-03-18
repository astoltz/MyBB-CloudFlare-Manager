<?php

if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

function cloudflare_sync_default_options(array $options = array())
{
	return array_merge(array(
		'backfill_notes' => true,
		'create_missing' => true,
		'overwrite_notes' => false,
		'max_usernames' => 4,
	), $options);
}

function cloudflare_sync_audit($db, $cloudflare, array $options = array())
{
	$options = cloudflare_sync_default_options($options);
	$rules = $cloudflare->get_all_access_rules();
	if(isset($rules['errors']))
	{
		return array('errors' => $rules['errors']);
	}

	$ipRules = array();
	foreach($rules as $rule)
	{
		if(!is_object($rule) || !isset($rule->configuration) || !is_object($rule->configuration))
		{
			continue;
		}

		if((string)$rule->configuration->target !== 'ip')
		{
			continue;
		}

		$ip = trim((string)$rule->configuration->value);
		if($ip === '')
		{
			continue;
		}

		$ipRules[$ip] = array(
			'id' => isset($rule->id) ? (string)$rule->id : '',
			'ip' => $ip,
			'mode' => isset($rule->mode) ? (string)$rule->mode : '',
			'notes' => isset($rule->notes) ? trim((string)$rule->notes) : '',
			'raw' => $rule,
		);
	}

	$banfilters = cloudflare_sync_fetch_ip_banfilters($db);
	$allIps = array_values(array_unique(array_merge(array_keys($banfilters), array_keys($ipRules))));
	$contexts = cloudflare_sync_build_contexts($db, $allIps, $banfilters, $options);

	$plan = array(
		'update_notes' => array(),
		'create_rules' => array(),
		'cloudflare_only' => array(),
	);
	$suggestedNotes = array();

	foreach($ipRules as $ip => $rule)
	{
		$note = cloudflare_sync_build_suggested_note($ip, $contexts[$ip]);
		if($note !== '')
		{
			$suggestedNotes[$ip] = $note;
		}

		if($note !== '' && !empty($options['backfill_notes']))
		{
			$currentNotes = trim((string)$rule['notes']);
			$needsUpdate = ($currentNotes === '');
			if(!$needsUpdate && !empty($options['overwrite_notes']) && $currentNotes !== $note)
			{
				$needsUpdate = true;
			}

			if($needsUpdate)
			{
				$plan['update_notes'][] = array(
					'rule_id' => $rule['id'],
					'ip' => $ip,
					'mode' => $rule['mode'],
					'current_notes' => $currentNotes,
					'suggested_notes' => $note,
				);
			}
		}

		if(!isset($banfilters[$ip]))
		{
			$plan['cloudflare_only'][] = array(
				'rule_id' => $rule['id'],
				'ip' => $ip,
				'mode' => $rule['mode'],
				'notes' => $rule['notes'],
			);
		}
	}

	if(!empty($options['create_missing']))
	{
		foreach($banfilters as $ip => $banfilter)
		{
			if(isset($ipRules[$ip]))
			{
				continue;
			}

			$note = cloudflare_sync_build_suggested_note($ip, $contexts[$ip]);
			$plan['create_rules'][] = array(
				'ip' => $ip,
				'mode' => 'block',
				'suggested_notes' => $note,
				'banfilter_fid' => (int)$banfilter['fid'],
			);
		}
	}

	return array(
		'options' => $options,
		'counts' => array(
			'cloudflare_ip_rules' => count($ipRules),
			'mybb_ip_banfilters' => count($banfilters),
			'update_notes' => count($plan['update_notes']),
			'create_rules' => count($plan['create_rules']),
			'cloudflare_only' => count($plan['cloudflare_only']),
		),
		'rules' => $ipRules,
		'contexts' => $contexts,
		'suggested_notes' => $suggestedNotes,
		'plan' => $plan,
	);
}

function cloudflare_sync_apply($cloudflare, array $audit, array $options = array())
{
	$options = array_merge(array(
		'apply_updates' => true,
		'apply_creates' => true,
	), $options);

	$result = array(
		'updated_notes' => 0,
		'created_rules' => 0,
		'errors' => array(),
	);

	if(!empty($options['apply_updates']))
	{
		foreach($audit['plan']['update_notes'] as $item)
		{
			$response = $cloudflare->update_access_rule_by_id($item['rule_id'], $item['mode'], $item['ip'], $item['suggested_notes'], 'ip');
			if(!empty($response['success']))
			{
				$result['updated_notes']++;
				continue;
			}

			$errorMessage = is_array($response) && !empty($response['errors']) ? $response['errors'] : 'Unknown CloudFlare error.';
			$result['errors'][] = 'update ' . $item['ip'] . ': ' . $errorMessage;
		}
	}

	if(!empty($options['apply_creates']))
	{
		foreach($audit['plan']['create_rules'] as $item)
		{
			$response = $cloudflare->upsert_ip_access_rule($item['mode'], $item['ip'], $item['suggested_notes']);
			if(!empty($response['success']))
			{
				$result['created_rules']++;
				continue;
			}

			$errorMessage = is_array($response) && !empty($response['errors']) ? $response['errors'] : 'Unknown CloudFlare error.';
			$result['errors'][] = 'create ' . $item['ip'] . ': ' . $errorMessage;
		}
	}

	return $result;
}

function cloudflare_sync_fetch_ip_banfilters($db)
{
	$rows = array();
	$query = $db->simple_select('banfilters', 'fid,filter,dateline,lastuse', "type='1'", array('order_by' => 'fid', 'order_dir' => 'ASC'));
	while($row = $db->fetch_array($query))
	{
		$ip = trim((string)$row['filter']);
		if($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP))
		{
			continue;
		}

		$rows[$ip] = array(
			'fid' => (int)$row['fid'],
			'ip' => $ip,
			'dateline' => (int)$row['dateline'],
			'lastuse' => (int)$row['lastuse'],
		);
	}

	return $rows;
}

function cloudflare_sync_build_contexts($db, array $ips, array $banfilters, array $options)
{
	$contexts = array();
	foreach($ips as $ip)
	{
		$contexts[$ip] = array(
			'banfilter' => isset($banfilters[$ip]) ? $banfilters[$ip] : null,
			'adminlog_add' => null,
			'users' => array(),
			'posts' => array(
				'count' => 0,
				'usernames' => array(),
				'latest_dateline' => 0,
				'latest_pid' => 0,
				'latest_tid' => 0,
			),
		);
	}

	if(empty($contexts))
	{
		return $contexts;
	}

	$contexts = cloudflare_sync_attach_adminlog_adds($db, $contexts);
	$contexts = cloudflare_sync_attach_user_matches($db, $contexts);
	$contexts = cloudflare_sync_attach_post_matches($db, $contexts, (int)$options['max_usernames']);

	return $contexts;
}

function cloudflare_sync_attach_adminlog_adds($db, array $contexts)
{
	$adds = array();
	$adminUids = array();
	$query = $db->simple_select(
		'adminlog',
		'uid,dateline,data',
		"module='config-banning' AND action='add'",
		array('order_by' => 'dateline', 'order_dir' => 'DESC')
	);

	while($row = $db->fetch_array($query))
	{
		$parsed = cloudflare_sync_parse_banfilter_adminlog_data((string)$row['data']);
		if($parsed === null || (int)$parsed['type'] !== 1)
		{
			continue;
		}

		$ip = trim((string)$parsed['filter']);
		if(!isset($contexts[$ip]) || isset($adds[$ip]))
		{
			continue;
		}

		$adds[$ip] = array(
			'dateline' => (int)$row['dateline'],
			'admin_uid' => (int)$row['uid'],
			'admin_username' => '',
		);
		$adminUids[(int)$row['uid']] = true;
	}

	$usernames = array();
	if(!empty($adminUids))
	{
		$uidSql = implode(',', array_map('intval', array_keys($adminUids)));
		$query = $db->simple_select('users', 'uid,username', "uid IN ({$uidSql})");
		while($row = $db->fetch_array($query))
		{
			$usernames[(int)$row['uid']] = (string)$row['username'];
		}
	}

	foreach($adds as $ip => $entry)
	{
		$uid = (int)$entry['admin_uid'];
		$entry['admin_username'] = isset($usernames[$uid]) ? $usernames[$uid] : ('uid ' . $uid);
		$contexts[$ip]['adminlog_add'] = $entry;
	}

	return $contexts;
}

function cloudflare_sync_attach_user_matches($db, array $contexts)
{
	$ips = array_keys($contexts);
	$ipSql = cloudflare_sync_sql_list($db, $ips);
	if($ipSql === '')
	{
		return $contexts;
	}

	$query = $db->query("
		SELECT uid, username, INET6_NTOA(regip) AS regip_text, INET6_NTOA(lastip) AS lastip_text
		FROM " . TABLE_PREFIX . "users
		WHERE (regip <> '' AND INET6_NTOA(regip) IN ({$ipSql}))
		   OR (lastip <> '' AND INET6_NTOA(lastip) IN ({$ipSql}))
	");

	while($row = $db->fetch_array($query))
	{
		$uid = (int)$row['uid'];
		$username = (string)$row['username'];
		$regip = trim((string)$row['regip_text']);
		$lastip = trim((string)$row['lastip_text']);

		foreach(array($regip => 'regip', $lastip => 'lastip') as $ip => $field)
		{
			if($ip === '' || !isset($contexts[$ip]))
			{
				continue;
			}

			if(!isset($contexts[$ip]['users'][$uid]))
			{
				$contexts[$ip]['users'][$uid] = array(
					'uid' => $uid,
					'username' => $username,
					'fields' => array(),
				);
			}

			$contexts[$ip]['users'][$uid]['fields'][$field] = true;
		}
	}

	foreach($contexts as &$context)
	{
		if(!empty($context['users']))
		{
			$context['users'] = array_values($context['users']);
		}
	}
	unset($context);

	return $contexts;
}

function cloudflare_sync_attach_post_matches($db, array $contexts, $maxUsernames)
{
	$ips = array_keys($contexts);
	$ipSql = cloudflare_sync_sql_list($db, $ips);
	if($ipSql === '')
	{
		return $contexts;
	}

	$query = $db->query("
		SELECT pid, tid, uid, username, dateline, INET6_NTOA(ipaddress) AS ip_text
		FROM " . TABLE_PREFIX . "posts
		WHERE ipaddress <> ''
		  AND INET6_NTOA(ipaddress) IN ({$ipSql})
		ORDER BY dateline DESC, pid DESC
	");

	while($row = $db->fetch_array($query))
	{
		$ip = trim((string)$row['ip_text']);
		if($ip === '' || !isset($contexts[$ip]))
		{
			continue;
		}

		$contexts[$ip]['posts']['count']++;
		if($contexts[$ip]['posts']['latest_dateline'] === 0)
		{
			$contexts[$ip]['posts']['latest_dateline'] = (int)$row['dateline'];
			$contexts[$ip]['posts']['latest_pid'] = (int)$row['pid'];
			$contexts[$ip]['posts']['latest_tid'] = (int)$row['tid'];
		}

		$username = trim((string)$row['username']);
		if($username !== '' && count($contexts[$ip]['posts']['usernames']) < $maxUsernames && !in_array($username, $contexts[$ip]['posts']['usernames'], true))
		{
			$contexts[$ip]['posts']['usernames'][] = $username;
		}
	}

	return $contexts;
}

function cloudflare_sync_build_suggested_note($ip, array $context)
{
	$parts = array();

	if(!empty($context['adminlog_add']))
	{
		$parts[] = 'MyBB IP banfilter added ' . cloudflare_sync_format_date($context['adminlog_add']['dateline']) . ' by ' . $context['adminlog_add']['admin_username'];
	}
	elseif(!empty($context['banfilter']) && !empty($context['banfilter']['dateline']))
	{
		$parts[] = 'MyBB IP banfilter present since ' . cloudflare_sync_format_date($context['banfilter']['dateline']);
	}
	elseif(!empty($context['banfilter']))
	{
		$parts[] = 'MyBB IP banfilter sync';
	}

	if(!empty($context['users']))
	{
		$userParts = array();
		foreach(array_slice($context['users'], 0, 3) as $user)
		{
			$fields = !empty($user['fields']) ? implode('/', array_keys($user['fields'])) : 'match';
			$userParts[] = $user['username'] . ' (#' . (int)$user['uid'] . ', ' . $fields . ')';
		}
		$parts[] = 'users: ' . implode(', ', $userParts);
	}

	if(!empty($context['posts']['count']))
	{
		$postPart = 'posts: ' . (int)$context['posts']['count'];
		if(!empty($context['posts']['usernames']))
		{
			$postPart .= ' (' . implode(', ', $context['posts']['usernames']) . ')';
		}
		$parts[] = $postPart;
	}

	$note = trim(implode('; ', $parts));
	return cloudflare_sync_truncate_note($note);
}

function cloudflare_sync_parse_banfilter_adminlog_data($data)
{
	$matches = array();
	if(preg_match('/i:0;i:(\d+);i:1;s:\d+:"([^"]+)";i:2;i:(\d+)/', $data, $matches))
	{
		return array(
			'fid' => (int)$matches[1],
			'filter' => (string)$matches[2],
			'type' => (int)$matches[3],
		);
	}

	if(preg_match('/i:0;s:\d+:"(\d+)";i:1;s:\d+:"([^"]+)";i:2;i:(\d+)/', $data, $matches))
	{
		return array(
			'fid' => (int)$matches[1],
			'filter' => (string)$matches[2],
			'type' => (int)$matches[3],
		);
	}

	return null;
}

function cloudflare_sync_sql_list($db, array $values)
{
	$escaped = array();
	foreach($values as $value)
	{
		$value = trim((string)$value);
		if($value === '')
		{
			continue;
		}

		$escaped[] = "'" . $db->escape_string($value) . "'";
	}

	return implode(',', $escaped);
}

function cloudflare_sync_format_date($timestamp)
{
	$timestamp = (int)$timestamp;
	if($timestamp <= 0)
	{
		return 'unknown date';
	}

	return gmdate('Y-m-d', $timestamp);
}

function cloudflare_sync_truncate_note($note, $maxLength = 500)
{
	$note = trim((string)$note);
	if($note === '')
	{
		return '';
	}

	if(function_exists('mb_strlen') && function_exists('mb_substr'))
	{
		if(mb_strlen($note, 'UTF-8') <= $maxLength)
		{
			return $note;
		}

		return rtrim(mb_substr($note, 0, $maxLength - 3, 'UTF-8')) . '...';
	}

	if(strlen($note) <= $maxLength)
	{
		return $note;
	}

	return rtrim(substr($note, 0, $maxLength - 3)) . '...';
}
