<?php

$priv_list['page-services-frp'] = array();
$priv_list['page-services-frp']['name'] = gettext('WebCfg - Services: FRP Client');
$priv_list['page-services-frp']['descr'] = gettext('Allow access to the FRP Client package page.');
$priv_list['page-services-frp']['match'] = array();
$priv_list['page-services-frp']['match'][] = 'pkg_edit.php?xml=frp.xml*';
$priv_list['page-services-frp']['match'][] = 'frp_client.php*';
