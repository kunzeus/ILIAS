<?php declare(strict_types=1);

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

/**
 * Class ilBuddyList
 * @author Michael Jansen <mjansen@databay.de>
 */
class ilBuddySystemNotification
{
    protected ilObjUser $sender;
    protected ilSetting $settings;
    /** @var int[] */
    protected array $recipientIds = [];

    /**
     * @param ilObjUser $user
     * @param ilSetting $settings
     */
    public function __construct(ilObjUser $user, ilSetting $settings)
    {
        $this->sender = $user;
        $this->settings = $settings;
    }

    /**
     * @return int[]
     */
    public function getRecipientIds() : array
    {
        return $this->recipientIds;
    }

    /**
     * @param int[] $recipientIds
     */
    public function setRecipientIds(array $recipientIds) : void
    {
        $this->recipientIds = array_map('intval', $recipientIds);
    }

    public function send() : void
    {
        foreach ($this->getRecipientIds() as $usr_id) {
            $user = new ilObjUser($usr_id);

            $recipientLanguage = ilLanguageFactory::_getLanguage($user->getLanguage());
            $recipientLanguage->loadLanguageModule('buddysystem');

            $notification = new ilNotificationConfig('buddysystem_request');

            if ($this->hasPublicProfile($this->sender->getId())) {
                $links[] = new ilNotificationLink(
                    new ilNotificationParameter(
                        $this->sender->getFirstname() . ', ' .
                        $this->sender->getLastname() . ' ' .
                        $this->sender->getLogin()
                    ),
                    ilLink::_getStaticLink($this->sender->getId(), 'usr')
                );
            } else {
                $links[] = new ilNotificationLink(
                    new ilNotificationParameter($recipientLanguage->txt('buddy_noti_cr_profile_not_published')),
                    '#'
                );
            }
            $links[] = new ilNotificationLink(
                new ilNotificationParameter('buddy_notification_contact_request_link_osd', [], 'buddysystem'),
                ilLink::_getStaticLink(
                    $this->sender->getId(),
                    'usr',
                    true,
                    '_contact_approved'
                )
            );
            $links[] = new ilNotificationLink(
                new ilNotificationParameter('buddy_notification_contact_request_ignore_osd', [], 'buddysystem'),
                ilLink::_getStaticLink(
                    $this->sender->getId(),
                    'usr',
                    true,
                    '_contact_ignored'
                )
            );

            $bodyParams = [
                'SALUTATION' => ilMail::getSalutation($user->getId(), $recipientLanguage),
                'REQUESTING_USER' => ilUserUtil::getNamePresentation($this->sender->getId()),
            ];
            $notification->setTitleVar('buddy_notification_contact_request', [], 'buddysystem');
            $notification->setShortDescriptionVar('buddy_notification_contact_request_short', $bodyParams, 'buddysystem');
            $notification->setLongDescriptionVar('buddy_notification_contact_request_long', $bodyParams, 'buddysystem');
            $notification->setLinks($links);
            $notification->setValidForSeconds(ilNotificationConfig::TTL_LONG);
            $notification->setVisibleForSeconds(ilNotificationConfig::DEFAULT_TTS);
            $notification->setIconPath('templates/default/images/icon_usr.svg');
            $notification->setHandlerParam('mail.sender', ANONYMOUS_USER_ID);
            $notification->notifyByUsers([$user->getId()]);
        }
    }

    protected function hasPublicProfile(int $recipientUsrId) : bool
    {
        $portfolioId = ilObjPortfolio::getDefaultPortfolio($this->sender->getId());
        if (is_numeric($portfolioId) && $portfolioId > 0) {
            $accessHandler = new ilPortfolioAccessHandler();
            return $accessHandler->checkAccessOfUser($recipientUsrId, 'read', '', $portfolioId);
        }

        return (
            $this->sender->getPref('public_profile') === 'y' ||
            $this->sender->getPref('public_profile') === 'g'
        );
    }
}
