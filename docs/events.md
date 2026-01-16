# Events

## Overview

The event system allows you to react to ledger state changes. Every transaction, coinbase, and validation failure can trigger events for logging, analytics, webhooks, or event sourcing.

The `EventDispatchingLedger` decorator wraps any ledger and dispatches events via a callable, making it compatible with PSR-14 event dispatchers.

## Quick Start

```php
use Chemaclass\Unspent\Event\EventDispatchingLedger;
use Chemaclass\Unspent\Event\LedgerEvent;
use Chemaclass\Unspent\Ledger;
use Chemaclass\Unspent\Output;
use Chemaclass\Unspent\Tx;

// Collect events
$events = [];
$dispatcher = function (LedgerEvent $event) use (&$events): void {
    $events[] = $event;
};

// Wrap ledger with event dispatcher
$ledger = EventDispatchingLedger::wrap(
    Ledger::withGenesis(Output::open(1000, 'genesis')),
    $dispatcher,
);

// Apply transaction - events are dispatched automatically
$ledger = $ledger->apply(Tx::create(
    spendIds: ['genesis'],
    outputs: [Output::open(990, 'alice')],
    id: 'tx-001',
));

// Events: TransactionApplied, OutputSpent, OutputCreated
foreach ($events as $event) {
    echo $event->eventType() . "\n";
}
```

## Event Types

| Event | Triggered When | Key Properties |
|-|-|-|
| `TransactionApplied` | Transaction succeeds | `transaction`, `fee`, `inputTotal`, `outputTotal` |
| `OutputSpent` | Output is consumed | `outputId`, `amount`, `spentBy` |
| `OutputCreated` | New output created | `output`, `createdBy` |
| `CoinbaseApplied` | Coinbase (minting) succeeds | `coinbase`, `mintedAmount`, `totalMinted` |
| `ValidationFailed` | Transaction validation fails | `transaction`, `exception` |

All events extend `LedgerEvent` and include a `timestamp` property (Unix timestamp with microseconds).

## Event Details

### TransactionApplied

Dispatched when a transaction is successfully applied.

```php
$event->transaction;   // Tx - the applied transaction
$event->fee;           // int - fee paid (inputs - outputs)
$event->inputTotal;    // int - total spent
$event->outputTotal;   // int - total created
$event->timestamp;     // float - when it happened
$event->eventType();   // 'transaction.applied'
```

### OutputSpent

Dispatched for each output consumed by a transaction.

```php
$event->outputId;      // OutputId - which output was spent
$event->amount;        // int - amount that was in it
$event->spentBy;       // TxId - transaction that spent it
$event->eventType();   // 'output.spent'
```

### OutputCreated

Dispatched for each output created by a transaction or coinbase.

```php
$event->output;        // Output - the new output
$event->createdBy;     // TxId - transaction that created it
$event->eventType();   // 'output.created'
```

### CoinbaseApplied

Dispatched when a coinbase (minting) transaction is applied.

```php
$event->coinbase;      // CoinbaseTx - the coinbase transaction
$event->mintedAmount;  // int - amount minted in this coinbase
$event->totalMinted;   // int - new total minted in ledger
$event->eventType();   // 'coinbase.applied'
```

### ValidationFailed

Dispatched when transaction validation fails (before exception is thrown).

```php
$event->transaction;   // Tx - the failed transaction
$event->exception;     // UnspentException - what went wrong
$event->eventType();   // 'validation.failed'
```

## Integration Examples

### Simple Event Collector

```php
$events = [];
$dispatcher = fn(LedgerEvent $e) => $events[] = $e;

$ledger = EventDispatchingLedger::wrap(Ledger::inMemory(), $dispatcher);
```

### PSR-14 Event Dispatcher

```php
use Psr\EventDispatcher\EventDispatcherInterface;

$psr14Dispatcher = /* your PSR-14 dispatcher */;

$ledger = EventDispatchingLedger::wrap(
    Ledger::inMemory(),
    fn(LedgerEvent $e) => $psr14Dispatcher->dispatch($e),
);
```

### Logging Integration

```php
use Psr\Log\LoggerInterface;

$logger = /* your PSR-3 logger */;

$dispatcher = function (LedgerEvent $event) use ($logger): void {
    match (true) {
        $event instanceof TransactionApplied => $logger->info('Transaction applied', [
            'txId' => $event->transaction->id->value,
            'fee' => $event->fee,
        ]),
        $event instanceof ValidationFailed => $logger->warning('Transaction failed', [
            'txId' => $event->transaction->id->value,
            'error' => $event->exception->getMessage(),
        ]),
        default => null,
    };
};

$ledger = EventDispatchingLedger::wrap(Ledger::inMemory(), $dispatcher);
```

### Event Store / Analytics

```php
class EventStore
{
    private array $events = [];

    public function dispatch(LedgerEvent $event): void
    {
        $this->events[$event->eventType()][] = $event;
    }

    public function getByType(string $type): array
    {
        return $this->events[$type] ?? [];
    }
}

$store = new EventStore();
$ledger = EventDispatchingLedger::wrap(Ledger::inMemory(), $store->dispatch(...));

// Later: query events by type
$transactions = $store->getByType('transaction.applied');
```

## Event Sourcing Pattern

Use outputs to model state machines. Each state transition spends the old state and creates a new one.

```php
// Order lifecycle: placed -> paid -> shipped -> delivered
$orders = Ledger::withGenesis(
    Output::open(1, 'order-1001_placed'),
);

// Transition: placed -> paid
$orders = $orders->apply(Tx::create(
    spendIds: ['order-1001_placed'],
    outputs: [Output::open(1, 'order-1001_paid')],
    id: 'evt_payment',
));

// Transition: paid -> shipped
$orders = $orders->apply(Tx::create(
    spendIds: ['order-1001_paid'],
    outputs: [Output::open(1, 'order-1001_shipped')],
    id: 'evt_shipped',
));

// Query current state
foreach ($orders->unspent() as $id => $output) {
    [$orderId, $state] = explode('_', $id);
    echo "{$orderId}: {$state}\n";  // order-1001: shipped
}

// Query history
$orders->outputCreatedBy(new OutputId('order-1001_paid'));   // 'evt_payment'
$orders->outputSpentBy(new OutputId('order-1001_paid'));     // 'evt_shipped'
```

With events:

```php
$events = [];
$dispatcher = fn(LedgerEvent $e) => $events[] = $e;

$orders = EventDispatchingLedger::wrap(
    Ledger::withGenesis(Output::open(1, 'order-1001_placed')),
    $dispatcher,
);

$orders = $orders->apply(Tx::create(
    spendIds: ['order-1001_placed'],
    outputs: [Output::open(1, 'order-1001_paid')],
    id: 'evt_payment',
));

// Events dispatched:
// - TransactionApplied (txId: evt_payment)
// - OutputSpent (outputId: order-1001_placed)
// - OutputCreated (outputId: order-1001_paid)
```

## Combining with LoggingLedger

You can compose multiple decorators:

```php
use Chemaclass\Unspent\Logging\LoggingLedger;

$logger = /* your PSR-3 logger */;
$events = [];

// First wrap with logging, then with events
$ledger = Ledger::inMemory();
$ledger = LoggingLedger::wrap($ledger, $logger);
$ledger = EventDispatchingLedger::wrap($ledger->unwrap(), fn($e) => $events[] = $e);
```

Or create your own composite:

```php
$baseLedger = Ledger::withGenesis(Output::open(1000, 'genesis'));

// Events for external systems
$eventLedger = EventDispatchingLedger::wrap($baseLedger, $eventBus->dispatch(...));

// Logging for debugging
$loggingLedger = LoggingLedger::wrap($eventLedger->unwrap(), $logger);
```

## Accessing the Underlying Ledger

Use `unwrap()` to get the underlying `Ledger`:

```php
$wrapped = EventDispatchingLedger::wrap($ledger, $dispatcher);
$unwrapped = $wrapped->unwrap();  // Returns Ledger instance
```

## Next Steps

- [Concepts](concepts.md) - Core ledger concepts
- [History](history.md) - Output provenance tracking
- [Persistence](persistence.md) - Saving ledger state
