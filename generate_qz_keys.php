<?php
$dir = __DIR__ . '/storage/app/qz';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$certFile = $dir . '/digital-certificate.txt';
$keyFile = $dir . '/private-key.pem';

/*
if (file_exists($certFile) && file_exists($keyFile)) {
    echo "Keys already exist.\n";
    exit;
}
*/

$config = array(
    "digest_alg" => "sha1",
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
    "config" => __DIR__ . '/openssl.cnf'
);

// Create the private and public key
$res = openssl_pkey_new($config);

// Extract the private key from $res to $privKey
openssl_pkey_export($res, $privKey);

// Extract the public key from $res to $pubKey
$pubKey = openssl_pkey_get_details($res);
$pubKey = $pubKey["key"];

// Generate CSR
$dn = array(
    "countryName" => "TG",
    "stateOrProvinceName" => "Maritime",
    "localityName" => "Lome",
    "organizationName" => "SmartFlow POS",
    "organizationalUnitName" => "Dev",
    "commonName" => "SmartFlow POS",
    "emailAddress" => "contact@smartflowpos.com"
);
$csr = openssl_csr_new($dn, $res, array('digest_alg' => 'sha1', 'config' => __DIR__ . '/openssl.cnf'));
$x509 = openssl_csr_sign($csr, null, $res, $days=3650, array('digest_alg' => 'sha1', 'config' => __DIR__ . '/openssl.cnf'));
openssl_x509_export($x509, $certout);

file_put_contents($keyFile, $privKey);
file_put_contents($certFile, $certout);

echo "Keys generated successfully in $dir\n";
echo "--- CERTIFICATE START ---\n";
echo $certout;
echo "--- CERTIFICATE END ---\n";
