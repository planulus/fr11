# AGENTS.md — ECA module

Module-specific instructions for agents working inside
`web/modules/contrib/eca/` (the ECA contrib module repository).

## Always run SCOPED PHPUnit tests for MR work

ECA's PHPUnit suite is very large and takes far too long to run in full. When
working on a merge request in this module, **always run scoped PHPUnit tests**
— target the specific test class(es) or method(s) relevant to the change.
**Never run the entire ECA suite** as part of MR work.

This is a policy, not a how-to: the supported mechanism for scoping a run
(passing `--filter=...` to the `l3d ahoy test phpunitmodule eca` wrapper) is
documented in the `l3d` skill. Use that mechanism — do not invent your own
phpunit invocation.
