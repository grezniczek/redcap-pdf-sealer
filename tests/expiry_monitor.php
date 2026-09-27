<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{ExpiryInventory,ExpiryMonitor,PrimaryLogReader,PrimarySystemSettingReader,ProviderRepository,CertificateIssuer};
use DE\RUB\PDFSealerExternalModule\Alerts\{AdminAlarmService,AlarmRepository,AlarmLock};
require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/CertificateSerials.php';
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
final class Result {
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
    public function fetch_row(): ?array { return array_shift($this->rows); }
}
final class Framework {
    public array $settings = [];
    public array $bindings = [];
    public array $certificates = [];
    public array $alarms = [];
    public bool $failInventory = false;
    public function getSystemSetting(string $key): mixed { return $this->settings[$key] ?? null; }
    public function setSystemSetting(string $key, mixed $value): void { $this->settings[$key] = $value; }
    public function tt(string $key): string { return $key; }
    public function log(string $message, array $values): int {
        check($message === 'pki_alarm' && $values['project_id'] === null && $values['record'] === '', 'Unexpected log scope/write');
        $this->alarms[] = $values;
        return count($this->alarms);
    }
    public function query(string $sql, array $params): Result {
        check(!str_contains($sql, 'private_key') && str_contains($sql, 'ISNULL(project_id)'), 'Inventory read secret or project-scoped records');
        $message = array_shift($params);
        if ($message === 'pki_alarm') {
            $rows = array_values(array_filter($this->alarms, fn($r) => $r['alarm_fingerprint'] === $params[0] && $r['mail_status'] === $params[1]));
            return new Result(array_slice(array_reverse($rows), 0, 1));
        }
        if ($this->failInventory) { throw new RuntimeException('Database unavailable'); }
        if (str_contains($sql, 'MAX(log_id)')) {
            $latest = [];
            foreach ($this->bindings as $row) { $latest[$row['redcap_pid']] = ['latest_id' => $row['log_id']]; }
            return new Result(array_values($latest));
        }
        if ($message === 'project_identity_binding') {
            return new Result(array_values(array_filter($this->bindings, fn($r) => in_array($r['log_id'], $params, true))));
        }
        check($message === 'pki_identity', 'Unexpected inventory query');
        return new Result(array_values(array_filter($this->certificates, fn($r) => in_array($r['identity_id'], $params, true))));
    }
}
$f = new Framework();
$reader = new PrimaryLogReader($f, [$f, 'query']);
$settings = new PrimarySystemSettingReader($f, [$f, 'getSystemSetting']);
$inventory = new ExpiryInventory($f, $reader, $settings);
check($inventory->collect() === [], 'Uninitialized inventory is not empty');
$rootId = str_repeat('a',32); $tsaId = str_repeat('b',32); $projectId = str_repeat('c',32); $oldIssuer = str_repeat('d',32);
(new ProviderRepository($f,$settings))->initialize($rootId,$tsaId);
$f->settings['active_root_identity_id'] = $rootId;
$f->settings['active_tsa_identity_id'] = $tsaId;
$f->bindings = [
    ['log_id'=>1,'redcap_pid'=>'527','provider_id'=>'builtin-ca','identity_id'=>str_repeat('e',32)],
    ['log_id'=>2,'redcap_pid'=>'527','provider_id'=>'builtin-ca','identity_id'=>$projectId],
    ['log_id'=>3,'redcap_pid'=>'528','provider_id'=>'builtin-ca','identity_id'=>null],
];
$issuer = new CertificateIssuer(static function(): string {
    $path = tempnam(sys_get_temp_dir(),'pdf-sealer-expiry-');
    if ($path === false) { throw new RuntimeException('Temp file failed'); }
    return $path;
}, [\PDFSealerTests\CertificateSerials::class,'reserve']);
$root = $issuer->createRoot('Expiry Test');
foreach ([$rootId=>'root',$tsaId=>'tsa',$projectId=>'project',$oldIssuer=>'root',str_repeat('e',32)=>'project'] as $id=>$role) {
    $f->certificates[] = ['identity_id'=>$id,'identity_role'=>$role,'certificate_der_b64'=>base64_encode($root->certificateDer),
        'certificate_sha256'=>hash('sha256',$root->certificateDer),'issuer_identity_id'=>$role==='project'?$oldIssuer:null];
}
$before = [$f->settings,$f->bindings,$f->certificates];
$items = $inventory->collect();
check(count($items)===4 && !isset($items[str_repeat('e',32)]) && isset($items[$oldIssuer]), 'Active selection, issuer retention, or deduplication failed');
check([$f->settings,$f->bindings,$f->certificates]===$before, 'Inventory mutated configuration/identities');
foreach ([[-1,'expired'],[0,'7d'],[7*86400,'7d'],[7*86400+1,'30d'],[30*86400,'30d'],[30*86400+1,'90d'],[90*86400,'90d'],[90*86400+1,'healthy']] as [$delta,$band]) {
    check(ExpiryMonitor::band(1700000000+$delta,1700000000)===$band,'Wrong threshold boundary');
}
$parsed = openssl_x509_parse("-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($root->certificateDer),64,"\n")."-----END CERTIFICATE-----\n");
$now = $parsed['validTo_time_t']-60*86400;
$held = [];
$lock = new AlarmLock(static function(string $sql,array $params) use (&$held): Result {
    $name=$params[0];
    if (str_contains($sql,'GET_LOCK')) { if (isset($held[$name])) return new Result([[0]]); $held[$name]=true; }
    else { check(isset($held[$name]),'Release without lock'); unset($held[$name]); }
    return new Result([[1]]);
});
$sent=[]; $deliver=true;
$alarms=new AdminAlarmService($f,new AlarmRepository($f,$reader),$lock,static function($to,$subject,$body) use (&$sent,&$deliver): bool {
    if ($deliver) $sent[]=[$to,$subject,$body];
    return $deliver;
});
$monitor=new ExpiryMonitor($f,$inventory,$alarms,$lock);
$result=$monitor->run($now);
check($result['counts']['90d']===4 && $result['mail_status']==='unconfigured', 'Unconfigured summary not recorded');
$f->settings['admin-alert-recipients']=['admin@example.org'];
$result=$monitor->run($now+1);
check(count($sent)===1 && $result['mail_status']==='sent','Digest did not send once for multiple certificates');
check(!str_contains($sent[0][2],'PRIVATE KEY') && str_contains($sent[0][2],'expiry_mail_help'), 'Digest leaked material or omitted guidance');
check($monitor->run($now+3601)['mail_status']==='throttled' && count($sent)===1,'Daily alarm throttle failed');
check($monitor->run($now+86401)['mail_status']==='sent' && count($sent)===2,'Daily reminder failed');
// Distinct urgency bands must not throttle each other.
$counts=array_fill_keys(ExpiryMonitor::BANDS,0); $counts['30d']=1;
check($alarms->raiseExpirySummary($counts,$now+86402)==='sent','Escalation suppressed');
$counts['expired']=1; $deliver=false;
check($alarms->raiseExpirySummary($counts,$now+86403)==='failed','Failed send hidden');
$deliver=true;
check($alarms->raiseExpirySummary($counts,$now+86404)==='sent','Failed send suppressed retry');
$f->certificates[2]['certificate_sha256']=str_repeat('0',64);
check($monitor->run($now+86405)['counts']['invalid']===1,'Corrupt certificate reported healthy');
$f->failInventory=true;
check($monitor->run($now+86406)['status']==='failed','Failed scan reported complete');
check(ExpiryMonitor::load($f)['status']==='failed','Failed scan left previous success snapshot');
check($held===[], 'Locks remained held');
$f->settings[ExpiryMonitor::SETTING]='{"completed_at":"bad"}';
try { ExpiryMonitor::load($f); throw new LogicException('Malformed snapshot accepted'); } catch (RuntimeException) {}
$large=[]; for($n=1;$n<=60;$n++) $large[sprintf('%032x',$n)]=['role'=>'project','pid'=>$n,'der'=>null];
$result=ExpiryMonitor::evaluate($large,$now);
check(count($result['items'])===50 && $result['counts']['invalid']===60,'Display limit changed counts');
check(ExpiryMonitor::evaluate($items,$parsed['validFrom_time_t']-1)['counts']['invalid']===4,'Future certificates reported healthy');
check(ExpiryMonitor::evaluate($items,$parsed['validTo_time_t']+1)['counts']['expired']===4,'Expired certificates missed');
check($f->bindings===$before[1], 'Monitoring changed bindings');
$config=json_decode(file_get_contents(dirname(__DIR__).'/config.json'),true,flags:JSON_THROW_ON_ERROR);
check($config['crons'][0]['cron_frequency']===86400 && $config['crons'][0]['method']==='checkCertificateExpiry','Missing daily cron');
echo "Expiry monitor: active public inventory, issuer retention, thresholds, snapshot limits/failures, digest throttling/escalation/retry, and no identity changes passed.\n";
