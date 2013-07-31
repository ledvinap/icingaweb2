<?php
// {{{ICINGA_LICENSE_HEADER}}}
/**
 * This file is part of Icinga 2 Web.
 * 
 * Icinga 2 Web - Head for multiple monitoring backends.
 * Copyright (C) 2013 Icinga Development Team
 * 
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 * 
 * @copyright 2013 Icinga Development Team <info@icinga.org>
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL, version 2
 * @author    Icinga Development Team <info@icinga.org>
 */
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Protocol\Commandpipe;

use Icinga\Application\Logger as IcingaLogger;

use Icinga\Protocol\Commandpipe\Transport\Transport;
use Icinga\Protocol\Commandpipe\Transport\LocalPipe;
use Icinga\Protocol\Commandpipe\Transport\SecureShell;

/**
 * Class CommandPipe
 * @package Icinga\Protocol\Commandpipe
 */
class CommandPipe
{

    private $name = "";

    private $transport = null;

    /**
     *
     */
    const TYPE_HOST = "HOST";

    /**
     *
     */
    const TYPE_SERVICE = "SVC";

    /**
     *
     */
    const TYPE_HOSTGROUP = "HOSTGROUP";

    /**
     *
     */
    const TYPE_SERVICEGROUP = "SERVICEGROUP";

    /**
     * @param \Zend_Config $config
     */
    public function __construct(\Zend_Config $config)
    {
        $this->getTransportForConfiguration($config);
        $this->name = $config->name;
    }

    private function getTransportForConfiguration(\Zend_Config $config, $transport = null)
    {
        if ($transport != null) {
            $this->transport = $transport;
        } else if (isset($config->host)) {
            $this->transport = new SecureShell();
            $this->transport->setEndpoint($config);
        } else {
            $this->transport = new LocalPipe();
            $this->transport->setEndpoint($config);
        }
    }

    /**
     * @param $command
     * @throws \RuntimeException
     */
    public function send($command)
    {
        $this->transport->send($command);
    }

    /**
     * @param $objects
     * @param IComment $acknowledgementOrComment
     */
    public function acknowledge($objects, IComment $acknowledgementOrComment)
    {
        if (is_a($acknowledgementOrComment, 'Icinga\Protocol\Commandpipe\Comment')) {
            $acknowledgementOrComment = new Acknowledgement($acknowledgementOrComment);
        }

        foreach ($objects as $object) {
            if (isset($object->service_description)) {
                $format = $acknowledgementOrComment->getFormatString(self::TYPE_SERVICE);
                $this->send(sprintf($format, $object->host_name, $object->service_description));
            } else {
                $format = $acknowledgementOrComment->getFormatString(self::TYPE_HOST);
                $this->send(sprintf($format, $object->host_name));
            }
        }
    }

    /**
     * @param $objects
     */
    public function removeAcknowledge($objects)
    {
        foreach ($objects as $object) {
            if (isset($object->service_description)) {
                $this->send("REMOVE_SVC_ACKNOWLEDGEMENT;$object->host_name;$object->service_description");
            } else {
                $this->send("REMOVE_HOST_ACKNOWLEDGEMENT;$object->host_name");
            }
        }
    }

    /**
     * @param $objects
     * @param $state
     * @param $output
     */
    public function submitCheckResult($objects, $state, $output)
    {
        foreach ($objects as $object) {
            if (isset($object->service_description)) {
                $this->send("PROCESS_SVC_CHECK_RESULT;$object->host_name;$object->service_description;$state;$output");
            } else {
                $this->send("PROCESS_HOST_CHECK_RESULT;$object->host_name;$state;$output");
            }
        }
    }

    /**
     * @param $objects
     * @param bool $time
     * @param bool $withChilds
     */
    public function scheduleForcedCheck($objects, $time = false, $withChilds = false)
    {
        if (!$time) {
            $time = time();
        }
        $base = "SCHEDULE_FORCED_";
        foreach ($objects as $object) {
            if (isset($object->service_description)) {
                $this->send($base . "SVC_CHECK;$object->host_name;$object->service_description;$time");
            } else {
                $this->send($base . 'HOST_' . ($withChilds ? 'SVC_CHECKS' : 'CHECK') . ";$object->host_name;$time");
            }
        }
    }

    /**
     * @param $objects
     * @param bool $time
     * @param bool $withChilds
     */
    public function scheduleCheck($objects, $time = false, $withChilds = false)
    {
        if (!$time) {
            $time = time();
        }
        $base = "SCHEDULE_";
        foreach ($objects as $object) {
            if (isset($object->service_description)) {
                $this->send($base . "SVC_CHECK;$object->host_name;$object->service_description;$time");
            } else {
                $this->send($base . 'HOST_' . ($withChilds ? 'SVC_CHECKS' : 'CHECK') . ";$object->host_name;$time");
            }
        }
    }

    /**
     * @param array $objects
     * @param Comment $comment
     */
    public function addComment(array $objects, Comment $comment)
    {
        foreach ($objects as $object) {
            if (isset($object->service_description)) {
                $format = $comment->getFormatString(self::TYPE_SERVICE);
                $this->send(sprintf($format, $object->host_name, $object->service_description));
            } else {
                $format = $comment->getFormatString(self::TYPE_HOST);
                $this->send(sprintf($format, $object->host_name));
            }
        }

    }

    /**
     * @param $objectsOrComments
     */
    public function removeComment($objectsOrComments)
    {
        foreach ($objectsOrComments as $object) {
            if (isset($object->comment_id)) {
                if (isset($object->service_description)) {
                    $type = "SERVICE_COMMENT";
                } else {
                    $type = "HOST_COMMENT";
                }
                $this->send("DEL_{$type};" . intval($object->comment_id));
            } else {
                if (isset($object->service_description)) {
                    $type = "SERVICE_COMMENT";
                } else {
                    $type = "HOST_COMMENT";
                }
                $cmd = "DEL_ALL_{$type}S;" . $object->host_name;
                if ($type == "SERVICE_COMMENT") {
                    $cmd .= ";" . $object->service_description;
                }
                $this->send($cmd);
            }
        }
    }

    /**
     *
     */
    public function enableGlobalNotifications()
    {
        $this->send("ENABLE_NOTIFICATIONS");
    }

    /**
     *
     */
    public function disableGlobalNotifications()
    {
        $this->send("DISABLE_NOTIFICATIONS");
    }

    /**
     * @param $object
     * @return string
     */
    private function getObjectType($object)
    {
        //@TODO: This must be refactored once more commands are supported
        if (isset($object->service_description)) {
            return self::TYPE_SERVICE;
        }
        return self::TYPE_HOST;
    }

    /**
     * @param $objects
     * @param Downtime $downtime
     */
    public function scheduleDowntime($objects, Downtime $downtime)
    {
        foreach ($objects as $object) {
            $type = $this->getObjectType($object);
            if ($type == self::TYPE_SERVICE) {
                $this->send(
                    sprintf($downtime->getFormatString($type), $object->host_name, $object->service_description)
                );
            } else {
                $this->send(sprintf($downtime->getFormatString($type), $object->host_name));
            }
        }
    }

    /**
     * @param $objects
     * @param int $starttime
     */
    public function removeDowntime($objects, $starttime = 0)
    {
        foreach ($objects as $object) {
            $type = $this->getObjectType($object);
            if (isset($object->downtime_id)) {
                $this->send("DEL_" . $type . "_DOWNTIME;" . $object->downtime_id);
                continue;
            }
            $cmd = "DEL_DOWNTIME_BY_HOST_NAME;" . $object->host_name;
            if ($type == self::TYPE_SERVICE) {
                $cmd .= ";" . $object->service_description;
            }
            if ($starttime != 0) {
                $cmd .= ";" . $starttime;
            }
            $this->send($cmd);
        }
    }

    /**
     *
     */
    public function restartIcinga()
    {
        $this->send("RESTART_PROCESS");
    }

    /**
     * @param $objects
     * @param PropertyModifier $flags
     */
    public function setMonitoringProperties($objects, PropertyModifier $flags)
    {
        foreach ($objects as $object) {
            $type = $this->getObjectType($object);
            $formatArray = $flags->getFormatString($type);
            foreach ($formatArray as $format) {
                $format .= ";"
                    . $object->host_name
                    . ($type == self::TYPE_SERVICE ? ";" . $object->service_description : "");
                $this->send($format);
            }
        }
    }

    /**
     * @param $objects
     */
    public function enableActiveChecks($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::ACTIVE => PropertyModifier::STATE_ENABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function disableActiveChecks($objects)
    {
        $this->modifyMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::ACTIVE => PropertyModifier::STATE_DISABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function enablePassiveChecks($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::PASSIVE => PropertyModifier::STATE_ENABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function disablePassiveChecks($objects)
    {
        $this->modifyMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::PASSIVE => PropertyModifier::STATE_DISABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function enableFlappingDetection($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::FLAPPING => PropertyModifier::STATE_ENABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function disableFlappingDetection($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::FLAPPING => PropertyModifier::STATE_DISABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function enableNotifications($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::NOTIFICATIONS => PropertyModifier::STATE_ENABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function disableNotifications($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::NOTIFICATIONS => PropertyModifier::STATE_DISABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function enableFreshnessChecks($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::FRESHNESS => PropertyModifier::STATE_ENABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function disableFreshnessChecks($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::FRESHNESS => PropertyModifier::STATE_DISABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function enableEventHandler($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::EVENTHANDLER => PropertyModifier::STATE_ENABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function disableEventHandler($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::EVENTHANDLER => PropertyModifier::STATE_DISABLE
                )
            )
        );
    }

    /**
     * @param $objects
     */
    public function enablePerfdata($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::PERFDATA => PropertyModifier::STATE_ENABLE
                )
            )
        );
    }

    public function disablePerfdata($objects)
    {
        $this->setMonitoringProperties(
            $objects,
            new PropertyModifier(
                array(
                    PropertyModifier::PERFDATA => PropertyModifier::STATE_DISABLE
                )
            )
        );
    }

    /**
     * Return the transport handler that handles actual sending of commands
     *
     * @return Transport
     */
    public function getTransport()
    {
        return $this->transport;
    }
}
