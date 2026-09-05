<?php
require_once('guiconfig.inc');
if (!isAllowedPage('/frp_client.php')) {
	echo htmlspecialchars(gettext('FRP Client access is required to display tunnel status.'));
	return;
}
$frp_asset_root = dirname(__DIR__, 2) . '/frp-assets';
?>
<link rel="stylesheet" href="/frp-assets/frp.css?v=<?=filemtime($frp_asset_root . '/frp.css')?>">
<div class="frp-widget" data-frp-status>
 <div class="frp-status-line"><strong class="label label-default" data-frp-state><?=gettext('FRP Client')?></strong><span class="text-muted frp-updated" data-frp-updated></span></div>
 <p class="frp-status-detail" data-frp-detail><?=gettext('Loading status…')?></p>
 <div class="table-responsive" data-frp-tunnel-table hidden><table class="table table-condensed"><thead><tr>
  <th><?=gettext('Tunnel')?></th><th><?=gettext('Type')?></th><th><?=gettext('Local')?></th><th><?=gettext('Remote')?></th><th><?=gettext('Status')?></th>
 </tr></thead><tbody data-frp-proxies></tbody></table></div>
 <div class="frp-status-links"><a href="/frp_client.php"><?=gettext('Manage FRP Client')?></a></div>
</div>
<script src="/frp-assets/frp.js?v=<?=filemtime($frp_asset_root . '/frp.js')?>"></script>
