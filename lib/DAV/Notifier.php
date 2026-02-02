<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Notification;

use OCP\Notification\INotifier;
use OCP\Notification\INotification;
use OCP\L10N\IFactory;

class Notifier implements INotifier {
    protected $factory;

    public function __construct(IFactory $factory) {
        $this->factory = $factory;
    }

    public function getID(): string {
        return 'folder_protection';
    }

    public function getName(): string {
        return 'Folder Protection';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'folder_protection') {
            throw new \InvalidArgumentException();
        }

        $l = $this->factory->get('folder_protection', $languageCode);
        $subject = $notification->getSubject();
        $params = $notification->getSubjectParameters();

        if ($subject === 'folder_protected') {
            $notification->setParsedSubject(
                $l->t('Action blocked: %s on "%s"', [
                    $params['action'],
                    $params['path']
                ])
            );
            $notification->setParsedMessage(
                $l->t('The folder "%s" is protected. The action "%s" was blocked by server policy.', [
                    $params['path'],
                    $params['action']
                ])
            );
        }

        return $notification;
    }
}