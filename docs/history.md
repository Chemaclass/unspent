# History & Provenance

Every output tracks where it came from and where it went.

## Query Provenance

```php
// Who created this output?
$ledger->outputCreatedBy(new OutputId('x')); // 'tx-001' or 'genesis'

// Who spent it?
$ledger->outputSpentBy(new OutputId('x'));   // 'tx-002' or null

// Get output data (even if spent)
$ledger->getOutput(new OutputId('x'));       // Output or null
```

## Full History DTO

```php
$history = $ledger->outputHistory(new OutputId('bob-funds'));

$history->id;         // OutputId
$history->amount;     // 600
$history->lock;       // OutputLock
$history->createdBy;  // 'tx-001' or 'genesis'
$history->spentBy;    // 'tx-002' or null
$history->status;     // OutputStatus::SPENT or UNSPENT

// Convenience methods
$history->isSpent();
$history->isUnspent();
$history->isGenesis();
```

## Chain of Custody

Trace value through multiple transactions:

```php
$ledger = Ledger::withGenesis(
    Output::ownedBy('alice', 1000, 'alice-genesis'),
);

// TX1: Alice -> Bob
$ledger = $ledger->apply(Tx::create(
    spendIds: ['alice-genesis'],
    outputs: [
        Output::ownedBy('bob', 600, 'bob-funds'),
        Output::ownedBy('alice', 400, 'alice-change'),
    ],
    signedBy: 'alice',
    id: 'tx-001',
));

// TX2: Bob -> Charlie
$ledger = $ledger->apply(Tx::create(
    spendIds: ['bob-funds'],
    outputs: [Output::ownedBy('charlie', 600, 'charlie-funds')],
    signedBy: 'bob',
    id: 'tx-002',
));

// Trace the chain
$ledger->outputCreatedBy(new OutputId('alice-genesis')); // 'genesis'
$ledger->outputSpentBy(new OutputId('alice-genesis'));   // 'tx-001'

$ledger->outputCreatedBy(new OutputId('bob-funds'));     // 'tx-001'
$ledger->outputSpentBy(new OutputId('bob-funds'));       // 'tx-002'

$ledger->outputCreatedBy(new OutputId('charlie-funds')); // 'tx-002'
$ledger->outputSpentBy(new OutputId('charlie-funds'));   // null (unspent)
```

## Serialization

History survives save/restore:

```php
$json = $ledger->toJson();
$restored = Ledger::fromJson($json);

$restored->outputCreatedBy(new OutputId('bob-funds')); // 'tx-001'
$restored->getOutput(new OutputId('bob-funds'));       // Output object
```

## Next Steps

- [Core Concepts](concepts.md) - The UTXO model explained
- [API Reference](api-reference.md) - Complete method reference
