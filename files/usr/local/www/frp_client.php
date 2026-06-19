<?php
/*
 * frp_client.php
 *
 * pfSense package page for FRP client management.
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/frp/frp.inc");

$pgtitle = array(gettext("Services"), gettext("FRP Client"));
$pglinks = array("", "@self");
$input_errors = [];
$savemsg = "";

$settings = frp_get_config();
$pconfig = [
	'enable' => frp_is_enabled($settings),
	'toml' => frp_decode_toml($settings),
];
$runtime_differs = frp_runtime_config_differs($settings);

if (trim($pconfig['toml']) == '') {
	$pkg = parse_xml_config_pkg('/usr/local/pkg/frp.xml', 'packagegui');
	foreach ($pkg['fields']['field'] as $field) {
		if (($field['fieldname'] ?? '') == 'toml') {
			$pconfig['toml'] = base64_decode($field['default_value']);
			break;
		}
	}
}

if ($_POST) {
	$action = $_POST['action'] ?? 'save';
	$allowed_actions = ['save', 'validate', 'restart', 'reload_runtime'];
	if (!in_array($action, $allowed_actions, true)) {
		$input_errors[] = gettext('Invalid action requested.');
		$action = '';
	}

	$pconfig['enable'] = isset($_POST['enable']);
	$pconfig['toml'] = unixnewlines($_POST['toml'] ?? '');

	if ($action == '') {
		// Invalid action; keep submitted data visible but do not apply changes.
	} elseif ($action == 'reload_runtime') {
		$runtime_toml = frp_get_runtime_toml();
		if ($runtime_toml === null) {
			$input_errors[] = gettext('The runtime frpc.toml file does not exist, is not a regular file, or is too large.');
		} else {
			$output = '';
			if (frp_validate_toml($runtime_toml, $output)) {
				$pconfig['toml'] = $runtime_toml;
				$newsettings = [
					'enable' => $pconfig['enable'] ? 'on' : '',
					'toml' => base64_encode($pconfig['toml']),
					'toml_hash' => frp_toml_hash($pconfig['toml']),
				];
				config_set_path('installedpackages/frp/config/0', $newsettings);
				write_config(gettext('FRP Client settings reloaded from runtime frpc.toml.'));
				$savemsg = gettext('Runtime frpc.toml was loaded into the web configuration.');
				$runtime_differs = false;
			} else {
				$input_errors[] = sprintf(gettext('Runtime frpc.toml validation failed: %s'), $output);
			}
		}
	} elseif ($action == 'restart') {
		if (frp_is_enabled()) {
			$output = '';
			if (frp_validate_toml(frp_decode_toml(frp_get_config()), $output)) {
				frp_restart();
				$savemsg = gettext('FRP Client service restart requested.');
			} else {
				$input_errors[] = sprintf(gettext('Stored frpc.toml validation failed: %s'), $output);
			}
		} else {
			$input_errors[] = gettext('Enable FRP Client before restarting the service.');
		}
	} elseif ($action == 'validate') {
		$output = '';
		if (strlen($pconfig['toml']) > FRP_MAX_CONFIG_BYTES) {
			$input_errors[] = sprintf(gettext('frpc.toml is larger than %d bytes.'), FRP_MAX_CONFIG_BYTES);
		} elseif (frp_validate_toml($pconfig['toml'], $output)) {
			$savemsg = gettext('frpc.toml validation succeeded.');
		} else {
			$input_errors[] = sprintf(gettext('frpc.toml validation failed: %s'), $output);
		}
	} else {
		if (trim($pconfig['toml']) == '') {
			$input_errors[] = gettext('The frpc.toml configuration must not be empty.');
		}

		if (!$input_errors && (strlen($pconfig['toml']) > FRP_MAX_CONFIG_BYTES)) {
			$input_errors[] = sprintf(gettext('frpc.toml is larger than %d bytes.'), FRP_MAX_CONFIG_BYTES);
		}

		$output = '';
		if (!$input_errors && !frp_validate_toml($pconfig['toml'], $output)) {
			$input_errors[] = sprintf(gettext('frpc.toml validation failed: %s'), $output);
		}

		if (!$input_errors) {
			$newsettings = [
				'enable' => $pconfig['enable'] ? 'on' : '',
				'toml' => base64_encode($pconfig['toml']),
				'toml_hash' => frp_toml_hash($pconfig['toml']),
			];
			config_set_path('installedpackages/frp/config/0', $newsettings);
			write_config(gettext('FRP Client settings saved.'));
			frp_write_config($newsettings);

			if ($pconfig['enable']) {
				frp_restart();
			} else {
				frp_stop();
			}

			$savemsg = gettext('FRP Client settings saved.');
			$runtime_differs = false;
		}
	}
}

$status = frp_get_connection_status();
$frpc_version = frp_get_version();
$recent_log = frp_get_recent_log(12);

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

if ($runtime_differs) {
	print_info_box(
		gettext('The file /usr/local/etc/frpc.toml differs from the configuration stored in pfSense. It may have been edited from the console. Review the file before loading it into the web configuration.'),
		'warning'
	);
}

$status_class = $status['connected'] ? 'success' : ($status['running'] ? 'warning' : 'default');
?>

<div class="panel panel-<?=$status_class?>">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Service Status')?></h2>
	</div>
	<div class="panel-body">
		<p><strong><?=htmlspecialchars($status['state'])?></strong></p>
		<p><?=htmlspecialchars($status['detail'])?></p>
		<p><?=gettext('frpc version')?>: <?=htmlspecialchars($frpc_version)?></p>
		<p>
			<a href="<?=FRP_DOC_URL?>" target="_blank" rel="noopener noreferrer"><?=gettext('FRP information')?></a>
			&nbsp;|&nbsp;
			<a href="/status_logs_packages.php?pkg=frp"><?=gettext('Package logs')?></a>
		</p>
<?php if ($recent_log): ?>
		<pre style="max-height: 12em; overflow: auto; white-space: pre-wrap;"><?=htmlspecialchars($recent_log)?></pre>
<?php endif; ?>
	</div>
</div>

<form method="post">
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?=gettext('FRP Client Settings')?></h2>
		</div>
		<div class="panel-body">
			<div class="form-group">
				<label class="control-label">
					<input name="enable" type="checkbox" value="yes" <?=($pconfig['enable'] ? 'checked="checked"' : '')?>>
					<?=gettext('Enable FRP Client')?>
				</label>
			</div>
			<div class="form-group">
				<label for="toml"><?=gettext('frpc.toml')?></label>
				<textarea id="toml" name="toml" class="form-control" rows="30" spellcheck="false" style="width: 100%; max-width: none; font-family: monospace;"><?=htmlspecialchars($pconfig['toml'])?></textarea>
			</div>
		</div>
		<div class="panel-footer">
			<button type="submit" name="action" value="save" class="btn btn-primary">
				<i class="fa-solid fa-save"></i>
				<?=gettext('Save')?>
			</button>
<?php if ($runtime_differs): ?>
			<button type="submit" name="action" value="reload_runtime" class="btn btn-warning">
				<i class="fa-solid fa-download"></i>
				<?=gettext('Load /usr/local/etc/frpc.toml')?>
			</button>
<?php endif; ?>
			<button type="submit" name="action" value="validate" class="btn btn-info">
				<i class="fa-solid fa-check"></i>
				<?=gettext('Validate Configuration')?>
			</button>
			<button type="submit" name="action" value="restart" class="btn btn-warning">
				<i class="fa-solid fa-arrows-rotate"></i>
				<?=gettext('Restart FRP Service')?>
			</button>
		</div>
	</div>
</form>

<?php include("foot.inc"); ?>
