<?php

declare(strict_types=1);

/**
 * Crypto Wallet - Ed25519 Signatures
 *
 * Shows trustless transactions with cryptographic signatures.
 *
 * Run: php example/crypto-wallet.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;

echo "Crypto Wallet Example\n";
echo "=====================\n\n";

// 1. Generate keypairs (client-side)
$aliceKp = sodium_crypto_sign_keypair();
$alicePub = base64_encode(sodium_crypto_sign_publickey($aliceKp));
$alicePriv = sodium_crypto_sign_secretkey($aliceKp);

$bobKp = sodium_crypto_sign_keypair();
$bobPub = base64_encode(sodium_crypto_sign_publickey($bobKp));
$bobPriv = sodium_crypto_sign_secretkey($bobKp);

echo "Keys generated:\n";
echo '  Alice: ' . substr($alicePub, 0, 16) . "...\n";
echo '  Bob:   ' . substr($bobPub, 0, 16) . "...\n\n";

// 2. Create wallets locked by public keys
$wallet = Ledger::withGenesis(
    Output::signedBy($alicePub, 1000, 'alice-wallet'),
    Output::signedBy($bobPub, 500, 'bob-wallet'),
);
echo "Wallets: Alice=1000, Bob=500\n\n";

// 3. Alice sends to Bob (signs with private key)
$txId = 'tx-001';
$sig = base64_encode(sodium_crypto_sign_detached($txId, $alicePriv));

$wallet = $wallet->apply(Tx::create(
    spendIds: ['alice-wallet'],
    outputs: [
        Output::signedBy($bobPub, 300, 'bob-received'),
        Output::signedBy($alicePub, 700, 'alice-change'),
    ],
    id: $txId,
    proofs: [$sig],
));
echo "Alice -> Bob: 300 (signed)\n";

// 4. Wrong signature blocked
echo "\nMallory tries to steal with wrong key... ";
$malloryKp = sodium_crypto_sign_keypair();
$malloryPriv = sodium_crypto_sign_secretkey($malloryKp);
$fakeSig = base64_encode(sodium_crypto_sign_detached('tx-steal', $malloryPriv));

try {
    $wallet->apply(Tx::create(
        spendIds: ['bob-wallet'],
        outputs: [Output::open(500)],
        id: 'tx-steal',
        proofs: [$fakeSig],
    ));
} catch (AuthorizationException) {
    echo "BLOCKED\n";
}

// 5. Multi-input needs multiple signatures
$txId2 = 'tx-002';
$bobSig1 = base64_encode(sodium_crypto_sign_detached($txId2, $bobPriv));
$bobSig2 = base64_encode(sodium_crypto_sign_detached($txId2, $bobPriv));

$wallet = $wallet->apply(Tx::create(
    spendIds: ['bob-wallet', 'bob-received'], // 500 + 300
    outputs: [Output::signedBy($bobPub, 800, 'bob-combined')], // one per input
    id: $txId2,
    proofs: [$bobSig1, $bobSig2],
));
echo "\nBob combined 500+300 = 800 (multi-sig)\n";

// 6. History preserved
echo "\nHistory:\n";
$history = $wallet->outputHistory(new OutputId('bob-combined'));
echo "  bob-combined: created by {$history?->createdBy}\n";

// 7. Final state
echo "\nFinal balances:\n";
foreach ($wallet->unspent() as $id => $output) {
    echo "  {$id}: {$output->amount}\n";
}
