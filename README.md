# Folder Protection for Nextcloud

Protect critical folders from accidental deletion, moving, or copying - preventing server crashes from massive file operations.

## Problem Solved

When users move 300GB+ folders, Nextcloud servers can crash or become unresponsive. This app prevents such operations on designated folders.

## Features

- Block delete, move, and copy operations on protected folders
- Two-layer protection (WebDAV + Storage layer)
- Distributed cache support (Redis/Memcached) for performance
- OCC commands for CLI management
- Web admin interface with Group Folder support
- Track who protected folders and why
- Desktop client aware: removes delete permission from `oc:permissions` so sync clients do not attempt deletion

## Installation

### Via App Store (Recommended)
1. Go to **Apps** in your Nextcloud
2. Search for "Folder Protection"
3. Click **Install**

### Manual Installation
```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/kreotropic/folder_protect.git folder_protection
cd folder_protection
npm install
npm run build
php occ app:enable folder_protection
```

## Usage

### Web Interface
Go to **Settings → Administration → Folder Protection**

### OCC Commands
```bash
# List all protected folders
php occ folder-protection:list

# Protect a folder (path as stored in DB, e.g. /files/ncadmin/important or /__groupfolders/1)
php occ folder-protection:protect "/files/ncadmin/important" --reason="Critical data"

# Remove protection by ID (use list to find the ID)
php occ folder-protection:unprotect 1

# Check if a path is protected
php occ folder-protection:check "/files/ncadmin/important"
```

### Group Folders
If the [Group Folders](https://github.com/nextcloud/groupfolders) app is installed, the admin panel shows a **Group Folders** tab where you can protect any group folder without being a member of the group.

## Requirements

- Nextcloud 28 or later
- PHP 8.1 or later
- Redis or Memcached recommended (app works without it, using in-process cache)

## License

AGPL-3.0

## Contributing

Pull requests welcome! Please open an issue first to discuss significant changes.

## Support

- Issues: [GitHub Issues](https://github.com/kreotropic/folder_protect/issues)
- Forum: [Nextcloud Community](https://help.nextcloud.com)

## Author

Ricardo Ferreira
