# Key normalization and generator policy

Generated keys use `generated-ascii-v1`; imported arbitrary keys default to `import-exact-v1`. Profiles are immutable per license/import batch. Never guess a profile or silently migrate it.

The default alphabet is `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, with 32 unique symbols and 26 independent random positions: `26 × log2(32) = 130` effective bits. Prefix `DLM`, separators, and fixed text add zero. Configuration below 96 bits is rejected; 96–127.999… bits requires a visible warning; 128+ is the safe default.

The unit suite contains the frozen prompt vectors, including Unicode punctuation rejection, internal-tab rejection, undeclared separator rejection, exact import whitespace/case preservation, BOM/record-ending removal, and NFC/NFD distinction.
