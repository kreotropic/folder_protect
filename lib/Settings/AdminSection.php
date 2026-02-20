<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {

    private IL10N $l;
    private IURLGenerator $url;

    public function __construct(IL10N $l, IURLGenerator $url) {
        $this->l = $l;
        $this->url = $url;
    }

    public function getID(): string {
        return 'folder_protection';
    }

    public function getName(): string {
        return $this->l->t('Folder Protection');
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        return $this->url->imagePath('files', 'folder.svg');
    }
}
