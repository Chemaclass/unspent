<?php

declare(strict_types=1);

/**
 * Crypto Wallet Example - Trustless Ed25519 Signatures
 *
 * Demonstrates the PublicKey lock using Ed25519 cryptographic signatures.
 * This is for trustless systems where the server doesn't control authentication.
 *
 * Run with: php example/crypto-wallet.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\Exception\AuthorizationException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\OutputId;
use Chemaclass\Unspent\Tx;

echo "==========================================================\n";
echo " Crypto Wallet Example - Trustless Ed25519 Signatures\n";
echo "==========================================================\n\n";

// ============================================================================
// 1. KEY GENERATION - Client-Side Keypairs
// ============================================================================

echo "1. KEY GENERATION - Creating Client Keypairs\n";
echo "---------------------------------------------\n";

// Alice generates her keypair (client-side, private key stays secret)
$aliceKeypair = sodium_crypto_sign_keypair();
$alicePublicKey = base64_encode(sodium_crypto_sign_publickey($aliceKeypair));
$alicePrivateKey = sodium_crypto_sign_secretkey($aliceKeypair);

// Bob generates his keypair
$bobKeypair = sodium_crypto_sign_keypair();
$bobPublicKey = base64_encode(sodium_crypto_sign_publickey($bobKeypair));
$bobPrivateKey = sodium_crypto_sign_secretkey($bobKeypair);

echo "Alice's public key: " . substr($alicePublicKey, 0, 20) . "...\n";
echo "Bob's public key:   " . substr($bobPublicKey, 0, 20) . "...\n";
echo "Private keys stay secret on client devices.\n\n";

// ============================================================================
// 2. CREATING CRYPTO-LOCKED OUTPUTS
// ============================================================================

echo "2. CREATING CRYPTO-LOCKED OUTPUTS\n";
echo "----------------------------------\n";

$wallet = Ledger::withGenesis(
    Output::signedBy($alicePublicKey, 1000, 'alice-wallet'),
    Output::signedBy($bobPublicKey, 500, 'bob-wallet'),
);

echo "Created wallets locked by public keys:\n";
echo "  Alice: 1000 units (requires Alice's signature to spend)\n";
echo "  Bob:   500 units (requires Bob's signature to spend)\n";
echo "  Total: {$wallet->totalUnspentAmount()} units\n\n";

// ============================================================================
// 3. SIGNING TRANSACTIONS - Client Signs with Private Key
// ============================================================================

echo "3. SIGNING TRANSACTIONS\n";
echo "------------------------\n";

// Alice wants to send 300 to Bob
// The transaction ID is what gets signed
$txId = 'tx-alice-to-bob-001';

// Alice signs the transaction ID with her private key
$aliceSignature = base64_encode(
    sodium_crypto_sign_detached($txId, $alicePrivateKey),
);

echo "Alice creates a transaction to send 300 to Bob.\n";
echo "  Transaction ID: {$txId}\n";
echo '  Signature: ' . substr($aliceSignature, 0, 30) . "...\n";

// Apply the signed transaction
$wallet = $wallet->apply(Tx::create(
    inputIds: ['alice-wallet'],
    outputs: [
        Output::signedBy($bobPublicKey, 300, 'bob-received'),
        Output::signedBy($alicePublicKey, 700, 'alice-change'),
    ], // Proof at index 0 for input 0
    id: $txId,
    proofs: [$aliceSignature],
));

echo "  Transaction verified and applied!\n";
echo "  Alice: 1000 -> 700 (sent 300)\n";
echo "  Bob:   500 -> 800 (received 300)\n\n";

// ============================================================================
// 4. UNAUTHORIZED ATTEMPTS - Wrong Signature
// ============================================================================

echo "4. UNAUTHORIZED ATTEMPTS\n";
echo "-------------------------\n";

// Mallory tries to spend Bob's funds with a fake signature
echo "Mallory tries to spend Bob's funds with wrong signature...\n";

$malloryKeypair = sodium_crypto_sign_keypair();
$malloryPrivateKey = sodium_crypto_sign_secretkey($malloryKeypair);

$fakeTxId = 'tx-steal-bob';
$fakeSignature = base64_encode(
    sodium_crypto_sign_detached($fakeTxId, $malloryPrivateKey),
);

try {
    $wallet->apply(Tx::create(
        inputIds: ['bob-wallet'],
        outputs: [Output::open(500, 'stolen')],
        id: $fakeTxId,
        proofs: [$fakeSignature],
    ));
} catch (AuthorizationException $e) {
    echo "  BLOCKED: {$e->getMessage()}\n";
}

// Try without any signature
echo "\nMallory tries without any signature...\n";
try {
    $wallet->apply(Tx::create(
        inputIds: ['bob-wallet'],
        outputs: [Output::open(500, 'stolen-2')],
        id: 'tx-no-sig',
        proofs: [],
    ));
} catch (AuthorizationException $e) {
    echo "  BLOCKED: {$e->getMessage()}\n";
}
echo "\n";

// ============================================================================
// 5. MULTI-INPUT TRANSACTIONS - Multiple Signatures
// ============================================================================

echo "5. MULTI-INPUT TRANSACTIONS - Multiple Signatures\n";
echo "--------------------------------------------------\n";

// Bob wants to combine his two outputs
// Each input needs its own signature at the matching index
$combineTxId = 'tx-bob-combine';

// Sign the tx ID with Bob's key (same signature for both inputs since same owner)
$bobSig1 = base64_encode(sodium_crypto_sign_detached($combineTxId, $bobPrivateKey));
$bobSig2 = base64_encode(sodium_crypto_sign_detached($combineTxId, $bobPrivateKey));

$wallet = $wallet->apply(Tx::create(
    inputIds: ['bob-wallet', 'bob-received'], // 500 + 300 = 800
    outputs: [Output::signedBy($bobPublicKey, 800, 'bob-combined')], // Signature at each input index
    id: $combineTxId,
    proofs: [$bobSig1, $bobSig2],
));

echo "Bob combines his two outputs (500 + 300):\n";
echo "  Input 0 (bob-wallet): verified with signature\n";
echo "  Input 1 (bob-received): verified with signature\n";
echo "  Combined output: 800 units\n\n";

// ============================================================================
// 6. MIXED OWNERSHIP - Different Keys Per Input
// ============================================================================

echo "6. MIXED OWNERSHIP - Joint Transaction\n";
echo "---------------------------------------\n";

// Alice and Bob both contribute to a joint output
$jointTxId = 'tx-joint-contribution';

// Each signs with their respective private key
$aliceSigJoint = base64_encode(sodium_crypto_sign_detached($jointTxId, $alicePrivateKey));
$bobSigJoint = base64_encode(sodium_crypto_sign_detached($jointTxId, $bobPrivateKey));

$wallet = $wallet->apply(Tx::create(
    inputIds: ['alice-change', 'bob-combined'], // Alice's 700 + Bob's 800
    outputs: [
        Output::signedBy($alicePublicKey, 750, 'alice-final'),
        Output::signedBy($bobPublicKey, 750, 'bob-final'),
    ], // Index 0 = Alice's sig, Index 1 = Bob's sig
    id: $jointTxId,
    proofs: [$aliceSigJoint, $bobSigJoint],
));

echo "Alice (700) and Bob (800) create a joint transaction:\n";
echo "  Input 0: Alice's 700 (Alice signature at index 0)\n";
echo "  Input 1: Bob's 800 (Bob signature at index 1)\n";
echo "  Output: Each gets 750\n";
echo '  Fee: ' . $wallet->feeForTx(new Chemaclass\Unspent\TxId($jointTxId)) . " units\n\n";

// ============================================================================
// 7. VERIFYING LOCK TYPE
// ============================================================================

echo "7. VERIFYING LOCK TYPE\n";
echo "----------------------\n";

echo "Current outputs and their lock types:\n";
foreach ($wallet->unspent() as $id => $output) {
    /** @var array{type: string, key?: string, name?: string} $lock */
    $lock = $output->lock->toArray();
    $lockType = $lock['type'];
    if ($lockType === 'pubkey' && isset($lock['key'])) {
        $keyPreview = substr($lock['key'], 0, 15) . '...';
        echo "  {$id}: {$output->amount} units [PublicKey: {$keyPreview}]\n";
    } else {
        echo "  {$id}: {$output->amount} units [{$lockType}]\n";
    }
}
echo "\n";

// ============================================================================
// 8. HISTORY & PROVENANCE
// ============================================================================

echo "8. HISTORY & PROVENANCE\n";
echo "------------------------\n";

// Trace Bob's final output
echo "Tracing bob-final back to genesis:\n";
$history = $wallet->outputHistory(new OutputId('bob-final'));
\assert($history !== null);
echo "  Current: bob-final ({$history['amount']} units)\n";
echo "  Created by: {$history['createdBy']}\n";

// Chain of custody
$bobInputs = ['bob-wallet', 'bob-received', 'bob-combined', 'bob-final'];
echo "\nBob's full chain of custody:\n";
foreach ($bobInputs as $outputId) {
    $created = $wallet->outputCreatedBy(new OutputId($outputId));
    $spent = $wallet->outputSpentBy(new OutputId($outputId));
    $status = $spent !== null ? "spent in {$spent}" : 'unspent';
    echo "  {$outputId}: created by {$created}, {$status}\n";
}
echo "\n";

// ============================================================================
// 9. SERIALIZATION - Preserve Crypto Locks
// ============================================================================

echo "9. SERIALIZATION - Preserve Crypto Locks\n";
echo "-----------------------------------------\n";

// Save wallet state
$savedState = $wallet->toJson();
echo 'Wallet state saved (' . \strlen($savedState) . " bytes)\n";

// Restore wallet
$restored = Ledger::fromJson($savedState);
echo "Wallet state restored.\n";

// Verify the restored wallet still requires signatures
echo "\nVerifying crypto locks survive serialization:\n";
$aliceFinal = $restored->unspent()->get(new OutputId('alice-final'));
\assert($aliceFinal !== null);
/** @var array{type: string, key?: string, name?: string} $lock */
$lock = $aliceFinal->lock->toArray();
echo "  alice-final lock type: {$lock['type']}\n";
$keyMatch = isset($lock['key']) && $lock['key'] === $alicePublicKey;
echo '  Public key preserved: ' . ($keyMatch ? 'YES' : 'NO') . "\n";

// Try spending without proper signature (should fail)
echo "\nAttempting to spend restored output without signature...\n";
try {
    $restored->apply(Tx::create(
        inputIds: ['alice-final'],
        outputs: [Output::open(750, 'theft')],
        id: 'post-restore-theft',
        proofs: [],
    ));
} catch (AuthorizationException) {
    echo "  BLOCKED: Signature still required after restore!\n";
}

// ============================================================================
// 10. COMPARING LOCK TYPES
// ============================================================================

echo "\n10. COMPARING LOCK TYPES\n";
echo "-------------------------\n";

echo "Server-side (Owner lock):\n";
echo "  + Simple: just pass signedBy='alice'\n";
echo "  + Server verifies identity (session/JWT)\n";
echo "  - Requires trusted server\n";
echo "  - Server could be compromised\n\n";

echo "Trustless (PublicKey lock):\n";
echo "  + No trusted party needed\n";
echo "  + Client controls their keys\n";
echo "  + Cryptographically secure\n";
echo "  - More complex (key management)\n";
echo "  - Lost keys = lost funds\n\n";

echo "Use Owner for: web apps, internal systems, any trusted environment\n";
echo "Use PublicKey for: decentralized apps, client wallets, high-security\n";

// ============================================================================
// SUMMARY
// ============================================================================

echo "\n";
echo "==========================================================\n";
echo " Summary\n";
echo "==========================================================\n\n";

echo "Final wallet state:\n";
foreach ($restored->unspent() as $id => $output) {
    /** @var array{type: string, key?: string, name?: string} $lock */
    $lock = $output->lock->toArray();
    $owner = $lock['type'] === 'pubkey' ? 'crypto-locked' : ($lock['name'] ?? 'open');
    echo "  {$id}: {$output->amount} units ({$owner})\n";
}

echo "\nFeatures demonstrated:\n";
echo "  - Ed25519 keypair generation\n";
echo "  - PublicKey-locked outputs\n";
echo "  - Transaction signing with private key\n";
echo "  - Signature verification\n";
echo "  - Multi-input with multiple signatures\n";
echo "  - Mixed ownership (different keys per input)\n";
echo "  - Unauthorized attempt rejection\n";
echo "  - Lock type verification\n";
echo "  - History/provenance tracking\n";
echo "  - Crypto locks survive serialization\n";

echo "\n";
