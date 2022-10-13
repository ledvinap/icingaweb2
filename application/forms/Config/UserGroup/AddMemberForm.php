<?php
/* Icinga Web 2 | (c) 2015 Icinga GmbH | GPLv2+ */

namespace Icinga\Forms\Config\UserGroup;

use Exception;
use Icinga\Data\Extensible;
use Icinga\Web\Notification;
use ipl\Web\Control\SimpleSearchField;

/**
 * Form for adding one or more group members
 */
class AddMemberForm extends SimpleSearchField
{
    /**
     * The user group backend to use
     *
     * @var Extensible
     */
    protected $backend;

    /**
     * The group to add members for
     *
     * @var string
     */
    protected $groupName;

    /**
     * Set the user group backend to use
     *
     * @param   Extensible  $backend
     *
     * @return  $this
     */
    public function setBackend(Extensible $backend)
    {
        $this->backend = $backend;
        return $this;
    }

    /**
     * Set the group to add members for
     *
     * @param   string  $groupName
     *
     * @return  $this
     */
    public function setGroupName($groupName)
    {
        $this->groupName = $groupName;
        return $this;
    }

    public function onSuccess()
    {
        $q = $this->getValue($this->getSearchParameter());
        if (empty($q)) {
            Notification::info(t('Please provide at least one username'));

            return;
        }

        $userNames = array_unique(
            explode(self::TERM_SEPARATOR, urldecode($q))
        );

        $userNames = array_filter(
            array_map('trim', $userNames)
        );

        $single = null;
        foreach ($userNames as $userName) {
            try {
                $this->backend->insert(
                    'group_membership',
                    [
                        'group_name'    => $this->groupName,
                        'user_name'     => $userName
                    ]
                );
            } catch (Exception $e) {
                Notification::error(sprintf(
                    t('Failed to add "%s" as group member for "%s"'),
                    $userName,
                    $this->groupName
                ));

                return;
            }

            $single = $single === null;
        }

        if ($single) {
            Notification::success(sprintf(t('Group member "%s" added successfully'), $userName));
        } else {
            Notification::success(t('Group members added successfully'));
        }
    }
}
