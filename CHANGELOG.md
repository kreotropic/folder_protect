# Changelog

## [1.0.0] - 2025-11-12

### Added
- Initial release
- Protect folders from delete/move/copy operations
- Two-layer protection system (WebDAV + Storage)
- OCC commands for CLI management
- Web admin interface with Vue.js
- Performance caching system
- Protection by folder name

### Features
- `folder-protection:list` - List protected folders
- `folder-protection:protect` - Add protection
- `folder-protection:unprotect` - Remove protection  
- `folder-protection:check` - Check protection status

### Technical
- Database schema with migrations
- WebDAV plugin integration
- Storage wrapper implementation
- Vue.js admin interface
