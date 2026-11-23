# Reconciling portal accounts

The authoritative account ID is the Laravel backend's `users.id`. An Authentik login first looks up the **exact OIDC issuer and subject**. Changes to the provider's email or display name leave this link and the backend profile intact. For a first login, only a provider-verified email matching exactly one available backend account (ignoring case and surrounding spaces) can link that account. An unmatched verified address creates a user with no roles or club memberships. A missing or unverified email, ambiguous match, or account already linked to a different identity requires operator review. Backend password and legacy SSO credentials, roles, and club associations survive linking.

## Inventory and review

1. In the retained **admin-panel prototype** with its own database, export its local accounts. Supply the exact historical Authentik issuer for those subjects, including any trailing slash. Confirm it against the provider registration or records. A change of client or provider configuration can change a user's subject. When the historical issuer or subject is unknown, confirm them independently before importing any mapping. Keep the export in an access-controlled operator directory. It contains personal data.

   ```sh
   umask 077
   php artisan authentik:inventory 'https://identity.example.org/application/o/portal/' --no-ansi > portal-accounts.json
   ```

2. On the backend, run the read-only report. The inventory's issuer must exactly match the configured Authentik issuer. The report shows portal IDs, backend candidate IDs and findings. Even a unique email candidate from this historical export needs review. Portal and backend IDs belong to separate databases.

   ```sh
   php artisan authentik:reconcile /private/portal-accounts.json
   ```

3. After confirming identity using trusted account records, create a reviewed file containing only selected rows and add the existing **backend** user ID to each. Users without a known subject must first be identified with the provider. An operator can resolve ambiguous names or email matches with an explicitly selected ID. Existing conflicting links are never reassigned by this command. In particular, verify the target identity independently before mapping an administrator.

   ```json
   {
     "issuer": "https://identity.example.org/application/o/portal/",
     "accounts": [
       {"portal_user_id": 8, "subject": "opaque-subject", "email": "person@example.org", "backend_user_id": 42}
     ]
   }
   ```

4. Run the report on that file and apply the reviewed mappings. Import only links existing users. Duplicate source records, missing targets, or conflicting identities abort the whole import. Repeating an identical import is safe. Verify the same backend user ID and existing permissions after sign-in, then remove the private inventory and review copies according to your retention policy.

   ```sh
   php artisan authentik:reconcile /private/reviewed.json
   php artisan authentik:reconcile /private/reviewed.json --apply
   ```
