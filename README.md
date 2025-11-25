# 🛡️ Folder Protection for Nextcloud

Protect critical folders from accidental deletion, moving, or copying - preventing server crashes from massive file operations.

## 🎯 Problem Solved

When users move 300GB+ folders, Nextcloud servers can crash or become unresponsive. This app prevents such operations on designated folders.

## ✨ Features

- 🚫 Block delete, move, and copy operations on protected folders
- 🔒 Two-layer protection (WebDAV + Storage)
- ⚡ Redis/Memcached caching for performance
- 🛠️ OCC commands for CLI management
- 🌐 Web admin interface
- 📊 Track who protected folders and why

## 📦 Installation

### Via App Store (Recommended)
1. Go to Apps in your Nextcloud
2. Search for "Folder Protection"
3. Click Install

### Manual Installation
```bash
cd /path/to/nextcloud/apps
git clone https://github.com/yourusername/nextcloud-folder-protection.git folder_protection
cd folder_protection
npm install
npm run build
php occ app:enable folder_protection
```

## 🚀 Usage

### Web Interface
Go to **Settings → Administration → Additional → Folder Protection**

### OCC Commands
```bash
# List protected folders
php occ folder-protection:list

# Protect a folder
php occ folder-protection:protect "/files/important" --reason="Critical data"

# Remove protection
php occ folder-protection:unprotect 1
```

[See full documentation](OCC_COMMANDS.md)

## 📸 Screenshots

![Admin Interface](screenshots/admin-interface.png)

## 🔧 Requirements

- Nextcloud 28+
- PHP 8.1+
- Redis or Memcached (recommended)

## 📝 License

AGPL-3.0

## 🤝 Contributing

Pull requests welcome!

## 💬 Support

- Issues: [GitHub Issues](https://github.com/yourusername/repo/issues)
- Forum: [Nextcloud Community](https://help.nextcloud.com)

## 👨‍💻 Author

Ricardo Ferreira - JOFEBAR
