<?php

/**
 * Simple Web API Example
 *
 * Demonstrates how to use Unspent in a web application context.
 *
 * Run with: php -S localhost:8080 example/web-api.php
 *
 * Endpoints:
 *   GET  /balance?owner=alice     - Get owner's balance
 *   GET  /outputs?owner=alice     - List owner's outputs
 *   POST /transfer                - Transfer value between owners
 *   GET  /history?id=output-id    - Get output history
 *   GET  /                        - Show API documentation
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Chemaclass\Unspent\CoinbaseTx;
use Chemaclass\Unspent\Exception\UnspentException;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;

// Simple in-memory state (use SqliteHistoryRepository for persistence)
session_start();

// Initialize or restore ledger
if (!isset($_SESSION['ledger_json'])) {
    $ledger = Ledger::withGenesis(
        Output::ownedBy('alice', 1000, 'alice-initial'),
        Output::ownedBy('bob', 500, 'bob-initial'),
    );
    $_SESSION['ledger_json'] = $ledger->toJson();
} else {
    $ledger = Ledger::fromJson($_SESSION['ledger_json']);
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('Content-Type: application/json');

try {
    $response = match (true) {
        $path === '/' && $method === 'GET' => handleDocs(),
        $path === '/balance' && $method === 'GET' => handleBalance($ledger),
        $path === '/outputs' && $method === 'GET' => handleOutputs($ledger),
        $path === '/transfer' && $method === 'POST' => handleTransfer($ledger),
        $path === '/mint' && $method === 'POST' => handleMint($ledger),
        $path === '/history' && $method === 'GET' => handleHistory($ledger),
        $path === '/reset' && $method === 'POST' => handleReset(),
        default => ['error' => 'Not found', 'status' => 404],
    };

    $status = $response['status'] ?? 200;
    http_response_code($status);
    unset($response['status']);
    echo json_encode($response, JSON_PRETTY_PRINT);
} catch (UnspentException $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal error: ' . $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}

/**
 * @return array<string, mixed>
 */
function handleDocs(): array
{
    return [
        'name' => 'Unspent Web API Example',
        'endpoints' => [
            'GET /' => 'This documentation',
            'GET /balance?owner=alice' => 'Get owner balance',
            'GET /outputs?owner=alice' => 'List owner outputs',
            'POST /transfer' => 'Transfer value (body: {"from": "alice", "to": "bob", "amount": 100})',
            'POST /mint' => 'Mint new value (body: {"to": "alice", "amount": 100})',
            'GET /history?id=output-id' => 'Get output history',
            'POST /reset' => 'Reset ledger to initial state',
        ],
        'example' => 'curl -X POST localhost:8080/transfer -d \'{"from":"alice","to":"bob","amount":100}\'',
    ];
}

/**
 * @return array<string, mixed>
 */
function handleBalance(Ledger $ledger): array
{
    $owner = $_GET['owner'] ?? null;
    if ($owner === null) {
        return ['error' => 'Missing owner parameter', 'status' => 400];
    }

    return [
        'owner' => $owner,
        'balance' => $ledger->totalUnspentByOwner($owner),
    ];
}

/**
 * @return array<string, mixed>
 */
function handleOutputs(Ledger $ledger): array
{
    $owner = $_GET['owner'] ?? null;
    if ($owner === null) {
        return ['error' => 'Missing owner parameter', 'status' => 400];
    }

    $outputs = [];
    foreach ($ledger->unspentByOwner($owner) as $output) {
        $outputs[] = [
            'id' => $output->id->value,
            'amount' => $output->amount,
        ];
    }

    return [
        'owner' => $owner,
        'outputs' => $outputs,
        'total' => $ledger->totalUnspentByOwner($owner),
    ];
}

/**
 * @return array<string, mixed>
 */
function handleTransfer(Ledger &$ledger): array
{
    $rawInput = file_get_contents('php://input');
    if ($rawInput === false) {
        return ['error' => 'Failed to read request body', 'status' => 400];
    }

    /** @var array<string, mixed>|null $input */
    $input = json_decode($rawInput, true);

    $from = $input['from'] ?? null;
    $to = $input['to'] ?? null;
    $amount = $input['amount'] ?? null;

    if ($from === null || $to === null || $amount === null) {
        return ['error' => 'Missing from, to, or amount', 'status' => 400];
    }

    $amount = (int) $amount;

    // Select outputs to spend
    /** @var list<string> $outputsToSpend */
    $outputsToSpend = [];
    $accumulated = 0;

    foreach ($ledger->unspentByOwner($from) as $output) {
        $outputsToSpend[] = $output->id->value;
        $accumulated += $output->amount;
        if ($accumulated >= $amount) {
            break;
        }
    }

    if ($accumulated < $amount) {
        return ['error' => "Insufficient funds. Available: {$accumulated}", 'status' => 400];
    }

    $outputs = [Output::ownedBy($to, $amount)];
    $change = $accumulated - $amount;
    if ($change > 0) {
        $outputs[] = Output::ownedBy($from, $change);
    }

    $ledger->apply(Tx::create(
        spendIds: $outputsToSpend,
        outputs: $outputs,
        signedBy: $from,
    ));

    // Persist state
    $_SESSION['ledger_json'] = $ledger->toJson();

    return [
        'success' => true,
        'transferred' => $amount,
        'from' => $from,
        'to' => $to,
        'change' => $change,
        'new_balance' => [
            $from => $ledger->totalUnspentByOwner($from),
            $to => $ledger->totalUnspentByOwner($to),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function handleMint(Ledger &$ledger): array
{
    $rawInput = file_get_contents('php://input');
    if ($rawInput === false) {
        return ['error' => 'Failed to read request body', 'status' => 400];
    }

    /** @var array<string, mixed>|null $input */
    $input = json_decode($rawInput, true);

    $to = $input['to'] ?? null;
    $amount = $input['amount'] ?? null;

    if ($to === null || $amount === null) {
        return ['error' => 'Missing to or amount', 'status' => 400];
    }

    $ledger->applyCoinbase(CoinbaseTx::create(
        outputs: [Output::ownedBy($to, (int) $amount)],
    ));

    $_SESSION['ledger_json'] = $ledger->toJson();

    return [
        'success' => true,
        'minted' => (int) $amount,
        'to' => $to,
        'new_balance' => $ledger->totalUnspentByOwner($to),
        'total_minted' => $ledger->totalMinted(),
    ];
}

/**
 * @return array<string, mixed>
 */
function handleHistory(Ledger $ledger): array
{
    $id = $_GET['id'] ?? null;
    if ($id === null) {
        return ['error' => 'Missing id parameter', 'status' => 400];
    }

    $history = $ledger->outputHistory($id);
    if ($history === null) {
        return ['error' => 'Output not found', 'status' => 404];
    }

    return [
        'id' => $id,
        'amount' => $history->amount,
        'created_by' => $history->createdBy,
        'spent' => $history->isSpent(),
        'spent_by' => $history->spentBy,
    ];
}

/**
 * @return array<string, mixed>
 */
function handleReset(): array
{
    unset($_SESSION['ledger_json']);

    return [
        'success' => true,
        'message' => 'Ledger reset. Refresh to start fresh.',
    ];
}
