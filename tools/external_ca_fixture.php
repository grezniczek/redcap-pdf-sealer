<?php
/** Disposable development CA, outside REDCap. Never package or use for production. */
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/tests/support/CertificateSerials.php';
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
$dir = dirname(__DIR__) . '/DEV_DOCS/interop-artifacts/external-ca-acceptance';
$mode = $argv[1] ?? '';
if (!in_array($mode,['--create','--sign'],true) || ($mode === '--create' && $argc !== 2) || ($mode === '--sign' && $argc !== 3)) {
    fwrite(STDERR,"Usage: php tools/external_ca_fixture.php --create | --sign /path/to/project.csr\n"); exit(1);
}
umask(0077);
$paths = [];
$temp = static function() use (&$paths): string {
    $path = tempnam('/tmp','pdf-sealer-test-ca-');
    if ($path === false) throw new RuntimeException('Temporary file unavailable');
    return $paths[] = $path;
};
try {
    $config = $temp();
    file_put_contents($config,"[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:true,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n[leaf]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.5.5.7.3.36\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n");
    if ($mode === '--create') {
        if (is_dir($dir)) throw new RuntimeException('Test CA already exists; reuse it to sign CSRs');
        $issuer = new CertificateIssuer($temp,[\PDFSealerTests\CertificateSerials::class,'reserve']);
        $root = $issuer->createRoot('PDF Sealer External Acceptance TEST ONLY');
        $rootPem = Certificate::derToPem($root->certificateDer);
        $key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>3072]);
        $csr = openssl_csr_new(['commonName'=>'PDF Sealer External Issuer TEST ONLY'],$key,['config'=>$config]);
        $cert = openssl_csr_sign($csr,$rootPem,$root->privateKey(),365,['config'=>$config,'x509_extensions'=>'ca','digest_alg'=>'sha256'],random_int(1,PHP_INT_MAX));
        if ($cert === false || !openssl_x509_export($cert,$caPem) || !openssl_pkey_export($key,$keyPem)) throw new RuntimeException('Test CA generation failed');
        if (!mkdir($dir,0700,true)) throw new RuntimeException('Cannot create test CA directory');
        foreach (['issuer-key.pem'=>$keyPem,'issuer.pem'=>$caPem,'chain.pem'=>$caPem.$rootPem] as $name=>$contents) {
            if (file_put_contents($dir.'/'.$name,$contents) !== strlen($contents)) throw new RuntimeException('Test fixture write failed');
        }
        echo "TEST ONLY: register the public chain: $dir/chain.pem\n";
        echo "Disposable CA key retained locally with owner-only permissions; never upload it to REDCap.\n";
    } else {
        $csr = file_get_contents($argv[2],false,null,0,16385);
        if (!is_string($csr) || strlen($csr)>16384 || openssl_csr_get_public_key($csr) === false) throw new RuntimeException('Invalid public CSR');
        $caPem = file_get_contents($dir.'/issuer.pem');
        $key = openssl_pkey_get_private(file_get_contents($dir.'/issuer-key.pem'));
        if ($key === false || !openssl_x509_check_private_key($caPem,$key)) throw new RuntimeException('Test CA unavailable');
        $cert = openssl_csr_sign($csr,$caPem,$key,180,['config'=>$config,'x509_extensions'=>'leaf','digest_alg'=>'sha256'],random_int(1,PHP_INT_MAX));
        if ($cert === false || !openssl_x509_export($cert,$pem)) throw new RuntimeException('CSR signing failed');
        $path = $dir.'/issued-'.substr(hash('sha256',$csr),0,16).'.pem';
        if (file_put_contents($path,$pem)!==strlen($pem)) throw new RuntimeException('Certificate write failed');
        echo "TEST ONLY: upload this returned signing certificate: $path\n";
    }
} finally { foreach ($paths as $path) { if (is_file($path)) unlink($path); } }
