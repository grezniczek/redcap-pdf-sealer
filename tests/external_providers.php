<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{CaChainValidator,ProviderRepository,ProviderAdminService,PrimarySystemSettingReader,PrimaryLogReader,ProjectBindingRepository,PkiInitializationLock,ProjectIssueLock,CertificateIssuer,IdentityRepository,SecretProtector,PkiHealthService,ProjectIdentityService,ProjectCertificateRequired,ExpiryInventory,ExpiryMonitor};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support/CertificateSerials.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $work): void { try { $work(); } catch (Throwable) { return; } throw new RuntimeException('Expected rejection'); }
final class Rows {
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
    public function fetch_row(): ?array { return array_shift($this->rows); }
}
final class Framework {
    public array $settings = [], $logs = [], $paths = [];
    public function getProjectId(): int { return 104; }
    public function getModuleInstance(): object { return (object)['PREFIX'=>'pdf_sealer']; }
    public function prefixSettingKey(string $key): string { return $key; }
    public function getQueryLogsSql(string $sql): string { return $sql; }
    public ?array $snapshot = null;
    public bool $allowIdentityReads = false;
    public bool $failAudit = false, $failCatalog = false, $failEnrollment = false;
    public function createTempFile(): string { return $this->paths[] = tempnam('/tmp', 'pdf-sealer-external-'); }
    public function getSystemSetting(string $key): mixed { return $this->settings[$key] ?? null; }
    public function setSystemSetting(string $key, mixed $value): void {
        if ($this->failCatalog && $key === 'external_ca_provider_ids') throw new RuntimeException('Catalog write failed');
        $this->settings[$key] = $value;
    }
    public function removeSystemSetting(string $key): void { unset($this->settings[$key]); }
    public function getProjectsWithModuleEnabled(): array { return [101,102]; }
    public function getUser(): object { return new class { public function getUsername(): string { return 'admin'; } }; }
    public function query(string $sql, array $params): bool {
        if ($sql === 'START TRANSACTION') $this->snapshot = [$this->settings,$this->logs];
        elseif ($sql === 'ROLLBACK') { [$this->settings,$this->logs] = $this->snapshot; $this->snapshot = null; }
        elseif ($sql === 'COMMIT') $this->snapshot = null;
        else throw new RuntimeException('Unexpected query');
        return true;
    }
    public function log(string $message, array $values): int {
        check($values['project_id'] === null && $values['record'] === '', 'Wrong audit scope');
        if ($message === 'project_enrollment' && $this->failEnrollment) throw new RuntimeException('Enrollment audit unavailable');
        if ($message === 'ca_provider_admin' && $this->failAudit) throw new RuntimeException('Audit unavailable');
        $this->logs[] = ['message'=>$message,'log_id'=>count($this->logs)+1] + $values;
        return count($this->logs);
    }
    public function queryLogs(string $sql, array $params): Rows {
        check($this->allowIdentityReads || !str_contains($sql, 'private_key'), 'Unexpected secret read');
        $rows = array_values(array_filter($this->logs, fn($row) => $row['message'] === $params[0]));
        if (str_contains($sql, "NOT LIKE 'external-%'")) $rows = array_values(array_filter($rows, fn($r) => !str_starts_with($r['provider_id'] ?? '', 'external-')));
        if (str_contains($sql, 'ISNULL(issuer_chain_json)')) $rows = array_values(array_filter($rows, fn($r) => ($r['issuer_chain_json'] ?? null) === null));
        $index = 1;
        foreach (['identity_id','identity_role','project_uuid','redcap_pid'] as $field) {
            if (str_contains($sql, $field . ' = ?')) {
                $value = $params[$index++];
                $rows = array_values(array_filter($rows, fn($r) => ($r[$field] ?? null) === $value));
            }
        }
        foreach (['identity_id','log_id'] as $field) {
            if (str_contains($sql, $field . ' IN (')) $rows = array_values(array_filter($rows, fn($r) => in_array($r[$field] ?? null, array_slice($params,1),true)));
        }
        if (str_contains($sql, 'MAX(log_id)')) {
            $latest = []; foreach ($rows as $row) $latest[$row['redcap_pid']] = ['latest_id'=>$row['log_id']];
            return new Rows(array_values($latest));
        }
        if (str_contains($sql, 'ORDER BY log_id DESC')) $rows = array_reverse($rows);
        return new Rows($rows);
    }
}
$f = new Framework();
$settings = new PrimarySystemSettingReader($f, [$f,'getSystemSetting']);
$logs = new PrimaryLogReader($f, [$f,'queryLogs']);
$providers = new ProviderRepository($f,$settings);
$bindings = new ProjectBindingRepository($f,$logs);
$lock = static fn(string $sql, array $params): Rows => new Rows([[1]]);
$projectLock = new ProjectIssueLock($lock);
$validator = new CaChainValidator($f);
$admin = new ProviderAdminService($f,$providers,$bindings,$validator,new PkiInitializationLock($lock),$projectLock);
$issuer = new CertificateIssuer([$f,'createTempFile'], [\PDFSealerTests\CertificateSerials::class,'reserve']);
try {
    $root = $issuer->createRoot('External CA Test');
    $rootPem = Certificate::derToPem($root->certificateDer);
    $chain = $validator->validate($rootPem);
    check(count($chain) === 1, 'Single root failed');
    $config = $f->createTempFile();
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:true,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n");
    $key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    $csr = openssl_csr_new(['commonName'=>'External Issuing CA'], $key, ['config'=>$config]);
    $cert = openssl_csr_sign($csr,$rootPem,$root->privateKey(),365,['config'=>$config,'x509_extensions'=>'ca','digest_alg'=>'sha256'],42);
    check($cert !== false && openssl_x509_export($cert,$intermediate), 'Could not generate intermediate');
    $fullPem = $intermediate . $rootPem;
    check(count($validator->validate($fullPem)) === 2, 'Intermediate chain failed');
    $leaf = $issuer->createProject('External CA Test', $issuer->newProjectUuid(), $root);
    $other = $issuer->createRoot('Unrelated Test');
    foreach (['',str_repeat('x',131073), $rootPem.$rootPem, $rootPem.$intermediate,
        $intermediate.Certificate::derToPem($other->certificateDer),
        Certificate::derToPem($leaf->certificateDer).$rootPem, $rootPem.$root->privateKeyPem()] as $bad) {
        rejects(fn() => $validator->validate($bad));
    }
    // A sub-CA below a pathlen:0 issuing CA must be rejected by path validation.
    $childCsr = openssl_csr_new(['commonName'=>'Forbidden sub-CA'], $key, ['config'=>$config]);
    $child = openssl_csr_sign($childCsr,$intermediate,$key,100,['config'=>$config,'x509_extensions'=>'ca','digest_alg'=>'sha256'],43);
    openssl_x509_export($child,$childPem);
    rejects(fn() => $validator->validate($childPem.$fullPem));
    $expired = openssl_csr_sign($csr,$rootPem,$root->privateKey(),0,['config'=>$config,'x509_extensions'=>'ca'],44);
    openssl_x509_export($expired,$expiredPem);
    sleep(1); // Zero-day certificate must be strictly in the past.
    rejects(fn() => $validator->validate($expiredPem.$rootPem));
    foreach (['failAudit','failCatalog'] as $failure) {
        $f->$failure = true;
        rejects(fn() => $admin->register('Test provider',$fullPem,null,false));
        check($f->settings === [] && $f->logs === [] && $f->snapshot === null, 'Registration did not roll back');
        $f->$failure = false;
    }
    check(!$providers->requiresAssignment(), 'Absent gate is not off');
    $f->failAudit = true;
    rejects(fn() => $admin->saveAssignmentPolicy(true));
    check($f->settings === [] && $f->logs === [], 'Failed gate audit did not roll back setting');
    $f->failAudit = false;
    $admin->saveAssignmentPolicy(true);
    check($providers->requiresAssignment(), 'Gate cannot be enabled without any CA');
    $id = $admin->register('Test provider',$fullPem,null,false);
    check($providers->requiresAssignment(), 'Registering a provider changed the gate');
    $admin->saveAssignmentPolicy(false);
    check(!$providers->requiresAssignment(), 'Gate cannot be disabled with an external CA');
    check($providers->externalIds() === [$id], 'Provider missing');
    check(!$providers->hasConfiguration(), 'Registration changed default/built-in settings');
    rejects(fn() => $admin->register('Duplicate',$fullPem,null,false));
    rejects(fn() => $admin->register('Unknown source',$rootPem,'missing',false));
    $public = $providers->publicCertificates();
    check(count($public) === 2 && !$public[0]['trust_anchor'] && $public[1]['trust_anchor'], 'Public chain roles wrong');
    check(!str_contains(json_encode([$f->logs,$f->settings]), 'PRIVATE KEY'), 'Private key stored');
    rejects(fn() => $admin->assign(999,$id));
    $f->failAudit = true;
    rejects(fn() => $admin->assign(101,$id));
    check($bindings->find(101) === null, 'Failed assignment persisted binding');
    $f->failAudit = false;
    $admin->assign(101,$id);
    $binding = $bindings->find(101);
    check($binding !== null && $binding->identityId === null && $binding->providerId === $id, 'Assignment generated signer or wrong provider');
    $before = [$f->settings,$f->logs];
    $admin->assign(101,$id);
    check([$f->settings,$f->logs] === $before, 'Idempotent assignment wrote again');
    check(!$bindings->hasAny(false) && $bindings->hasAny(), 'External-only assignment blocks built-in initialization');
    $providers->initialize(str_repeat('a',32),str_repeat('b',32));
    rejects(fn() => $admin->assign(101,'builtin-ca'));
    $protector = new SecretProtector();
    $identities = new IdentityRepository($f,$protector,$logs,$settings);
    $service = new ProjectIdentityService($bindings,$identities,$protector,$issuer,new PkiHealthService($identities,$protector),$projectLock,new PkiInitializationLock($lock));
    check($service->inspect(101)['state'] === 'awaiting_certificate', 'Awaiting state wrong');
    $before = [$f->settings,$f->logs];
    try { $service->getOrIssue(101); throw new RuntimeException('Unexpected local issuance'); }
    catch (ProjectCertificateRequired) {}
    check([$f->settings,$f->logs] === $before, 'External sealing attempt mutated PKI');
    $items = (new ExpiryInventory($f,$logs,$settings))->collect();
    foreach ($public as $cert) check($items[$cert['id']]['der'] === $cert['der'] && $items[$cert['id']]['role'] === 'ca', 'External expiry certificate missing');
    $snapshot = ExpiryMonitor::evaluate($items, $public[0]['valid_until'] - 3600);
    $f->settings['last-expiry-scan'] = json_encode($snapshot);
    check(ExpiryMonitor::load($f) === $snapshot, 'External expiry snapshot did not round trip');
    check($providers->timestampSettings($id)->mode === 'none', 'External B-B policy lost');
    $second = $admin->register('Internal timestamp',$rootPem,'builtin-tsa',false);
    check($providers->timestampSettings($second)->mode === 'internal' && !$providers->timestampSettings($second)->fallback, 'Explicit internal source lost');
    // Corrupted registry certificate cannot be published or downloaded.
    $stored = json_decode($f->settings['ca_provider_'.$id],true);
    $stored['chain'][0]['sha256'] = str_repeat('0',64);
    $f->settings['ca_provider_'.$id] = json_encode($stored);
    rejects(fn() => $providers->publicCertificates());
    foreach ($f->paths as $path) { if ($path !== $config) check(!file_exists($path), 'Validator temporary file retained'); }
    if (in_array('--fixture', $argv,true)) {
        $dir = dirname(__DIR__).'/DEV_DOCS/interop-artifacts';
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/external-ca-registration-test.pem',$fullPem);
    }
    echo "External providers: CA/path validation, public metadata, rollback, assignment, no local issuance, and expiry inventory passed.\n";
} finally { foreach ($f->paths as $path) { if (is_file($path)) unlink($path); } }
