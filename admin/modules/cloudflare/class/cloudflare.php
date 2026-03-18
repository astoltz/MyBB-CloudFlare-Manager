<?php

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

class cloudflare {

	public $zone = '';
	private $api_key = '';
	private $api_token = '';
	public $email = '';
	public $api_url = 'https://api.cloudflare.com/client/v4/';
	public $zone_id;
	private $access_rules_cache = null;
	private $dns_status_cache_ttl = 1800;
	private $statistics_cache_ttl = 300;
	private $http_connect_timeout = 5;
	private $http_timeout = 15;

	public function __construct(MyBB $mybb, $zone_id) {
		$this->zone = $mybb->settings['cloudflare_domain'];
		$this->api_key = $mybb->settings['cloudflare_api'];
		$this->api_token = !empty($mybb->settings['cloudflare_api_token']) ? trim((string)$mybb->settings['cloudflare_api_token']) : '';
		$this->email = $mybb->settings['cloudflare_email'];
		if (!$zone_id)
		{
			$zone_id = $this->get_cached_zone_id();
		}
		$this->zone_id = $zone_id;
	}

	public function get_cached_zone_id()
	{
		global $cache;

		if (!isset($cache))
		{
			return $this->zone_id;
		}

		$zoneId = $cache->read('cloudflare_zone_id');
		if (!empty($zoneId))
		{
			$this->zone_id = $zoneId;
			return $zoneId;
		}

		return $this->zone_id;
	}

	public function get_cached_account_id()
	{
		global $cache;

		if (!isset($cache))
		{
			return false;
		}

		$accountId = $cache->read('cloudflare_account_id');
		if (!empty($accountId))
		{
			return $accountId;
		}

		return false;
	}

	public function ensure_zone_context()
	{
		if ($this->get_cached_zone_id() && $this->get_cached_account_id())
		{
			return $this->zone_id;
		}

		return $this->get_cloudflare_zone_id();
	}

	public function request($request_data, $custom_url = false)
	{
		$method = isset($request_data['method']) ? strtoupper((string)$request_data['method']) : 'GET';
		$postFields = isset($request_data['post_fields']) ? $request_data['post_fields'] : null;

		if (!$custom_url)
		{
			$url = $this->api_url . $request_data['endpoint'];

			if (isset($request_data['url_parameters']))
			{
				$url = $url . "?". http_build_query($request_data['url_parameters']);
			}
		}
		else
		{
			$url = $custom_url;
		}

		if ($custom_url)
		{
			return $this->execute_http_request($url, array(), $method, $postFields, false);
		}

		return $this->execute_http_request($url, $this->build_api_headers(), $method, $postFields, true);
	}

	public function graphql_request($query, array $variables = array())
	{
		return $this->execute_http_request($this->api_url . 'graphql', $this->build_api_headers(), 'POST', array(
			'query' => $query,
			'variables' => $variables,
		), true);
	}

	private function build_api_headers()
	{
		global $plugins;

		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
		);

		if ($this->api_token !== '')
		{
			$headers[] = 'Authorization: Bearer ' . $this->api_token;
		}
		else
		{
			$headers[] = "X-Auth-Key: {$this->api_key}";
			$headers[] = "X-Auth-Email: {$this->email}";
		}

		if (isset($plugins))
		{
			$headers = $plugins->run_hooks('cloudflare_api_headers', $headers);
		}

		return $headers;
	}

	private function execute_http_request($url, array $headers, $method, $postFields, $decodeJson)
	{
		global $plugins;

		if (isset($plugins))
		{
			$requestContext = array(
				'url' => $url,
				'headers' => $headers,
				'method' => $method,
				'post_fields' => $postFields,
				'decode_json' => $decodeJson,
			);
			$requestContext = $plugins->run_hooks('cloudflare_request_context', $requestContext);
			if (is_array($requestContext))
			{
				$url = isset($requestContext['url']) ? $requestContext['url'] : $url;
				$headers = isset($requestContext['headers']) && is_array($requestContext['headers']) ? $requestContext['headers'] : $headers;
				$method = isset($requestContext['method']) ? $requestContext['method'] : $method;
				$postFields = array_key_exists('post_fields', $requestContext) ? $requestContext['post_fields'] : $postFields;
				$decodeJson = isset($requestContext['decode_json']) ? (bool)$requestContext['decode_json'] : $decodeJson;
			}
		}

		$requestResult = $this->perform_curl_request($url, $headers, $method, $postFields, null);
		if ($this->should_retry_with_ipv4($requestResult))
		{
			$ipv4Retry = $this->perform_curl_request($url, $headers, $method, $postFields, (defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : null));
			if ($ipv4Retry['error'] === '' && is_string($ipv4Retry['body']) && $ipv4Retry['body'] !== '')
			{
				$requestResult = $ipv4Retry;
			}
			else
			{
				$requestResult = $ipv4Retry;
			}
		}

		$httpResult = $requestResult['body'];
		$error = $requestResult['error'];
		$httpCode = $requestResult['http_code'];

		if ($decodeJson === false)
		{
			if ($error !== '' || !is_string($httpResult) || $httpCode < 200 || $httpCode >= 300)
			{
				return '';
			}

			return $httpResult;
		}

		if ($error !== '')
		{
			return $this->build_error_response('CloudFlare request failed: ' . $error, $httpCode);
		}

		if (!is_string($httpResult) || $httpResult === '')
		{
			return $this->build_error_response('CloudFlare API returned an empty response.', $httpCode);
		}

		$decoded = json_decode($httpResult);
		if (!is_object($decoded))
		{
			return $this->build_error_response('CloudFlare API returned malformed JSON.', $httpCode);
		}

		if ($httpCode >= 400 && (!isset($decoded->errors) || !is_array($decoded->errors) || empty($decoded->errors)))
		{
			return $this->build_error_response('CloudFlare API returned HTTP ' . $httpCode . '.', $httpCode);
		}

		return $decoded;
	}

	private function perform_curl_request($url, array $headers, $method, $postFields, $ipResolve)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_USERAGENT, "MyBB CloudFlare Manager Plugin");
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$this->http_connect_timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, (int)$this->http_timeout);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_ENCODING, '');

		if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS'))
		{
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		}

		if ($ipResolve !== null && defined('CURLOPT_IPRESOLVE'))
		{
			curl_setopt($ch, CURLOPT_IPRESOLVE, $ipResolve);
		}

		if ($method === 'POST')
		{
			curl_setopt($ch, CURLOPT_POST, 1);
		}
		elseif ($method !== 'GET')
		{
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		}

		if ($postFields !== null)
		{
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postFields));
		}

		if (!empty($headers))
		{
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}

		$body = curl_exec($ch);
		$result = array(
			'body' => $body,
			'error' => curl_error($ch),
			'errno' => curl_errno($ch),
			'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
		);
		curl_close($ch);

		return $result;
	}

	private function should_retry_with_ipv4(array $requestResult)
	{
		if (!defined('CURL_IPRESOLVE_V4'))
		{
			return false;
		}

		$retryableErrors = array(
			6,  // CURLE_COULDNT_RESOLVE_HOST
			7,  // CURLE_COULDNT_CONNECT
			35, // CURLE_SSL_CONNECT_ERROR
		);

		return in_array((int)$requestResult['errno'], $retryableErrors, true);
	}

	private function build_error_response($message, $code)
	{
		$error = new stdClass();
		$error->code = (int)$code;
		$error->message = $message;

		$response = new stdClass();
		$response->success = false;
		$response->errors = array($error);
		$response->messages = array();
		$response->result = null;

		return $response;
	}

	public function get_cloudflare_zone_id()
	{
		global $cache;
		if ($this->get_cached_zone_id() && $this->get_cached_account_id())
		{
			return $this->zone_id;
		}

		$data = $this->request(
			array(
				'endpoint' => 'zones',
				'url_parameters' => array (
					'name' => $this->zone
				)
			)
		);

		if (
			!is_object($data) ||
			(isset($data->errors) && is_array($data->errors) && !empty($data->errors))
		)
		{
			$errors = $this->get_all_errors($data);
			$errors[] = "Check your API options are correct in settings";
			return array('errors' => $errors);
		}

		if (
			!isset($data->result) ||
			!is_array($data->result) ||
			!isset($data->result[0]) ||
			!is_object($data->result[0]) ||
			empty($data->result[0]->id)
		)
		{
			return array('errors' => array('CloudFlare did not return a matching zone for the configured domain.'));
		}

		$this->zone_id = $data->result[0]->id;
		$cache->update('cloudflare_zone_id', $data->result[0]->id);
		if (isset($data->result[0]->account->id))
		{
			$cache->update('cloudflare_account_id', $data->result[0]->account->id);
		}

		return $data->result[0]->id;
	}

	public function dns_status($allowLiveLookup = true)
	{
		global $cache;

		$now = defined('TIME_NOW') ? TIME_NOW : time();
		if (isset($cache))
		{
			$dnsCache = $cache->read('cloudflare_dns_status');
			if (is_array($dnsCache))
			{
				foreach ($this->get_zone_cache_keys() as $zoneKey)
				{
					if (
						isset($dnsCache[$zoneKey]) &&
						is_array($dnsCache[$zoneKey]) &&
						isset($dnsCache[$zoneKey]['expires_at']) &&
						(int)$dnsCache[$zoneKey]['expires_at'] >= $now
					)
					{
						return !empty($dnsCache[$zoneKey]['active']);
					}
				}
			}
		}

		if (!$allowLiveLookup)
		{
			return null;
		}

		$dns = @dns_get_record($this->zone, DNS_NS);
		$active = false;

		if (is_array($dns))
		{
			foreach($dns as $ns)
			{
				if(!empty($ns['target']) && strpos($ns['target'], ".ns.cloudflare.com") !== false)
				{
					$active = true;
					break;
				}
			}
		}

		if (isset($cache))
		{
			$dnsCache = $cache->read('cloudflare_dns_status');
			if (!is_array($dnsCache))
			{
				$dnsCache = array();
			}

			$cacheEntry = array(
				'active' => $active ? 1 : 0,
				'expires_at' => $now + (int)$this->dns_status_cache_ttl,
			);

			foreach ($this->get_zone_cache_keys() as $zoneKey)
			{
				$dnsCache[$zoneKey] = $cacheEntry;
			}
			$cache->update('cloudflare_dns_status', $dnsCache);
		}

		return $active;
	}

	public function objectToArray($d) {
		if(is_object($d)) {
			$d = get_object_vars($d);
		}

		if(is_array($d)) {
			return array_map(array($this, 'objectToArray'), $d); // recursive
		} else {
			return $d;
		}
	}

	public function get_statistics($interval, $allowLiveFetch = true)
	{
		$minutes = abs((int)$interval);
		if ($minutes < 1)
		{
			$minutes = 1;
		}

		$endTimestamp = defined('TIME_NOW') ? TIME_NOW : time();
		$alignedEndTimestamp = $endTimestamp - ($endTimestamp % $this->statistics_cache_ttl);
		if ($alignedEndTimestamp <= 0)
		{
			$alignedEndTimestamp = $endTimestamp;
		}

		$cached = $this->get_cached_statistics($minutes, $alignedEndTimestamp);
		if ($cached !== null)
		{
			return $this->build_statistics_response(
				(int)$cached['requests'],
				(int)$cached['visits'],
				(float)$cached['bandwidth'],
				(int)$cached['chunks'],
				true,
				$alignedEndTimestamp
			);
		}

		if (!$allowLiveFetch)
		{
			return $this->graph_ql_statistics_error(array(
				'No cached CloudFlare statistics are available yet for this range.',
			));
		}

		$startTimestamp = $alignedEndTimestamp - ($minutes * 60);
		$maxChunkSeconds = 86400;
		$cursor = $startTimestamp;
		$totalRequests = 0;
		$totalVisits = 0;
		$totalBandwidth = 0.0;
		$chunks = 0;

		while ($cursor < $alignedEndTimestamp)
		{
			$chunkEnd = min($cursor + $maxChunkSeconds, $alignedEndTimestamp);
			$chunkTotals = $this->get_statistics_chunk($cursor, $chunkEnd);
			if (isset($chunkTotals['errors']))
			{
				return $this->graph_ql_statistics_error($chunkTotals['errors']);
			}

			$totalRequests += (int)$chunkTotals['requests'];
			$totalVisits += (int)$chunkTotals['visits'];
			$totalBandwidth += (float)$chunkTotals['bandwidth'];
			$chunks++;
			$cursor = $chunkEnd;
		}

		$this->store_cached_statistics($minutes, $alignedEndTimestamp, $totalRequests, $totalVisits, $totalBandwidth, $chunks);

		return $this->build_statistics_response(
			$totalRequests,
			$totalVisits,
			$totalBandwidth,
			$chunks,
			false,
			$alignedEndTimestamp
		);
	}

	private function get_statistics_chunk($startTimestamp, $endTimestamp)
	{
		$query = <<<'GRAPHQL'
query CloudflareZoneAnalytics($zoneTag: string, $start: Time, $end: Time) {
  viewer {
    zones(filter: { zoneTag: $zoneTag }) {
      totals: httpRequestsAdaptiveGroups(
        limit: 1
        filter: { datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball" }
      ) {
        count
        sum {
          edgeResponseBytes
          visits
        }
      }
    }
  }
}
GRAPHQL;

		$response = $this->graphql_request($query, array(
			'zoneTag' => $this->zone_id,
			'start' => gmdate('Y-m-d\TH:i:s\Z', $startTimestamp),
			'end' => gmdate('Y-m-d\TH:i:s\Z', $endTimestamp),
		));

		if (!is_object($response))
		{
			return array('errors' => array('CloudFlare GraphQL statistics request failed.'));
		}

		if (!empty($response->errors) && is_array($response->errors))
		{
			return array('errors' => $this->graphql_errors_to_messages($response->errors));
		}

		if (
			!isset($response->data->viewer->zones) ||
			!is_array($response->data->viewer->zones) ||
			!isset($response->data->viewer->zones[0]) ||
			!isset($response->data->viewer->zones[0]->totals) ||
			!is_array($response->data->viewer->zones[0]->totals) ||
			!isset($response->data->viewer->zones[0]->totals[0])
		)
		{
			return array('errors' => array('CloudFlare GraphQL statistics returned no totals for the selected range.'));
		}

		$totals = $response->data->viewer->zones[0]->totals[0];
		$sum = isset($totals->sum) && is_object($totals->sum) ? $totals->sum : (object)array();

		return array(
			'requests' => isset($totals->count) ? (int)$totals->count : 0,
			'visits' => isset($sum->visits) ? (int)$sum->visits : 0,
			'bandwidth' => isset($sum->edgeResponseBytes) ? (float)$sum->edgeResponseBytes : 0,
		);
	}

	function dev_mode($setting = NULL)
	{
		$endpoint = "zones/{$this->zone_id}/settings/development_mode";

		if (is_null($setting))
		{
			$data = $this->request(
				array (
					'endpoint' => $endpoint
				)
			);

			return $data;
		}
		else
		{
			$data = $this->request(
				array (
					'endpoint' => $endpoint,
					'method' => 'PATCH',
					'post_fields' => array (
						'value' => $setting
					)
				)
			);

			return $data;
		}
	}


	public function whitelist_ip($ip, $notes = '')
	{
		return $this->upsert_ip_or_range_access_rule("whitelist", $ip, $notes);
	}

	public function blacklist_ip($ip, $notes = '')
	{
		return $this->upsert_ip_or_range_access_rule("block", $ip, $notes);
	}

	public function challenge_ip($ip, $notes = '')
	{
		return $this->upsert_ip_or_range_access_rule("challenge", $ip, $notes);
	}

	public function update_access_rule($mode, $ip, $notes = '')
	{
		return $this->upsert_ip_access_rule($mode, $ip, $notes);
	}

	public function create_access_rule($mode, $target, $value, $notes = '')
	{
		$data = $this->request (
			array (
				'endpoint' => "zones/{$this->zone_id}/firewall/access_rules/rules",
				'method' => 'POST',
				'post_fields' => $this->build_access_rule_payload($mode, $target, $value, $notes)
			)
		);
		$this->clear_access_rules_cache();

		return $this->access_rule_write_result($data, "CloudFlare could not create the access rule.");
	}

	public function update_access_rule_by_id($rule_id, $mode, $value, $notes = '', $target = 'ip')
	{
		$rule_id = trim((string)$rule_id);
		if ($rule_id === '')
		{
			return array('errors' => 'Missing CloudFlare rule ID.');
		}

		$data = $this->request(
			array(
				'endpoint' => "zones/{$this->zone_id}/firewall/access_rules/rules/{$rule_id}",
				'method' => 'PATCH',
				'post_fields' => $this->build_access_rule_payload($mode, $target, $value, $notes)
			)
		);
		$this->clear_access_rules_cache();

		return $this->access_rule_write_result($data, "CloudFlare could not update the access rule.");
	}

	public function upsert_ip_access_rule($mode, $ip, $notes = '')
	{
		return $this->upsert_access_rule($mode, 'ip', $ip, $notes);
	}

	public function upsert_ip_or_range_access_rule($mode, $value, $notes = '')
	{
		$parsed = $this->normalize_access_rule_value($value);
		if(isset($parsed['errors']))
		{
			return array('errors' => $parsed['errors']);
		}

		return $this->upsert_access_rule($mode, $parsed['target'], $parsed['value'], $notes);
	}

	public function upsert_access_rule($mode, $target, $value, $notes = '')
	{
		$existing = $this->find_access_rule_by_value($value, $target);
		if (isset($existing['errors']))
		{
			return $existing;
		}

		if (is_object($existing))
		{
			return $this->update_access_rule_by_id($existing->id, $mode, $value, $notes, $target);
		}

		return $this->create_access_rule($mode, $target, $value, $notes);
	}

	public function ipv46_setting($setting = NULL)
	{
		$endpoint = "zones/{$this->zone_id}/settings/ipv6";

		if (is_null($setting))
		{
			$data = $this->request(
				array (
					'endpoint' => $endpoint
				)
			);
			return $data;
		}

		$data = $this->request(
			array (
				'endpoint' => $endpoint,
				'method' => 'PATCH',
				'post_fields' => array (
					'value' => $setting
				)
			)
		);

		return $data;
	}

	public function get_managed_http_config_status()
	{
		$entrypoint = $this->get_phase_entrypoint_ruleset('http_config_settings');
		if (!$entrypoint['success'])
		{
			return array('errors' => $entrypoint['errors']);
		}

		$ruleset = $entrypoint['ruleset'];
		$managed = $this->managed_http_config_rules();

		$status = array(
			'ruleset' => $ruleset,
			'ruleset_missing' => !is_object($ruleset),
			'ruleset_id' => (is_object($ruleset) && isset($ruleset->id)) ? (string)$ruleset->id : '',
			'disable_rum' => false,
			'disable_rum_rule' => null,
			'disable_rum_rule_id' => '',
			'disable_zaraz' => false,
			'disable_zaraz_rule' => null,
			'disable_zaraz_rule_id' => '',
		);

		foreach ($managed as $settingKey => $metadata)
		{
			$rule = $this->find_ruleset_rule_by_ref($ruleset, $metadata['ref']);
			$isEnabled = (is_object($rule) && (!isset($rule->enabled) || $rule->enabled));

			$status[$settingKey] = $isEnabled;
			$status[$settingKey . '_rule'] = $rule;
			$status[$settingKey . '_rule_id'] = (is_object($rule) && isset($rule->id)) ? (string)$rule->id : '';
		}

		return $status;
	}

	public function set_managed_http_config_setting($settingKey, $disabled)
	{
		$managed = $this->managed_http_config_rules();
		if (!isset($managed[$settingKey]))
		{
			return array('errors' => array('Unsupported CloudFlare configuration setting: ' . $settingKey));
		}

		$metadata = $managed[$settingKey];
		$entrypoint = $this->get_phase_entrypoint_ruleset('http_config_settings');
		if (!$entrypoint['success'])
		{
			return array('errors' => $entrypoint['errors']);
		}

		$ruleset = $entrypoint['ruleset'];
		if ($disabled)
		{
			if (!is_object($ruleset))
			{
				$created = $this->create_zone_ruleset(
					'http_config_settings',
					'MyBB CloudFlare Manager Configuration Rules',
					'Managed by the MyBB CloudFlare Manager plugin.'
				);
				if (!$created['success'])
				{
					return array('errors' => $created['errors']);
				}

				$ruleset = $created['result'];
			}

			$rule = $this->find_ruleset_rule_by_ref($ruleset, $metadata['ref']);
			$payload = $this->build_managed_http_config_rule_payload($metadata['ref'], $metadata['description'], $settingKey);

			if (is_object($rule) && isset($rule->id))
			{
				return $this->update_ruleset_rule(
					$ruleset->id,
					$rule->id,
					$payload,
					'CloudFlare could not update the browser integration rule.'
				);
			}

			return $this->create_ruleset_rule(
				$ruleset->id,
				$payload,
				'CloudFlare could not create the browser integration rule.'
			);
		}

		if (!is_object($ruleset))
		{
			return array('success' => true, 'noop' => true);
		}

		$rule = $this->find_ruleset_rule_by_ref($ruleset, $metadata['ref']);
		if (!is_object($rule) || !isset($rule->id))
		{
			return array('success' => true, 'noop' => true);
		}

		return $this->delete_ruleset_rule(
			$ruleset->id,
			$rule->id,
			'CloudFlare could not delete the browser integration rule.'
		);
	}

	private function get_phase_entrypoint_ruleset($phase)
	{
		$data = $this->request(
			array(
				'endpoint' => "zones/{$this->zone_id}/rulesets/phases/{$phase}/entrypoint"
			)
		);

		if (is_object($data) && !empty($data->success) && isset($data->result))
		{
			return array(
				'success' => true,
				'ruleset' => $data->result,
			);
		}

		if ($this->api_error_code_present($data, 10003))
		{
			return array(
				'success' => true,
				'ruleset' => null,
			);
		}

		$errors = $this->get_all_errors($data);
		if (empty($errors))
		{
			$errors[] = 'CloudFlare could not fetch the configuration rules entry point for phase ' . $phase . '.';
		}

		return array(
			'success' => false,
			'errors' => $errors,
			'ruleset' => null,
		);
	}

	private function create_zone_ruleset($phase, $name, $description = '')
	{
		$postFields = array(
			'name' => $name,
			'kind' => 'zone',
			'phase' => $phase,
		);

		if ($description !== '')
		{
			$postFields['description'] = $description;
		}

		$data = $this->request(
			array(
				'endpoint' => "zones/{$this->zone_id}/rulesets",
				'method' => 'POST',
				'post_fields' => $postFields,
			)
		);

		return $this->ruleset_write_result($data, 'CloudFlare could not create the configuration ruleset.');
	}

	private function create_ruleset_rule($rulesetId, array $payload, $fallback)
	{
		$data = $this->request(
			array(
				'endpoint' => "zones/{$this->zone_id}/rulesets/{$rulesetId}/rules",
				'method' => 'POST',
				'post_fields' => $payload,
			)
		);

		return $this->ruleset_write_result($data, $fallback);
	}

	private function update_ruleset_rule($rulesetId, $ruleId, array $payload, $fallback)
	{
		$data = $this->request(
			array(
				'endpoint' => "zones/{$this->zone_id}/rulesets/{$rulesetId}/rules/{$ruleId}",
				'method' => 'PATCH',
				'post_fields' => $payload,
			)
		);

		return $this->ruleset_write_result($data, $fallback);
	}

	private function delete_ruleset_rule($rulesetId, $ruleId, $fallback)
	{
		$data = $this->request(
			array(
				'endpoint' => "zones/{$this->zone_id}/rulesets/{$rulesetId}/rules/{$ruleId}",
				'method' => 'DELETE',
			)
		);

		return $this->ruleset_write_result($data, $fallback);
	}

	private function managed_http_config_rules()
	{
		return array(
			'disable_rum' => array(
				'ref' => 'mybb_cloudflare_disable_rum',
				'description' => 'Disable Cloudflare Web Analytics (RUM) for all requests in this zone.',
			),
			'disable_zaraz' => array(
				'ref' => 'mybb_cloudflare_disable_zaraz',
				'description' => 'Disable Cloudflare Zaraz for all requests in this zone.',
			),
		);
	}

	private function find_ruleset_rule_by_ref($ruleset, $ref)
	{
		if (!is_object($ruleset) || !isset($ruleset->rules) || !is_array($ruleset->rules))
		{
			return null;
		}

		foreach ($ruleset->rules as $rule)
		{
			if (!is_object($rule))
			{
				continue;
			}

			if (isset($rule->ref) && (string)$rule->ref === (string)$ref)
			{
				return $rule;
			}
		}

		return null;
	}

	private function build_managed_http_config_rule_payload($ref, $description, $settingKey)
	{
		return array(
			'ref' => $ref,
			'description' => $description,
			'expression' => 'true',
			'action' => 'set_config',
			'action_parameters' => array(
				$settingKey => true,
			),
			'enabled' => true,
		);
	}

	private function ruleset_write_result($data, $fallback)
	{
		if (is_object($data) && !empty($data->success))
		{
			return array(
				'success' => true,
				'result' => isset($data->result) ? $data->result : null,
			);
		}

		$errors = $this->get_all_errors($data);
		if (empty($errors))
		{
			$errors[] = $fallback;
		}

		return array('errors' => $errors);
	}

	private function api_error_code_present($data, $code)
	{
		if (!is_object($data) || empty($data->errors) || !is_array($data->errors))
		{
			return false;
		}

		foreach ($data->errors as $error)
		{
			if (is_object($error) && isset($error->code) && (int)$error->code === (int)$code)
			{
				return true;
			}
		}

		return false;
	}

	public function get_access_rules($page = 1, $per_page = 100)
	{
		$data = $this->request(
			array (
				'endpoint' => "zones/{$this->zone_id}/firewall/access_rules/rules",
				'url_parameters' => array(
					'page' => max(1, (int)$page),
					'per_page' => max(1, min(100, (int)$per_page))
				)
			)
		);
		return $data;
	}

	public function get_all_access_rules($per_page = 100)
	{
		if (is_array($this->access_rules_cache))
		{
			return $this->access_rules_cache;
		}

		$page = 1;
		$rules = array();

		do
		{
			$data = $this->get_access_rules($page, $per_page);
			if (!is_object($data) || empty($data->success))
			{
				return array('errors' => $this->get_all_errors($data));
			}

			if (is_array($data->result))
			{
				$rules = array_merge($rules, $data->result);
			}

			$total_pages = 1;
			if (isset($data->result_info) && isset($data->result_info->total_pages))
			{
				$total_pages = max(1, (int)$data->result_info->total_pages);
			}

			$page++;
		}
			while ($page <= $total_pages);

		$this->access_rules_cache = $rules;

		return $rules;
	}

	public function find_access_rule_by_value($value, $target = 'ip')
	{
		$value = trim((string)$value);
		$target = trim((string)$target);
		if ($value === '' || $target === '')
		{
			return null;
		}

		$rules = $this->get_all_access_rules();
		if (isset($rules['errors']))
		{
			return $rules;
		}

		foreach ($rules as $rule)
		{
			if (!isset($rule->configuration) || !is_object($rule->configuration))
			{
				continue;
			}

			if ((string)$rule->configuration->target === $target && (string)$rule->configuration->value === $value)
			{
				return $rule;
			}
		}

		return null;
	}

	public function delete_firewall_rule($rule_id)
	{
		$data = $this->request(
			array (
				'endpoint' => "zones/{$this->zone_id}/firewall/access_rules/rules/{$rule_id}",
				'method' => 'DELETE'
			)
		);
		$this->clear_access_rules_cache();

		return $data;
	}

	public function cache_level($setting = NULL)
	{
		$endpoint = "zones/{$this->zone_id}/settings/cache_level";

		if (is_null($setting))
		{
			$data = $this->request(
				array (
					'endpoint' => $endpoint
				)
			);
			return $data;
		}

		$data = $this->request(
			array (
				'endpoint' => $endpoint,
				'method' => 'PATCH',
				'post_fields' => array (
					'value' => $setting
				)
			)
		);
		return $data;
	}

	public function purge_cache($urls = NULL)
	{
		$endpoint = "zones/{$this->zone_id}/purge_cache";

		if (is_null($files))
		{
			$data = $this->request(
				array (
					'endpoint' => $endpoint,
					'method' => 'DELETE',
					'post_fields' => array (
						'purge_everything' => true
					)
				)
			);

			return $data;
		}

		if (is_array($files))
		{
			$data = $this->request(
				array (
					'endpoint' => $endpoint,
					'method' => 'DELETE',
					'post_fields' => array (
						'files' => 'urls'
					)
				)
			);
		}
	}

	public function get_latest_version()
	{
		$sourceUrl = defined('CLOUDFLARE_MANAGER_VERSION_SOURCE_URL')
			? CLOUDFLARE_MANAGER_VERSION_SOURCE_URL
			: 'https://raw.githubusercontent.com/astoltz/MyBB-CloudFlare-Manager/main/inc/plugins/cloudflare.php';
		$data = $this->request("", $sourceUrl);
		if (!is_string($data) || $data === '')
		{
			return '';
		}

		if (preg_match('/define\(\s*[\'"]CLOUDFLARE_MANAGER_VERSION[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)\s*;/', $data, $matches))
		{
			return $matches[1];
		}

		return '';
	}

	public function security_level_setting($setting = NULL)
	{
		$endpoint = "zones/{$this->zone_id}/settings/security_level";
		if (is_null($setting))
		{
			$data = $this->request(
				array (
					'endpoint' => $endpoint
				)
			);
			return $data;
		}

		$data = $this->request(
			array (
				'endpoint' => $endpoint,
				'method' => 'PATCH',
				'post_fields' => array (
					'value' => $setting
				)
			)
		);

		return $data;
	}

	public function get_all_errors($raw)
	{
		$errors = array();
		if (is_object($raw) && is_array($raw->errors))
		{
			foreach ($raw->errors as $error)
			{
				$errors[] = $error->message;
			}
		}
		return $errors;
	}

	private function build_access_rule_payload($mode, $target, $value, $notes)
	{
		return array(
			'mode' => $mode,
			'configuration' => array(
				'target' => $target,
				'value' => $value,
			),
			'notes' => $notes
		);
	}

	private function access_rule_write_result($data, $fallback)
	{
		if (is_object($data) && !empty($data->success))
		{
			return array("success" => true, 'result' => isset($data->result) ? $data->result : null);
		}

		$errors = $this->get_all_errors($data);
		if (empty($errors))
		{
			$errors[] = $fallback;
		}

		return array('errors' => implode("; ", $errors));
	}

	private function clear_access_rules_cache()
	{
		$this->access_rules_cache = null;
	}

	private function normalize_access_rule_value($value)
	{
		$value = trim((string)$value);
		if($value === '')
		{
			return array('errors' => 'Missing IP address or CIDR range.');
		}

		if(strpos($value, '/') !== false)
		{
			if(!$this->is_valid_cidr($value))
			{
				return array('errors' => 'Invalid CIDR range: ' . $value);
			}

			return array(
				'target' => 'ip_range',
				'value' => $value,
			);
		}

		if(!filter_var($value, FILTER_VALIDATE_IP))
		{
			return array('errors' => 'Invalid IP address: ' . $value);
		}

		return array(
			'target' => 'ip',
			'value' => $value,
		);
	}

	private function is_valid_cidr($value)
	{
		$parts = explode('/', (string)$value, 2);
		if(count($parts) !== 2)
		{
			return false;
		}

		$ip = trim($parts[0]);
		$prefix = trim($parts[1]);
		if($ip === '' || $prefix === '' || !ctype_digit($prefix))
		{
			return false;
		}

		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		{
			$prefix = (int)$prefix;
			return $prefix >= 0 && $prefix <= 32;
		}

		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
		{
			$prefix = (int)$prefix;
			return $prefix >= 0 && $prefix <= 128;
		}

		return false;
	}

	private function build_statistics_response($requests, $visits, $bandwidth, $chunks, $cacheHit, $alignedEndTimestamp)
	{
		return (object)array(
			'success' => true,
			'errors' => array(),
			'messages' => array(),
			'result' => (object)array(
				'totals' => (object)array(
					'requests' => (object)array(
						'all' => (int)$requests,
					),
					'visits' => (object)array(
						'all' => (int)$visits,
					),
					'bandwidth' => (object)array(
						'all' => (float)$bandwidth,
					),
				),
				'source' => (object)array(
					'dataset' => 'httpRequestsAdaptiveGroups',
					'request_source' => 'eyeball',
					'chunk_seconds' => 86400,
					'chunks' => (int)$chunks,
					'cache_ttl' => (int)$this->statistics_cache_ttl,
					'cache_hit' => $cacheHit,
					'aligned_end' => gmdate('Y-m-d\TH:i:s\Z', (int)$alignedEndTimestamp),
				),
			),
		);
	}

	private function get_cached_statistics($minutes, $alignedEndTimestamp)
	{
		global $cache;

		if (!isset($cache))
		{
			return null;
		}

		$statsCache = $cache->read('cloudflare_graphql_stats');
		if (!is_array($statsCache))
		{
			return null;
		}

		foreach ($this->get_zone_cache_keys() as $zoneKey)
		{
			if ($zoneKey === '' || !isset($statsCache[$zoneKey]) || !is_array($statsCache[$zoneKey]))
			{
				continue;
			}

			$entryKey = (string)$minutes;
			if (!isset($statsCache[$zoneKey][$entryKey]) || !is_array($statsCache[$zoneKey][$entryKey]))
			{
				continue;
			}

			$entry = $statsCache[$zoneKey][$entryKey];
			$now = defined('TIME_NOW') ? TIME_NOW : time();
			if (
				!isset($entry['expires_at']) ||
				!isset($entry['aligned_end']) ||
				(int)$entry['expires_at'] < $now ||
				(int)$entry['aligned_end'] !== (int)$alignedEndTimestamp
			)
			{
				continue;
			}

			return $entry;
		}

		return null;
	}

	private function store_cached_statistics($minutes, $alignedEndTimestamp, $requests, $visits, $bandwidth, $chunks)
	{
		global $cache;

		if (!isset($cache))
		{
			return;
		}

		$statsCache = $cache->read('cloudflare_graphql_stats');
		if (!is_array($statsCache))
		{
			$statsCache = array();
		}

		$now = defined('TIME_NOW') ? TIME_NOW : time();
		$entryValue = array(
			'aligned_end' => (int)$alignedEndTimestamp,
			'expires_at' => $now + (int)$this->statistics_cache_ttl,
			'requests' => (int)$requests,
			'visits' => (int)$visits,
			'bandwidth' => (float)$bandwidth,
			'chunks' => (int)$chunks,
		);

		foreach ($this->get_zone_cache_keys() as $zoneKey)
		{
			if ($zoneKey === '')
			{
				continue;
			}

			if (!isset($statsCache[$zoneKey]) || !is_array($statsCache[$zoneKey]))
			{
				$statsCache[$zoneKey] = array();
			}

			foreach ($statsCache[$zoneKey] as $entryKey => $entry)
			{
				if (!is_array($entry) || !isset($entry['expires_at']) || (int)$entry['expires_at'] < $now)
				{
					unset($statsCache[$zoneKey][$entryKey]);
				}
			}

			$statsCache[$zoneKey][(string)$minutes] = $entryValue;
		}

		$cache->update('cloudflare_graphql_stats', $statsCache);
	}

	private function get_zone_cache_keys()
	{
		$keys = array();

		if ($this->zone_id)
		{
			$keys[] = $this->zone_id;
		}

		if ($this->zone !== '' && !in_array($this->zone, $keys, true))
		{
			$keys[] = $this->zone;
		}

		return $keys;
	}

	private function graphql_errors_to_messages(array $errors)
	{
		$messages = array();
		foreach ($errors as $error)
		{
			if (is_object($error) && !empty($error->message))
			{
				$messages[] = $error->message;
			}
		}

		if (empty($messages))
		{
			$messages[] = 'CloudFlare GraphQL request failed.';
		}

		return $messages;
	}

	private function graph_ql_statistics_error(array $messages)
	{
		$errors = array();
		foreach ($messages as $message)
		{
			$errors[] = (object)array('message' => $message);
		}

		return (object)array(
			'success' => false,
			'errors' => $errors,
			'messages' => array(),
		);
	}

	public function get_readable_dt($dts)
	{
		return date_format(date_create($dts), 'Y-m-d H:i:s');
	}

	public function get_formatted_size_by_bytes($bytes)
	{
		if ($bytes >= 1048576)
		{
			$bytes = number_format($bytes / 1048576, 2) . ' MB';
		}
		elseif ($bytes >= 1024)
        {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
		}
		elseif ($bytes > 1)
        {
            $bytes = $bytes . ' bytes';
        }
        elseif ($bytes == 1)
        {
            $bytes = $bytes . ' byte';
		}
		else
		{
			$bytes = 0;
		}

		return $bytes;
	}
}
