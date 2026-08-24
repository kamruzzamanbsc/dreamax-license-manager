# Capability and role matrix

| Capability | Administrator | Shop Manager default |
| --- | --- | --- |
| `dreamax_lm_manage_licenses` | Yes | Yes |
| `dreamax_lm_reveal_license_keys` | Yes | No |
| `dreamax_lm_export_license_keys` | Yes | No |
| `dreamax_lm_manage_api_credentials` | Yes | No |
| `dreamax_lm_delete_license_records` | Yes | No |
| `dreamax_lm_manage_security` | Yes | No |
| `dreamax_lm_view_diagnostics` | Yes | Yes, non-secret only |

Delegation is manual or filter-driven and must be reviewed. Deactivation preserves grants. Explicit uninstall cleanup may remove grants only together with the documented data-retention choice.

Ordinary transitions, extensions, activation resets, and reassignment require `dreamax_lm_manage_licenses`. Permanent record deletion additionally checks `dreamax_lm_delete_license_records` at the action boundary and is limited to unassigned pool records without order, customer, or activation history.
