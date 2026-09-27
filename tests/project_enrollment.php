<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\ProjectEnrollmentService;
// Reuse the provider/assignment fake and real CA fixture setup; all persistence is fake.
require __DIR__ . '/external_providers.php';
$encryptionKey = random_bytes(32); $decryptCalls = 0; $encryptionFails = false;
function encrypt(string $plain): string|false {
    global $encryptionKey, $encryptionFails;
    if ($encryptionFails) return false;
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain,'aes-256-gcm',$encryptionKey,OPENSSL_RAW_DATA,$iv,$tag);
    return base64_encode($iv.$tag.$cipher);
}
function decrypt(string $cipher): string|false {
    global $encryptionKey, $decryptCalls;
    $decryptCalls++;
    $raw = base64_decode($cipher,true);
    return openssl_decrypt(substr($raw,28),'aes-256-gcm',$encryptionKey,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
}
$enrollment = new ProjectEnrollmentService($f,$bindings,$providers,$protector,$projectLock,$settings);
try {
    rejects(fn() => $enrollment->generate(999));
    $bindings->bindUuid(103,$issuer->newProjectUuid(),'builtin-ca');
    $beforeInternal = [$f->settings,$f->logs];
    rejects(fn() => $enrollment->generate(103));
    check([$f->settings,$f->logs] === $beforeInternal, 'Built-in provider enrollment wrote storage');
    $admin->assign(102,$second);
    $before = [$f->settings,$f->logs];
    check($enrollment->inspect(102) === null && [$f->settings,$f->logs] === $before, 'Viewing page created enrollment');
    $f->failEnrollment = true;
    rejects(fn() => $enrollment->generate(102));
    check([$f->settings,$f->logs] === $before, 'Audit failure left pending key');
    $f->failEnrollment = false;
    $encryptionFails = true;
    rejects(fn() => $enrollment->generate(102));
    check([$f->settings,$f->logs] === $before, 'Failed encryption wrote enrollment');
    $encryptionFails = false;
    $first = $enrollment->generate(102);
    $id = $first['pending']['id'];
    $pem = base64_decode($first['base64'],true);
    $subject = openssl_csr_get_subject($pem);
    check($subject === ['CN'=>'REDCap Project '.$bindings->find(102)->uuid], 'CSR exposes unexpected subject attributes');
    $key = openssl_pkey_get_details(openssl_csr_get_public_key($pem));
    check($key['type'] === OPENSSL_KEYTYPE_RSA && $key['bits'] === 3072, 'Wrong CSR key');
    $stored = json_decode($f->settings['pending_enrollment_102'],true);
    check(!str_contains(json_encode([$f->settings,$f->logs,$first]), 'PRIVATE KEY'), 'Plain private key exposed');
    check(!str_contains(json_encode($first), 'ciphertext'), 'Encrypted key exposed in response');
    check($bindings->find(102)->identityId === null, 'Generation activated a signer');
    $before = [$f->settings,$f->logs,$decryptCalls];
    check($enrollment->generate(102) === $first && $enrollment->download(102,$id) === $first, 'Repeated request changed pending key/CSR');
    check($enrollment->inspect(102) === $first['pending'], 'Public pending metadata differs');
    check([$f->settings,$f->logs,$decryptCalls] === $before, 'Read/retry mutated or decrypted pending key');
    rejects(fn() => $enrollment->download(101,$id));
    rejects(fn() => $enrollment->cancel(102,str_repeat('0',32)));
    check([$f->settings,$f->logs,$decryptCalls] === $before, 'Wrong request changed enrollment');
    // Independent OpenSSL CLI check of CSR self-signature and requested extensions.
    $path = $f->createTempFile(); file_put_contents($path,$pem);
    $pipes = [];
    $process = proc_open(['openssl','req','-in',$path,'-verify','-text','-noout'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && str_contains($output,'verify OK') && str_contains($output,'CA:FALSE')
        && str_contains($output,'Digital Signature') && str_contains($output,'1.3.6.1.5.5.7.3.36'), 'CSR signature or requested profile rejected');
    $f->failEnrollment = true;
    rejects(fn() => $enrollment->cancel(102,$id));
    $f->failEnrollment = false;
    check($enrollment->download(102,$id) === $first, 'Failed cancellation removed pending request');
    $enrollment->cancel(102,$id);
    check($enrollment->inspect(102) === null && !isset($f->settings['pending_enrollment_102']), 'Cancellation retained active key storage');
    rejects(fn() => $enrollment->download(102,$id));
    // Pending replacement can coexist with a current active identity reference.
    $bindings->activate(102,$bindings->find(102)->uuid,str_repeat('9',32));
    $bindingBefore = $bindings->find(102);
    $new = $enrollment->generate(102);
    check($new['pending']['id'] !== $id && $new['base64'] !== $first['base64'], 'Explicit cancellation did not permit a fresh key');
    rejects(fn() => $enrollment->cancel(102,$id));
    check($bindings->find(102) == $bindingBefore, 'Pending renewal changed active binding');
    $saved = $f->settings['pending_enrollment_102'];
    $bad = json_decode($saved,true); $bad['uuid'] = $bindings->find(101)->uuid;
    $f->settings['pending_enrollment_102'] = json_encode($bad);
    rejects(fn() => $enrollment->generate(102));
    check($f->settings['pending_enrollment_102'] === json_encode($bad), 'Mismatched pending key silently replaced');
    $f->settings['pending_enrollment_102'] = $saved;
    $enrollment->cancel(102,$new['pending']['id']);
    check($bindings->find(102) == $bindingBefore, 'Cancellation changed active binding');
    echo "Project enrollment: verified CSR/profile, encrypted durable key, idempotency, public-only responses, rollback, cancellation/stale IDs, and active identity isolation passed.\n";
} finally { foreach ($f->paths as $path) { if (is_file($path)) unlink($path); } }
