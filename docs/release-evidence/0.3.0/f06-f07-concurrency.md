# F06/F07 concurrency evidence - 0.3.0

- Date: 2026-08-28
- Classification: `PASS_WITH_EVIDENCE` for F06 and F07
- Environment: WordPress 7.1; WooCommerce 11.0.1; PHP 8.2.4; MariaDB 10.4.28; HPOS enabled; Dreamax License Manager 0.3.0
- Scope: last-pool-row order allocation; different-installation activation limit; same-installation convergence; audit exactness; ownership-scoped cleanup
- Sensitive-data handling: no license value, product, order, order-item, license, activation, event, request, database, installation, or private identifier; Cookie value; nonce; credential; URL; or private path is retained.

## Guarded parallel execution

The verifier required the explicit disposable-environment marker, active WordPress/WooCommerce/plugin runtime, and InnoDB for every table touched by fixture creation, races, or cleanup. It refused stale owned product or order fixtures and disabled outbound WooCommerce order email.

Each race used two separate PHP worker processes. Sensitive synthetic inputs travelled only through private parent/child standard-input pipes, never command arguments or files. A permission-restricted temporary barrier coordinated both workers. The parent held the exact owned license row with `FOR UPDATE`, released both workers, verified both remained in flight while blocked on that row, and only then committed the gate transaction.

## F06 last pool row

Two paid Processing orders each requested one license while the temporary product had exactly one available imported pool row.

- both contenders were simultaneously in flight before the gate row was released;
- exactly one order received the license and the other reported one recoverable failure;
- the assigned order had zero missing slots and the other retained one missing slot;
- the pool row was assigned once; and
- created, assigned, delivered, allocation-failed, and automatic-allocation-completed events were each present exactly once where required.

## F07 activation races

For different installations competing for the final slot of an activation-limit-one license:

- both contenders were simultaneously in flight;
- exactly one succeeded and one received the stable activation-limit rejection;
- one active installation row remained; and
- one activation event was recorded.

For two contenders presenting the same installation to another activation-limit-one license:

- both contenders were simultaneously in flight;
- both calls succeeded as one initial result and one replay;
- both converged on one active installation row; and
- one activation event was recorded.

## Restoration and regression coverage

An ownership-scoped InnoDB cleanup transaction removed only the temporary activation, event, license, order, and product fixtures. Cleanup committed, no owned fixture row remained, and plugin aggregate counts matched the starting snapshot. The private barrier directory was also removed. No outbound email was sent.

The source-contract test preserves the pool-row lock, license-row activation lock, unique installation storage, two-worker in-flight proof, fixed outcome contracts, private standard-input transport, closed-process cleanup guard, and exact-restoration assertions.

## Result

The final pool row cannot be assigned twice, different installations cannot consume the last activation slot twice, and same-installation concurrency converges idempotently. F06 and F07 are `PASS_WITH_EVIDENCE`.
