<?php

declare(strict_types=1);

// Preview first. --run persists only a healthy expiry snapshot; no mail or PKI changes.
if (getenv('PDF_SEALER_LIVE_TEST') !== '1') { throw new RuntimeException('Development instances only: set PDF_SEALER_LIVE_TEST=1'); }
$mode = $argv[1] ?? '--preview';
if (!in_array($mode, ['--preview', '--run'], true) || $argc > 2) { throw new RuntimeException('Use --preview or --run'); }
$_SERVER['PHP_SELF'] = 'pdf_sealer_expiry_monitor.php';
require '/home/gr/redcap/codebase/Config/init_global.php';
require dirname(__DIR__) . '/autoload.php';
use DE\RUB\PDFSealerExternalModule\Pki\{ExpiryInventory,ExpiryMonitor};
use DE\RUB\PDFSealerExternalModule\Alerts\{AdminAlarmService,AlarmRepository,AlarmLock};
$framework = \ExternalModules\ExternalModules::getFrameworkInstance('pdf_sealer','v9.9.9');
$framework->disableUserBasedSettingPermissions();
$inventory = new ExpiryInventory($framework);
$before = $inventory->collect();
$assessment = ExpiryMonitor::evaluate($before,time());
echo json_encode(['mode'=>$mode,'status'=>$assessment['status'],'counts'=>$assessment['counts'],'nearest_expiry'=>$assessment['nearest_expiry']], JSON_PRETTY_PRINT),"\n";
if ($mode === '--preview') { exit; }
if ($assessment['status'] !== 'ok' || array_sum($assessment['counts']) !== $assessment['counts']['healthy']) {
    throw new RuntimeException('Live probe requires only healthy certificates; inspect preview instead of sending alarms');
}
$mailAttempts = 0;
$alarms = new AdminAlarmService($framework,new AlarmRepository($framework),new AlarmLock(),static function() use (&$mailAttempts): bool {
    $mailAttempts++;
    return false;
});
$result = (new ExpiryMonitor($framework,$inventory,$alarms,new AlarmLock()))->run();
if ($result['status'] !== 'ok' || $result['counts'] !== $assessment['counts'] || $result['mail_status'] !== 'not_needed'
    || ExpiryMonitor::load($framework) !== $result || $inventory->collect() !== $before || $mailAttempts !== 0) {
    throw new RuntimeException('Live expiry scan failed, attempted mail, or changed inventory');
}
echo "Live expiry inventory, primary Framework queries, persisted snapshot, and unchanged certificates/bindings passed. No mail attempted.\n";
