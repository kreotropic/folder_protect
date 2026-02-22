# Changelog

## [2.0.1] - 2026-02-22

### Fixed
- DELETE and MOVE operations on regular (non-group) folders are now correctly blocked; previously these were registered in `beforeMethod` which fires too late in the Sabre event lifecycle — moved to `beforeUnbind` / `beforeMove`
- MKCOL blocked operations now correctly return **403 Forbidden** instead of **201 Created**; the old `sendErrorResponse(403) + return false` pattern in `beforeBind` was overridden by Sabre's internal response setter — fixed by throwing `Sabre\DAV\Exception\Forbidden`
- Desktop client rename-bypass via stepping-stone folder (MKCOL with neutral name + MOVE to protected name) is now blocked; the orphaned stepping-stone folder is automatically deleted from the server
- ETag changes now propagate up to all ancestor directories after a blocked MKCOL so the sync client re-lists parent folders and discards its local-only copy
- `ProtectionChecker::getProtectionInfo()` cache now correctly stores a sentinel for "not found" entries (avoids repeated DB queries for unprotected paths)
- `AdminController::protect()` now reads `userId` from the server-side `IUserSession` instead of trusting a client-supplied parameter
- `Notifier` class moved to correct namespace `OCA\FolderProtection\Notification` (was `OCA\FolderProtection\DAV`)
- Removed dead `OperationForbidden` exception class and unused `getCommands()` method
- `LockPlugin::getInternalPath()` no longer checks the parent directory name (causing false positives)
- `AdminApp.vue` add-protection modal now resets to the Group Folders tab when reopened

---

## [2.0.0] - 2026-02-20

### Added
- Group Folder support: protect group folders by their internal ID (`/__groupfolders/N`) without requiring the admin to be a group member
- Admin panel: two-tab modal ("Group Folders" and "Custom Path") for adding protections; gracefully degrades to single form when the groupfolders app is not installed
- API endpoint `GET /api/groupfolders` listing all group folders with their protection status
- API endpoint `GET /api/status` now emits `/files/<mountPoint>` aliases for group folder paths so the web UI badge system can match them by visible name
- ProtectionPropertyPlugin: removes the `D` flag from `oc:permissions` for protected folders so the Nextcloud desktop client does not attempt deletion locally

### Changed
- Desktop sync recovery: on a blocked DELETE or MOVE, the server updates the node's ETag and mtime so the sync client detects the server state as "newer" and restores the folder instead of showing a permanent sync error
- Database migrations consolidated from versions 1–4 into a single clean migration (Version 2); existing installations are not affected
- App version bumped to 2.0.0
- Admin list now shows the *visible* mount point name alongside internal `/__groupfolders/N` paths, making it easier to remember which folder is which

### Fixed
- Group folder path detection in ProtectionPlugin and ProtectionPropertyPlugin: DAV nodes backed by a group folder storage are now correctly resolved to `__groupfolders/N` by traversing the storage wrapper chain via `getWrapperStorage()` / `getFolderId()`
- Lock icon badge now appears on group folders in the web file browser (path alias fix in `/api/status`)
- COPY operations on group folders are now correctly blocked

---

## [1.0.0] - 2025-11-12

### Added
- Initial release
- Protect folders from delete/move/copy operations
- Two-layer protection system (WebDAV plugin + Storage wrapper)
- OCC commands for CLI management: `folder-protection:list`, `folder-protection:protect`, `folder-protection:unprotect`, `folder-protection:check`
- Web admin interface (Vue.js)
- Distributed cache support (Redis/Memcached) with 5-minute TTL
- Rate-limited Nextcloud notifications on blocked operations
- Database schema with indexed `path`, `file_id`, and `created_at` columns
