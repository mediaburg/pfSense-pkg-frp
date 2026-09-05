<?php
require_once('guiconfig.inc');
require_once('/usr/local/pkg/frp/frp.inc');
require_once('/usr/local/pkg/frp/web.inc');

if (($_GET['ajax'] ?? '') === 'status') {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode(frp_live_status(), JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}
$pgtitle = [gettext('Services'), gettext('FRP Client')];
$pglinks = ['', '@self'];
$result = ['values' => frp_form_values(frp_get_config()), 'errors' => [], 'message' => '',
    'comparison' => [], 'diagnostics' => [], 'reviewed' => false,
    'revision' => frp_revision(), 'runtime_revision' => frp_runtime_revision()];
if ($_POST) {
	$result = frp_handle_post($_POST);
} elseif (trim($result['values']['toml']) === '') {
	$pkg = parse_xml_config_pkg('/usr/local/pkg/frp.xml', 'packagegui');
	foreach ($pkg['fields']['field'] as $field) {
		if (($field['fieldname'] ?? '') === 'toml') { $result['values']['toml'] = base64_decode($field['default_value']); }
	}
}
$pconfig = $result['values'];
$status = frp_live_status();
include('head.inc');
if ($result['errors']) { print_input_errors($result['errors']); }
if ($result['message']) { print_info_box($result['message'], 'info'); }
if (frp_runtime_config_differs()) {
	print_info_box(gettext('Runtime configuration differs from the last saved settings. Load it into the editor to review console changes before saving.'), 'warning');
}
?>
<link rel="stylesheet" href="/frp-assets/frp.css?v=<?=filemtime(__DIR__ . '/frp-assets/frp.css')?>">
<div id="frp-page">
<div class="panel panel-default" data-frp-status>
 <div class="panel-heading"><h2 class="panel-title"><?=gettext('FRP Client status')?></h2></div>
 <div class="panel-body frp-status-body">
  <div class="frp-status-line"><strong class="label label-default" data-frp-state><?=htmlspecialchars($status['state'])?></strong><span class="text-muted frp-version">frpc <?=htmlspecialchars(frp_get_version())?></span><span class="text-muted frp-updated"><?=gettext('Updated')?> <span data-frp-updated></span></span></div>
  <p class="frp-status-detail" data-frp-detail><?=htmlspecialchars($status['detail'])?></p>
  <div class="table-responsive" data-frp-tunnel-table hidden><table class="table table-striped table-condensed">
   <thead><tr><th><?=gettext('Tunnel')?></th><th><?=gettext('Type')?></th><th><?=gettext('Local target')?></th><th><?=gettext('Remote endpoint')?></th><th><?=gettext('Status / error')?></th></tr></thead>
   <tbody data-frp-proxies><tr><td colspan="5"><?=gettext('Loading current tunnel status…')?></td></tr></tbody>
  </table></div>
  <div class="frp-status-links"><a href="/status_logs_packages.php?pkg=frp"><?=gettext('Package logs')?></a> · <a href="https://gofrp.org/en/docs/" target="_blank" rel="noopener noreferrer"><?=gettext('FRP documentation')?></a></div>
 </div>
</div>
<form method="post" id="frp-form" autocomplete="off" data-frp-unsaved="<?=$pconfig !== frp_form_values(frp_get_config()) ? 'true' : 'false'?>">
 <input type="hidden" name="revision" value="<?=htmlspecialchars(is_string($result['revision']) ? $result['revision'] : '')?>">
 <input type="hidden" name="runtime_revision" value="<?=htmlspecialchars(is_string($result['runtime_revision']) ? $result['runtime_revision'] : '')?>">
 <div class="panel panel-default">
  <div class="panel-heading"><h2 class="panel-title"><?=gettext('Client configuration')?> <span id="frp-dirty" class="label label-warning" hidden><?=gettext('Unsaved changes')?></span></h2></div>
  <div class="panel-body">
   <div class="frp-enable-row"><label class="frp-check"><input type="checkbox" name="enable" <?=$pconfig['enable'] ? 'checked' : ''?>> <span><?=gettext('Enable FRP Client')?></span></label><span class="text-muted"><?=gettext('Start on boot and after saving.')?></span></div>
   <div class="frp-editor-toolbar"><label for="toml">frpc.toml</label><button type="button" id="frp-editor-toggle" class="btn btn-default btn-xs" hidden data-plain-label="<?=gettext('Plain text')?>" data-highlight-label="<?=gettext('Syntax highlighting')?>"><?=gettext('Plain text')?></button></div>
   <textarea id="toml" name="toml" class="form-control" rows="20" spellcheck="false"><?=htmlspecialchars($pconfig['toml'])?></textarea>
   <div id="frp-editor" aria-label="<?=gettext('TOML configuration editor')?>" hidden></div>
   <p class="help-block frp-editor-help"><?=gettext('TOML with syntax highlighting. Comments are preserved. Use absolute paths for referenced files.')?></p>
   <details class="frp-options">
    <summary><?=gettext('Connection, HA sync, notifications and TLS')?><span class="text-muted"><?=gettext('Advanced options')?></span></summary>
    <div class="frp-details-body">
     <div class="frp-setting"><div class="frp-setting-label"><?=gettext('HA configuration sync')?></div><div><label class="frp-check"><input type="checkbox" name="ha_sync" <?=$pconfig['ha_sync'] ? 'checked' : ''?>> <span><?=gettext('Synchronize FRP settings with the HA peer')?></span></label><p class="help-block"><?=gettext('Uses XMLRPC synchronization configured under System > High Availability Sync. Disabling this option prevents sending and receiving FRP settings on this node. The choice stays local.')?></p><p class="help-block"><?=gettext('Install this package on both nodes. Synchronize selected certificates through pfSense as well; referenced files must exist on each node.')?></p></div></div>
     <div class="frp-setting"><label class="frp-setting-label" for="carp_mode"><?=gettext('CARP service ownership')?></label><div><select id="carp_mode" name="carp_mode" class="form-control">
<?php foreach (frp_carp_choices($pconfig['carp_mode']) as $value => $label): ?>
      <option value="<?=htmlspecialchars($value)?>" <?=$pconfig['carp_mode'] === $value ? 'selected' : ''?>><?=htmlspecialchars($label)?></option>
<?php endforeach; ?>
     </select><p class="help-block"><?=gettext('Automatic: run only when every configured CARP VIP is MASTER. Without CARP VIPs, run normally. A selected VIP must exist and be MASTER. BACKUP, INIT, unknown state and CARP maintenance keep the client stopped.')?></p><p class="help-block"><?=gettext('This setting is synchronized with FRP configuration. The receiving node uses its own live CARP role. Existing tunnel connections reconnect after failover.')?></p></div></div>
     <div class="frp-setting"><div class="frp-setting-label"><?=gettext('Reconnect')?></div><div><label class="frp-check"><input type="checkbox" name="reconnect" <?=$pconfig['reconnect'] ? 'checked' : ''?>> <span><?=gettext('Retry when the server is unavailable')?></span></label><p class="help-block"><?=gettext('Keep trying at startup. Process crashes are retried at most five times in ten minutes.')?></p></div></div>
     <div class="frp-setting"><div class="frp-setting-label"><?=gettext('Live management')?></div><div><label class="frp-check"><input type="checkbox" name="managed_admin" <?=$pconfig['managed_admin'] ? 'checked' : ''?>> <span><?=gettext('Enable tunnel status and proxy reload')?></span></label><div class="frp-port-row"><label for="admin_port"><?=gettext('Local port')?></label><input id="admin_port" name="admin_port" class="form-control frp-port" type="number" min="1024" max="65535" value="<?=htmlspecialchars($pconfig['admin_port'])?>"></div><p class="help-block"><?=gettext('Listens on 127.0.0.1 with generated credentials. Replaces webServer settings from TOML.')?></p></div></div>
     <div class="frp-setting"><div class="frp-setting-label"><?=gettext('Notifications')?></div><div><label class="frp-check"><input type="checkbox" name="notify" <?=$pconfig['notify'] ? 'checked' : ''?>> <span><?=gettext('Notify on outage and recovery')?></span></label><p class="help-block"><?=gettext('After two minutes of outage, using pfSense notification destinations. An unknown state does not trigger recovery notifications.')?></p></div></div>
<?php foreach (['certref' => ['cert', gettext('Client certificate')], 'caref' => ['ca', gettext('Trusted CA')]] as $key => [$type, $label]): ?>
     <div class="frp-setting"><label class="frp-setting-label" for="<?=$key?>"><?=$label?></label><div><select id="<?=$key?>" name="<?=$key?>" class="form-control">
<?php foreach (frp_certificate_choices($type) as $ref => $description): ?>
      <option value="<?=htmlspecialchars($ref)?>" <?=$pconfig[$key] === $ref ? 'selected' : ''?>><?=htmlspecialchars($description)?></option>
<?php endforeach; ?>
     </select></div></div>
<?php endforeach; ?>
     <p class="help-block frp-cert-help"><?=gettext('Certificates from Certificate Manager are used for transport TLS. Apply again after renewal.')?></p>
    </div>
   </details>
  </div>
  <div class="panel-footer frp-actions">
   <button type="submit" name="action" value="review" class="btn btn-primary"><?=gettext('Review changes')?></button>
   <button type="submit" name="action" value="validate" class="btn btn-default"><?=gettext('Validate')?></button>
   <span class="text-muted frp-action-hint"><?=gettext('Review your changes before applying them.')?></span>
  </div>
 </div>
<?php if ($result['reviewed']): ?>
 <div class="panel panel-default" id="frp-review">
  <div class="panel-heading"><h2 class="panel-title"><?=gettext('Review configuration changes')?></h2></div>
  <div class="panel-body">
   <p><?=gettext('The comparison shows up to 300 changed settings. Comments and formatting are preserved in the editor. Common credential values are hidden here.')?></p>
   <div class="table-responsive"><table class="table table-bordered"><thead><tr><th><?=gettext('Setting')?></th><th><?=gettext('Before')?></th><th><?=gettext('After')?></th></tr></thead><tbody>
<?php foreach ($result['comparison'] as $change): ?>
    <tr><td><?=htmlspecialchars($change['key'])?></td><td><code><?=htmlspecialchars($change['before'])?></code></td><td><code><?=htmlspecialchars($change['after'])?></code></td></tr>
<?php endforeach; ?>
<?php if (!$result['comparison']): ?>
    <tr><td colspan="3"><?=gettext('No setting values changed. Comments and formatting will still be saved.')?></td></tr>
<?php endif; ?>
   </tbody></table></div>
   <p><?=gettext('Reload applies proxy changes to a running client. Common settings, includes and templates require a restart. Failed apply operations attempt to restore previous settings.')?></p>
  </div>
  <div class="panel-footer frp-actions">
   <button type="submit" name="action" value="save" class="btn btn-primary"><?=$pconfig['enable'] ? gettext('Save and restart') : gettext('Save and stop')?></button>
   <button type="submit" name="action" value="reload" class="btn btn-info"><?=gettext('Save and reload proxies')?></button>
  </div>
 </div>
<?php endif; ?>
<details class="panel panel-default frp-maintenance">
 <summary><?=gettext('Diagnostics and recovery')?></summary>
 <div class="frp-details-body">
  <div class="frp-maintenance-row"><div><strong><?=gettext('Saved service')?></strong><p class="help-block"><?=gettext('Check connectivity or restart using the saved configuration.')?></p></div><div class="frp-actions"><button type="submit" name="action" value="diagnose" class="btn btn-default"><?=gettext('Run diagnostics')?></button><button type="submit" name="action" value="restart" class="btn btn-default"><?=gettext('Restart service')?></button></div></div>
  <div class="frp-maintenance-row"><div><strong><?=gettext('Restore into editor')?></strong><p class="help-block"><?=gettext('Load a configuration for review. Nothing is applied until you save.')?></p></div><div class="frp-actions"><button type="submit" name="action" value="reload_runtime" class="btn btn-default"><?=gettext('Load runtime')?></button><button type="submit" name="action" value="restore" class="btn btn-default"><?=gettext('Load previous')?></button></div></div>
 </div>
</details>
</form>
<?php if ($result['diagnostics']): ?>
<div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title"><?=gettext('Diagnostics')?></h2></div><div class="panel-body"><table class="table"><tbody>
<?php foreach ($result['diagnostics'] as $probe): ?>
 <tr><th><?=htmlspecialchars($probe['test'])?></th><td><?=htmlspecialchars($probe['ok'] === null ? gettext('Not tested') : ($probe['ok'] ? gettext('Reachable') : gettext('Failed')))?></td><td><?=htmlspecialchars($probe['detail'])?></td></tr>
<?php endforeach; ?>
</tbody></table><p><?=gettext('Checks are bounded to ten local targets and a short time budget. No firewall rules are changed.')?></p></div></div>
<?php endif; ?>
<details class="panel panel-default frp-maintenance"><summary><?=gettext('Recent package log')?></summary><pre class="frp-log"><?=htmlspecialchars(frp_get_recent_log(25))?></pre></details>
</div>
<script src="/frp-assets/ace/ace.js"></script>
<script src="/frp-assets/frp.js?v=<?=filemtime(__DIR__ . '/frp-assets/frp.js')?>"></script>
<?php include('foot.inc'); ?>
