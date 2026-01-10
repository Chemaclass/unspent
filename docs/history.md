# History & Provenance

The library tracks the complete history of every output, allowing you to trace where value came from and where it went.

## Output Provenance

Every output has provenance information:

- **Created by** - Which transaction (or genesis) created it
- **Spent by** - Which transaction consumed it (if spent)

```php
// Query provenance
$ledger->outputCreatedBy(new OutputId('bob-funds'));  // 'tx-001' or 'genesis'
$ledger->outputSpentBy(new OutputId('alice-funds'));  // 'tx-001' or null if unspent
```

## Accessing Spent Outputs

Even after an output is spent, you can still retrieve its details:

```php
// Get output data even if spent
$output = $ledger->getOutput(new OutputId('spent-output'));
$output->amount;  // Still accessible
$output->lock;    // Still accessible

// Check if output exists (spent or unspent)
$ledger->outputExists(new OutputId('any-output'));  // true/false
```

## Tracing History

Get the complete history of an output with the `OutputHistory` DTO:

```php
$history = $ledger->outputHistory(new OutputId('bob-funds'));

if ($history !== null) {
    $history->id;         // OutputId
    $history->amount;     // 600
    $history->lock;       // OutputLock object
    $history->createdBy;  // 'tx-001' or 'genesis'
    $history->spentBy;    // 'tx-002' or null if unspent
    $history->status;     // OutputStatus enum (SPENT or UNSPENT)

    // Convenience methods
    $history->isSpent();    // true
    $history->isUnspent();  // false
    $history->isGenesis();  // true if createdBy === 'genesis'

    // Serialize if needed
    $history->toArray();    // ['id' => ..., 'amount' => ..., ...]
}
```

## Chain of Custody Example

Track value through multiple transactions:

```php
// Genesis: Alice gets 1000
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-genesis'),
);

// TX1: Alice sends 600 to Bob
$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-genesis'],
    outputs: [
        Output::ownedBy('bob', 600, 'bob-funds'),
        Output::ownedBy('alice', 400, 'alice-change'),
    ],
    signedBy: 'alice',
    id: 'tx-001',
));

// TX2: Bob sends 500 to Charlie
$ledger = $ledger->apply(Tx::create(
    spendIds: ['bob-funds'],
    outputs: [
        Output::ownedBy('charlie', 500, 'charlie-funds'),
        Output::ownedBy('bob', 100, 'bob-change'),
    ],
    signedBy: 'bob',
    id: 'tx-002',
));

// Trace the history
$ledger->outputCreatedBy(new OutputId('alice-genesis'));  // 'genesis'
$ledger->outputSpentBy(new OutputId('alice-genesis'));    // 'tx-001'

$ledger->outputCreatedBy(new OutputId('bob-funds'));      // 'tx-001'
$ledger->outputSpentBy(new OutputId('bob-funds'));        // 'tx-002'

$ledger->outputCreatedBy(new OutputId('charlie-funds'));  // 'tx-002'
$ledger->outputSpentBy(new OutputId('charlie-funds'));    // null (unspent)
```

## Audit Trail

The provenance system enables complete audit trails:

```php
// "Prove that charlie-funds came from alice-genesis"
function traceOrigin(Ledger $ledger, OutputId $outputId): array
{
    $chain = [];
    $current = $outputId;

    while ($current !== null) {
        $createdBy = $ledger->outputCreatedBy($current);
        $chain[] = [
            'output' => $current->value,
            'createdBy' => $createdBy,
        ];

        if ($createdBy === 'genesis') {
            break;
        }

        // Find the input that led to this transaction
        // (requires knowing the spend's inputs)
        break; // Simplified - full implementation would traverse back
    }

    return $chain;
}
```

## Serialization

History is preserved through serialization:

```php
$json = $ledger->toJson();
$restored = Ledger::fromJson($json);

// History still available
$restored->outputCreatedBy(new OutputId('bob-funds'));  // 'tx-001'
$restored->outputSpentBy(new OutputId('bob-funds'));    // 'tx-002'
$restored->getOutput(new OutputId('bob-funds'));        // Output object
```

## Storage Considerations

The history tracking adds storage for:

- `outputCreatedBy` - Map of outputId → spendId (or 'genesis')
- `outputSpentBy` - Map of outputId → spendId
- `spentOutputs` - Map of outputId → output data

This grows with the total number of outputs ever created, not just current UTXOs.

## Next Steps

- [Core Concepts](concepts.md) - Understanding the UTXO model
- [API Reference](api-reference.md) - Complete method reference
