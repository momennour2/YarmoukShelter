## V5.2
- Finance import: XLSX support, hidden-character tolerant headers, header-row detection, Flow aliases (داخل/طالع), default flow selector, clearer errors.
- Camp logo added to login, console and portals.
- Institution request form restyled; missing portal CSS added.
- Username format AB-123456789; simple passwords (4+) stored with Argon2id; auto-rehash on login.
- "موظف" renamed to "متطوع".

# V4 development update
- Added server-enforced per-module actions and sector allowlists.
- Added account creation/editing, activation/deactivation, and permission editing.
- Added record edit and archive workflows; archive uses POST + CSRF and moves Profiles to R.
- Added family lookup, statistics, sector candidates, finance, aids, HR, storage, CSV profile export, and audit log.
- Added explicit sensitive-field visibility control and preservation of hidden values during edits.
- Admin initialization uses the requested initial credential and forces password change.
- Full XLSM bulk import remains pending a safe preview/mapping workflow.

## V4.0.1 - Admin login recovery
- Fixed setup_admin.php so it resets password_hash even when the admin username already exists.
- Re-enables the admin account and forces password change after recovery.
