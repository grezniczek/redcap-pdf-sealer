<?php
/** Disposable public inventory fixtures; fails on private-key reads or database mutations. */
declare(strict_types=1);
use DE\RUB\PDFSealerExternalModule\Pki\{ProjectAdminOverview,IdentityRepository,SecretProtector};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
require dirname(__DIR__) . '/autoload.php';
if (!defined('MYSQLI_STORE_RESULT')) { define('MYSQLI_STORE_RESULT', 0); }
function check(bool $ok, string $message): void {if (!$ok) throw new RuntimeException($message);}
final class Rows {
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array {return array_shift($this->rows);}
}
$uuid = 'aabbccdd-0000-4000-8000-000000000001';
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$csr = openssl_csr_new(['commonName' => 'REDCap Project ' . $uuid], $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 10, ['digest_alg' => 'sha256'], 10);
check($cert !== false && openssl_x509_export($cert, $pem), 'Disposable certificate unavailable');
$der = Certificate::pemToDer($pem);
$f = new class {
    public array $queries = [], $bindings = [], $enrollments = [], $certificates = [], $revocations = [], $enabled = [1,2,4,5,6,7,8,9], $settings = [];
    public bool $fail = false;
    public function getQueryLogsSql(string $sql): string {return $sql;}
    public function getProjectsWithModuleEnabled(): array {return $this->enabled;}
    public function getModuleInstance(): object {return (object)['PREFIX' => 'pdf_sealer'];}
    public function prefixSettingKey(string $key): string {return $key;}
    public function query(string $sql, array $pids): Rows {
        check(str_starts_with($sql, 'SELECT project_id, app_title') && !str_contains($sql, 'private_key'), 'Unexpected project query');
        $this->queries[] = $sql;
        return new Rows(array_map(static fn(int $pid): array => ['project_id' => $pid, 'app_title' => 'Project ' . $pid,
            'status' => $pid === 2 ? 2 : 1, 'completed_time' => $pid === 2 ? '2026-01-01' : null, 'date_deleted' => null], $pids));
    }
};
function db_query(string $sql, array $params, mixed ...$options): Rows {
    global $f;
    check(str_starts_with($sql, 'SELECT ') && !str_contains($sql, 'private_key') && !str_contains($sql, 'ciphertext'), 'Overview read private material or mutated data');
    check(($options[2] ?? false) === true, 'Log/settings reads must use the primary');
    if ($f->fail) throw new RuntimeException('Unavailable');
    $f->queries[] = $sql;
    if (str_contains($sql, 'redcap_external_module_settings')) {
        $v = $f->settings[$params[1]] ?? null; return new Rows($v === null ? [] : [['value' => $v, 'type' => 'string']]);
    }
    if ($params[0] === 'project_certificate_revocation') return new Rows($f->revocations);
    if ($params[0] === 'pki_identity') return new Rows(isset($f->certificates[$params[1]]) ? [$f->certificates[$params[1]]] : []);
    $rows = $params[0] === 'project_identity_binding' ? $f->bindings : $f->enrollments;
    if (str_contains($sql, 'MAX(log_id)')) {
        if (count($params) > 1) $rows = array_filter($rows, static fn(array $r): bool => in_array((int)$r['redcap_pid'], array_slice($params, 1), true));
        return new Rows(array_map(static fn(array $r): array => ['latest_id' => $r['log_id']], array_values($rows)));
    }
    return new Rows(array_values(array_filter($rows, static fn(array $r): bool => in_array($r['log_id'], array_slice($params, 1), true))));
}
$builtin = ['id'=>'builtin-ca','kind'=>'internal','issuer_identity_id'=>str_repeat('a',32),'timestamp_source'=>null,'timestamp_alternatives'=>[],'bb_fallback'=>false];
$external = ['id'=>'external-a','kind'=>'external','name'=>'External CA','chain'=>[['der_b64'=>base64_encode($der),'sha256'=>hash('sha256',$der)]],'timestamp_source'=>null,'timestamp_alternatives'=>[],'bb_fallback'=>false];
$f->settings = ['ca_provider_builtin-ca'=>json_encode($builtin),'ca_provider_external-a'=>json_encode($external)];
foreach ([2,3,4,5,6,7,8,9] as $pid) {
    $id = str_pad((string)$pid,32,'0',STR_PAD_LEFT);
    $f->bindings[] = ['log_id'=>$pid,'redcap_pid'=>(string)$pid,'project_uuid'=>$uuid,'provider_id'=>$pid === 6 || $pid === 7 ? 'external-a' : 'builtin-ca',
        'identity_id'=>$pid === 7 ? null : $id,'pending_provider_id'=>$pid === 4 ? 'external-a' : null,'transition_id'=>$pid === 4 ? str_repeat('b',32) : null];
    $f->certificates[$id] = ['identity_role'=>'project','certificate_der_b64'=>base64_encode($der),'certificate_sha256'=>hash('sha256',$der)];
}
$f->enrollments = [['log_id'=>40,'redcap_pid'=>'4','action'=>'generate','provider_id'=>'external-a','enrollment_id'=>str_repeat('c',32)],
    ['log_id'=>50,'redcap_pid'=>'5','action'=>'cancel','provider_id'=>'builtin-ca','enrollment_id'=>str_repeat('d',32)]];
$f->revocations = [['identity_id'=>str_pad('8',32,'0',STR_PAD_LEFT),'certificate_sha256'=>hash('sha256',$der)]];
$f->certificates[str_pad('9',32,'0',STR_PAD_LEFT)]['certificate_sha256'] = str_repeat('0',64);
$reader = new ProjectAdminOverview($f, new IdentityRepository($f,new SecretProtector()));
$index = static fn(array $rows): array => array_column($rows,null,'pid');
$rows = $index($reader->load());
check(count($rows) === 9, 'Missing enabled or disabled bound projects');
check($rows[1]['eligible']['assign'] && $rows[1]['binding'] === null, 'Unassigned eligibility wrong');
check($rows[2]['status'] === 'completed' && $rows[2]['eligible']['renew'] && $rows[2]['eligible']['revoke'], 'Built-in status/actions wrong');
check(!$rows[3]['enabled'] && $rows[3]['eligible']['revoke'] && !$rows[3]['eligible']['renew'], 'Disabled binding must remain revocable');
check($rows[4]['eligible']['cancel'] && !$rows[4]['eligible']['change'] && !$rows[4]['eligible']['renew'] && $rows[4]['binding']['enrollment_id'] !== null, 'Pending workflow eligibility wrong');
check($rows[5]['binding']['enrollment_id'] === null && $rows[5]['eligible']['renew'], 'Canceled CSR still blocks actions');
check($rows[6]['eligible']['change'] && !$rows[6]['eligible']['renew'] && !$rows[6]['eligible']['revoke'], 'External certificate actions wrong');
check($rows[7]['certificate'] === null && $rows[7]['binding']['identity_id'] === null, 'Enrollment-only binding wrong');
check($rows[8]['certificate']['status'] === 'revoked' && !$rows[8]['eligible']['revoke'], 'Local revocation missing');
check($rows[9]['unavailable'] && !array_filter($rows[9]['eligible']), 'Corrupt public identity must fail closed');
check($rows[2]['certificate']['thumbprint'] === hash('sha1',$der), 'Windows thumbprint missing');
check(array_keys($index($reader->load([2,3,99]))) === [2,3], 'Target refresh leaked unrelated projects');
$f->settings['ca_provider_retired_builtin-ca'] = 'true'; $rows = $index($reader->load([2]));
check(!$rows[2]['eligible']['renew'] && $rows[2]['eligible']['revoke'], 'Retired CA must not block revocation');
$f->fail = true;
try {$reader->load(); throw new LogicException('Read failure accepted');} catch (RuntimeException) {}
echo "Project overview: public-only reads, retained bindings, pending work, revocation, corruption and targeted refresh passed\n";
